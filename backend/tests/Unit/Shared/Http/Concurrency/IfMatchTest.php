<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Http\Concurrency;

use App\Shared\Domain\Error\DomainException;
use App\Shared\Domain\Error\ErrorCode;
use App\Shared\Domain\Error\FieldErrorCode;
use App\Shared\Http\Concurrency\IfMatch;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

#[CoversClass(IfMatch::class)]
final class IfMatchTest extends TestCase
{
    public function testItReadsTheContractExample(): void
    {
        // 契约的 IfMatch 参数 example 逐字就是 `"7"`。
        self::assertSame(7, IfMatch::revision(self::request('"7"')));
    }

    public function testItToleratesSurroundingWhitespace(): void
    {
        self::assertSame(7, IfMatch::revision(self::request(' "7" ')));
    }

    public function testItAcceptsALargeRevision(): void
    {
        // BIGINT 的上限是 19 位十进制，PHP_INT_MAX 也是 —— 不会溢出成 float。
        self::assertSame(9223372036854775807, IfMatch::revision(self::request('"9223372036854775807"')));
    }

    /**
     * @return iterable<string, array{string|null, FieldErrorCode}>
     */
    public static function rejected(): iterable
    {
        yield '缺失' => [null, FieldErrorCode::Required];
        yield '空串' => ['', FieldErrorCode::Required];
        yield '只有空白' => ['   ', FieldErrorCode::Required];

        yield '没有引号' => ['7', FieldErrorCode::InvalidFormat];
        yield '只有前引号' => ['"7', FieldErrorCode::InvalidFormat];
        yield '不是数字' => ['"abc"', FieldErrorCode::InvalidFormat];
        yield '负数' => ['"-1"', FieldErrorCode::InvalidFormat];
        yield '小数' => ['"7.0"', FieldErrorCode::InvalidFormat];

        // ⚠️ `*` 的语义是「我不在乎版本，只要它还在就写」—— 那恰好是
        // 乐观锁要挡住的那件事。默默接受它等于开一条绕过冲突检测的后门。
        yield '星号' => ['*', FieldErrorCode::InvalidFormat];
        // 弱验证器的定义是「语义等价但字节可能不同」，而 revision 是精确计数。
        yield '弱验证器' => ['W/"7"', FieldErrorCode::InvalidFormat];
        // 多值列表的语义是「其中任意一个匹配即可」，对单调递增的 revision 没有用途。
        yield '多值列表' => ['"7", "8"', FieldErrorCode::InvalidFormat];
    }

    #[DataProvider('rejected')]
    public function testItRejects(?string $header, FieldErrorCode $expected): void
    {
        try {
            IfMatch::revision(self::request($header));
            self::fail('期待 validation_failed。');
        } catch (DomainException $e) {
            // ⚠️ 缺一个必填参数在这个 API 里一律是 400 validation_failed，
            // 不是 428 Precondition Required —— 理由见 IfMatch 的类注释。
            self::assertSame(ErrorCode::ValidationFailed, $e->errorCode());
            self::assertCount(1, $e->fieldErrors());
            self::assertSame('If-Match', $e->fieldErrors()[0]->field);
            self::assertSame($expected, $e->fieldErrors()[0]->code);
        }
    }

    /**
     * detail 里**不回显**客户端发来的字符串 —— 它会进日志与 Sentry。
     * 口径同 `AbstractApiController::decodeBody()`。
     */
    public function testTheDetailNeverEchoesTheHeader(): void
    {
        try {
            IfMatch::revision(self::request('"<script>alert(1)</script>"'));
            self::fail('期待 validation_failed。');
        } catch (DomainException $e) {
            self::assertStringNotContainsString('script', $e->detail());
            self::assertStringNotContainsString('script', $e->fieldErrors()[0]->message);
        }
    }

    private static function request(?string $header): Request
    {
        $request = new Request();

        if (null !== $header) {
            $request->headers->set('If-Match', $header);
        }

        return $request;
    }
}
