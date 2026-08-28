<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Http\Pagination;

use App\Shared\Domain\Error\DomainException;
use App\Shared\Domain\Error\ErrorCode;
use App\Shared\Domain\Error\FieldErrorCode;
use App\Shared\Http\Pagination\CursorPaginator;
use App\Shared\Http\Pagination\Page;
use App\Shared\Http\Pagination\PageRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

#[CoversClass(CursorPaginator::class)]
#[CoversClass(PageRequest::class)]
#[CoversClass(Page::class)]
final class CursorPaginatorTest extends TestCase
{
    private CursorPaginator $paginator;

    protected function setUp(): void
    {
        $this->paginator = new CursorPaginator();
    }

    /**
     * @param array<string, string> $query
     */
    private static function request(array $query = []): Request
    {
        return Request::create('/v1/cards', 'GET', $query);
    }

    // ========================================================================
    // limit
    // ========================================================================

    public function testDefaultsToFifty(): void
    {
        self::assertSame(50, $this->paginator->pageRequest(self::request())->limit);
    }

    public function testAcceptsAnExplicitLimit(): void
    {
        self::assertSame(25, $this->paginator->pageRequest(self::request(['limit' => '25']))->limit);
    }

    /**
     * 夹取而不是报错：`limit=500` 是个合理请求。而且夹取意味着将来抬高 MAX_LIMIT
     * 仍然向后兼容（§13.6 禁止收紧、允许放宽）。
     */
    public function testClampsToTheMaximum(): void
    {
        self::assertSame(200, $this->paginator->pageRequest(self::request(['limit' => '500']))->limit);
        self::assertSame(200, $this->paginator->pageRequest(self::request(['limit' => '999999']))->limit);
    }

    public function testAcceptsExactlyTheMaximum(): void
    {
        self::assertSame(200, $this->paginator->pageRequest(self::request(['limit' => '200']))->limit);
    }

    /**
     * @return iterable<string, array{string, FieldErrorCode}>
     */
    public static function invalidLimits(): iterable
    {
        yield 'not a number' => ['abc', FieldErrorCode::InvalidType];
        yield 'negative' => ['-1', FieldErrorCode::InvalidType];
        yield 'float' => ['1.5', FieldErrorCode::InvalidType];
        yield 'zero' => ['0', FieldErrorCode::OutOfRange];
    }

    #[DataProvider('invalidLimits')]
    public function testRejectsInvalidLimits(string $limit, FieldErrorCode $expected): void
    {
        try {
            $this->paginator->pageRequest(self::request(['limit' => $limit]));
            self::fail('应该抛 DomainException');
        } catch (DomainException $e) {
            self::assertSame(ErrorCode::ValidationFailed, $e->errorCode());
            self::assertSame('limit', $e->fieldErrors()[0]->field);
            self::assertSame($expected, $e->fieldErrors()[0]->code);
        }
    }

    // ========================================================================
    // offset 主动拒绝
    // ========================================================================

    /**
     * §6.1 写的是「**不使用** offset」。只是忽略的话，发 `?offset=50` 的客户端
     * 会永远收到第一页 —— 没有报错、没有日志、没有人发现。
     *
     * @return iterable<string, array{string}>
     */
    public static function forbiddenParams(): iterable
    {
        foreach (CursorPaginator::FORBIDDEN_PARAMS as $param) {
            yield $param => [$param];
        }
    }

    #[DataProvider('forbiddenParams')]
    public function testRejectsOffsetStyleParameters(string $param): void
    {
        try {
            $this->paginator->pageRequest(self::request([$param => '50']));
            self::fail($param.' 应该被拒绝');
        } catch (DomainException $e) {
            self::assertSame(ErrorCode::ValidationFailed, $e->errorCode());
            self::assertSame($param, $e->fieldErrors()[0]->field);
            self::assertSame(FieldErrorCode::UnsupportedParameter, $e->fieldErrors()[0]->code);
            self::assertStringContainsString('cursor', $e->fieldErrors()[0]->message, '要告诉客户端该用什么');
        }
    }

    /**
     * 即使值为空也要拒绝 —— `?offset=` 同样说明客户端在按 offset 思维翻页。
     */
    public function testRejectsForbiddenParameterEvenWhenEmpty(): void
    {
        $this->expectException(DomainException::class);

        $this->paginator->pageRequest(self::request(['offset' => '']));
    }

    // ========================================================================
    // 分页切分
    // ========================================================================

    public function testReportsNoMoreWhenTheExtraRowIsAbsent(): void
    {
        $pageRequest = new PageRequest(3);
        // 仓储取了 fetchLimit()=4 行，但只回来 3 行 → 没有下一页。
        $page = $this->paginator->paginate([1, 2, 3], $pageRequest, static fn (int $r): array => ['after' => $r]);

        self::assertSame([1, 2, 3], $page->items);
        self::assertFalse($page->hasMore);
        self::assertNull($page->nextCursor);
    }

    public function testTrimsTheExtraRowAndReportsMore(): void
    {
        $pageRequest = new PageRequest(3);
        $page = $this->paginator->paginate([1, 2, 3, 4], $pageRequest, static fn (int $r): array => ['after' => $r]);

        self::assertSame([1, 2, 3], $page->items, '多取的那一行不能出现在响应里');
        self::assertTrue($page->hasMore);
        self::assertNotNull($page->nextCursor);
    }

    /**
     * ⚠️ 游标必须取自**保留下来的最后一行**（3），而不是被丢掉的那行（4）。
     *
     * 取错的话下一页会从 5 开始，**静默跳过** 4 —— 这类 bug 在小数据量下
     * 根本不会被发现。
     */
    public function testNextCursorPointsAtTheLastKeptRow(): void
    {
        $pageRequest = new PageRequest(3);
        $page = $this->paginator->paginate([1, 2, 3, 4], $pageRequest, static fn (int $r): array => ['after' => $r]);

        self::assertNotNull($page->nextCursor);
        self::assertSame(['after' => 3], $page->nextCursor->payload());
    }

    public function testEmptyResultSet(): void
    {
        $page = $this->paginator->paginate([], new PageRequest(10), static fn (int $r): array => ['after' => $r]);

        self::assertSame([], $page->items);
        self::assertFalse($page->hasMore);
        self::assertNull($page->nextCursor);
    }

    public function testFetchLimitIsOneMoreThanTheLimit(): void
    {
        // 多取一行是判断 has_more 的手段，也是唯一不需要第二次查询的手段。
        self::assertSame(51, (new PageRequest(50))->fetchLimit());
    }

    // ========================================================================
    // 序列化信封
    // ========================================================================

    public function testEnvelopeShape(): void
    {
        $page = $this->paginator->paginate([1, 2], new PageRequest(2), static fn (int $r): array => ['after' => $r]);

        self::assertSame(
            ['items' => [1, 2], 'next_cursor' => null, 'has_more' => false],
            $page->jsonSerialize(),
        );
    }

    public function testEnvelopeCarriesTheEncodedCursor(): void
    {
        $page = $this->paginator->paginate([1, 2, 3], new PageRequest(2), static fn (int $r): array => ['after' => $r]);

        // 经 json 往返再断言键序：直接对 jsonSerialize() 的返回值断言的话，
        // 它声明的 array shape 会让这条检查在静态层面就恒真，等于没测。
        /** @var array<string, mixed> $envelope */
        $envelope = json_decode(json_encode($page, \JSON_THROW_ON_ERROR), true, 8, \JSON_THROW_ON_ERROR);

        self::assertSame(['items', 'next_cursor', 'has_more'], array_keys($envelope));
        self::assertTrue($envelope['has_more']);
        self::assertIsString($envelope['next_cursor']);
    }

    // ========================================================================
    // 游标往返
    // ========================================================================

    public function testDecodesACursorFromTheQueryString(): void
    {
        $page = $this->paginator->paginate([1, 2, 3], new PageRequest(2), static fn (int $r): array => ['after' => $r]);
        self::assertNotNull($page->nextCursor);

        $next = $this->paginator->pageRequest(self::request(['cursor' => (string) $page->nextCursor]));

        self::assertNotNull($next->cursor);
        self::assertSame(['after' => 2], $next->cursor->payload());
    }

    public function testRejectsATamperedCursor(): void
    {
        try {
            $this->paginator->pageRequest(self::request(['cursor' => 'not a cursor!!']));
            self::fail('应该抛 DomainException');
        } catch (DomainException $e) {
            self::assertSame('cursor', $e->fieldErrors()[0]->field);
        }
    }

    public function testNoCursorMeansFirstPage(): void
    {
        self::assertNull($this->paginator->pageRequest(self::request())->cursor);
        self::assertNull($this->paginator->pageRequest(self::request(['cursor' => '']))->cursor);
    }
}
