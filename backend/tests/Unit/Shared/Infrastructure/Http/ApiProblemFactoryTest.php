<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Http;

use App\Shared\Domain\Error\ErrorCode;
use App\Shared\Domain\Error\FieldError;
use App\Shared\Domain\Error\FieldErrorCode;
use App\Shared\Infrastructure\Http\ApiProblemFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * T-004 验收标准的落点：**单测覆盖每个错误码的响应形状**。
 *
 * data provider 直接走 `ErrorCode::cases()`，所以新增一个 code 会自动被拉进来跑 ——
 * 忘了给它补 httpStatus()/title() 映射的话，无 default 分支的 match 会直接
 * `\UnhandledMatchError`，而 phpunit.xml.dist 开了 failOnWarning/failOnRisky，
 * 这是致命错误而不是警告。「加了 code 却没测」在结构上做不到。
 */
#[CoversClass(ApiProblemFactory::class)]
final class ApiProblemFactoryTest extends TestCase
{
    private const TYPE_BASE = 'https://api.n-cards.de/problems';
    private const INSTANCE = '/v1/cards/01941f29-7c00-70ab-8000-000000000000';
    private const REQUEST_ID = '01941f29-7c00-70ab-8000-0000000000ff';

    /**
     * §6.1 的成员顺序，逐字。
     *
     * @var list<string>
     */
    private const BASE_MEMBERS = ['type', 'title', 'status', 'code', 'detail', 'instance', 'request_id'];

    /**
     * @return iterable<string, array{ErrorCode}>
     */
    public static function everyErrorCode(): iterable
    {
        foreach (ErrorCode::cases() as $case) {
            yield $case->value => [$case];
        }
    }

    private static function factory(bool $debug = false): ApiProblemFactory
    {
        return new ApiProblemFactory(self::TYPE_BASE, $debug);
    }

    // ========================================================================
    // 每个错误码 × 响应形状
    // ========================================================================

    #[DataProvider('everyErrorCode')]
    public function testEmitsExactlyTheBaseMembersInSpecOrder(ErrorCode $code): void
    {
        $problem = self::factory()->build($code, null, self::INSTANCE, self::REQUEST_ID);

        self::assertSame(
            self::BASE_MEMBERS,
            array_keys($problem),
            $code->value.' 的成员集合或顺序与 §6.1 不符',
        );
    }

    #[DataProvider('everyErrorCode')]
    public function testStatusAndCodeAgreeWithTheEnum(ErrorCode $code): void
    {
        $problem = self::factory()->build($code, null, self::INSTANCE, self::REQUEST_ID);

        self::assertSame($code->httpStatus(), $problem['status']);
        self::assertSame($code->value, $problem['code']);
    }

    #[DataProvider('everyErrorCode')]
    public function testTypeUriDerivesFromTheCode(ErrorCode $code): void
    {
        $problem = self::factory()->build($code, null, self::INSTANCE, self::REQUEST_ID);

        self::assertSame(self::TYPE_BASE.'/'.$code->slug(), $problem['type']);
        self::assertStringNotContainsString('_', (string) $problem['type'], 'type URI 用连字符，不用下划线');
    }

    #[DataProvider('everyErrorCode')]
    public function testTitleAndDetailAreNonEmpty(ErrorCode $code): void
    {
        $problem = self::factory()->build($code, null, self::INSTANCE, self::REQUEST_ID);

        self::assertSame($code->title(), $problem['title']);
        self::assertNotSame('', $problem['detail'], '留空 detail 时必须回落到通用文案');
    }

    /**
     * §6.1：「`detail` 为英文开发者文案；面向用户的文案一律由客户端本地化生成
     * （服务端不返回可展示的德语文案）」。
     *
     * 德语的 ä/ö/ü/ß 都是非 ASCII —— 这条断言就是那句话的机械化形式。
     * 哪天有人往 detail 里写了德语，CI 立刻红。
     */
    #[DataProvider('everyErrorCode')]
    public function testDetailIsPrintableAsciiOnly(ErrorCode $code): void
    {
        $problem = self::factory()->build($code, null, self::INSTANCE, self::REQUEST_ID);

        self::assertMatchesRegularExpression(
            '/^[\x20-\x7E]+$/',
            (string) $problem['detail'],
            $code->value.' 的 detail 含非 ASCII 字符（德语文案泄露？）',
        );
    }

    #[DataProvider('everyErrorCode')]
    public function testOptionalMembersAreOmittedWhenEmpty(ErrorCode $code): void
    {
        $problem = self::factory()->build($code, null, self::INSTANCE, self::REQUEST_ID);

        // 发 [] / {} 会逼 T-007 的 schema 把它们标成 required，
        // 也会让 T-010 的 Kotlin 模型多两个永远为空的字段。
        self::assertArrayNotHasKey('errors', $problem);
        self::assertArrayNotHasKey('current', $problem);
        self::assertArrayNotHasKey('debug', $problem);
    }

    #[DataProvider('everyErrorCode')]
    public function testResponseCarriesTheProblemMediaTypeAndIsNotCacheable(ErrorCode $code): void
    {
        $response = self::factory()->toResponse($code, null, self::INSTANCE, self::REQUEST_ID);

        self::assertSame($code->httpStatus(), $response->getStatusCode());
        // RFC 9457 的媒体类型不带 charset —— JSON 按定义就是 UTF-8。
        self::assertSame('application/problem+json', $response->headers->get('Content-Type'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    #[DataProvider('everyErrorCode')]
    public function testResponseBodyRoundTripsThroughJson(ErrorCode $code): void
    {
        $response = self::factory()->toResponse($code, null, self::INSTANCE, self::REQUEST_ID);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame(
            self::factory()->build($code, null, self::INSTANCE, self::REQUEST_ID),
            $decoded,
        );
    }

    // ========================================================================
    // 可选成员
    // ========================================================================

    public function testErrorsAppearAfterTheBaseMembers(): void
    {
        $problem = self::factory()->build(
            ErrorCode::ValidationFailed,
            'One or more fields failed validation.',
            self::INSTANCE,
            self::REQUEST_ID,
            [new FieldError('title', FieldErrorCode::TooLong, 'Title must be at most 100 characters.')],
        );

        self::assertSame([...self::BASE_MEMBERS, 'errors'], array_keys($problem));
        self::assertSame(
            [['field' => 'title', 'code' => 'too_long', 'message' => 'Title must be at most 100 characters.']],
            $problem['errors'],
        );
    }

    public function testCurrentAppearsForConflicts(): void
    {
        $problem = self::factory()->build(
            ErrorCode::RevisionConflict,
            'The card was modified by another member.',
            self::INSTANCE,
            self::REQUEST_ID,
            [],
            ['revision' => 42, 'title' => 'Payback'],
        );

        self::assertSame([...self::BASE_MEMBERS, 'current'], array_keys($problem));
        self::assertSame(['revision' => 42, 'title' => 'Payback'], $problem['current']);
    }

    /**
     * §6.1 的例子逐字复现 —— 这是「我们实现的确实是规格里那个形状」的锚点。
     */
    public function testMatchesTheSpecExampleShape(): void
    {
        $problem = self::factory()->build(
            ErrorCode::RevisionConflict,
            'The card was modified by another member.',
            '/v1/cards/01941f29-7c00-70ab-8000-000000000000',
            '01941f29-7c00-70ab-8000-0000000000ff',
            [new FieldError('title', FieldErrorCode::TooLong, 'Title is too long.')],
            ['revision' => 7],
        );

        self::assertSame('https://api.n-cards.de/problems/revision-conflict', $problem['type']);
        self::assertSame('Revision conflict', $problem['title']);
        self::assertSame(409, $problem['status']);
        self::assertSame('revision_conflict', $problem['code']);
        self::assertSame('The card was modified by another member.', $problem['detail']);
        self::assertSame('/v1/cards/01941f29-7c00-70ab-8000-000000000000', $problem['instance']);
    }

    /**
     * type URI 里的斜杠不能被转义成 `\/` —— 那样和 §6.1 的例子对不上，
     * 客户端做字符串比较也会踩坑。
     */
    public function testSlashesAreNotEscapedInTheEncodedBody(): void
    {
        $response = self::factory()->toResponse(ErrorCode::NotFound, null, self::INSTANCE, self::REQUEST_ID);

        self::assertStringContainsString('"https://api.n-cards.de/problems/not-found"', (string) $response->getContent());
        self::assertStringNotContainsString('\\/', (string) $response->getContent());
    }

    // ========================================================================
    // debug 成员
    // ========================================================================

    public function testDebugMemberIsAbsentInProduction(): void
    {
        $problem = self::factory(debug: false)->build(
            ErrorCode::InternalError,
            null,
            self::INSTANCE,
            self::REQUEST_ID,
            [],
            [],
            new \RuntimeException('SQLSTATE[08006] connection to 10.0.0.5:5432 failed'),
        );

        self::assertArrayNotHasKey('debug', $problem);
        self::assertStringNotContainsString('10.0.0.5', json_encode($problem, \JSON_THROW_ON_ERROR));
    }

    public function testDebugMemberIsPresentInDebugMode(): void
    {
        $problem = self::factory(debug: true)->build(
            ErrorCode::InternalError,
            null,
            self::INSTANCE,
            self::REQUEST_ID,
            [],
            [],
            new \RuntimeException('boom'),
        );

        self::assertArrayHasKey('debug', $problem);
        self::assertIsArray($problem['debug']);
        self::assertSame(\RuntimeException::class, $problem['debug']['class']);
        self::assertSame('boom', $problem['debug']['message']);
        self::assertIsArray($problem['debug']['trace']);
        self::assertLessThanOrEqual(5, \count($problem['debug']['trace']), '栈帧数要有上限，否则错误体会膨胀');
    }

    public function testDebugMemberNeedsAnException(): void
    {
        $problem = self::factory(debug: true)->build(ErrorCode::NotFound, null, self::INSTANCE, self::REQUEST_ID);

        self::assertArrayNotHasKey('debug', $problem);
    }

    // ========================================================================
    // 其他
    // ========================================================================

    public function testRequestIdIsEmittedAsNullRatherThanOmitted(): void
    {
        // 形状恒定：客户端的解析不需要处理「有时有这个键、有时没有」。
        $problem = self::factory()->build(ErrorCode::NotFound, null, self::INSTANCE, null);

        self::assertArrayHasKey('request_id', $problem);
        self::assertNull($problem['request_id']);
    }

    public function testExtraHeadersArePassedThrough(): void
    {
        $response = self::factory()->toResponse(
            ErrorCode::RateLimited,
            null,
            self::INSTANCE,
            self::REQUEST_ID,
            [],
            [],
            null,
            ['Retry-After' => '30'],
        );

        self::assertSame('30', $response->headers->get('Retry-After'));
    }

    /**
     * 兜底响应不依赖任何外部状态 —— 它要在「渲染本身炸了」时还能工作。
     */
    public function testFallbackIsSelfContained(): void
    {
        $response = ApiProblemFactory::fallback(self::REQUEST_ID);

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame(500, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->headers->get('Content-Type'));
        self::assertSame('internal_error', $body['code']);
        self::assertSame(self::REQUEST_ID, $body['request_id'], '兜底也必须带 request_id，否则这条 500 无从追查');
        self::assertSame(self::BASE_MEMBERS, array_keys($body));
    }
}
