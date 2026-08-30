<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Domain\Error;

use App\Shared\Domain\Error\ErrorCode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * §6.1 错误码表的守门测试。
 *
 * ============================================================================
 * 三重互锁：新增一个 case 不写测试是**不可能**的
 * ============================================================================
 * 1. `ErrorCode::httpStatus()` / `title()` 是没有 `default` 分支的 `match`。
 *    新 case 忘了补映射 → 下面任何一个 data provider 跑到它就 `\UnhandledMatchError`。
 * 2. {@see GOLDEN} 是一份手写的对照表。它抓的是**静默改错** ——
 *    有人把 `revision_conflict` 从 409 改成 400，match 表照样能跑，只有黄金表会红。
 * 3. {@see testGoldenTableCoversEveryCase} 断言两者条目数相等。
 *    加了 case 不加黄金条目 → 计数不符。
 *
 * §13.6 的「禁止改变错误 `code` 的含义」从此由 CI 强制，而不是靠 review 时有人记得。
 * Android 侧（T-010）的 `ApiError` 是照这张表生成的，改这里等于改客户端行为。
 */
#[CoversClass(ErrorCode::class)]
final class ErrorCodeTest extends TestCase
{
    /**
     * code → [HTTP 状态码, title]。**手写**，不要从 enum 派生 —— 从被测对象派生的
     * 期望值等于没有期望值。
     *
     * @var array<string, array{int, string}>
     */
    private const GOLDEN = [
        'validation_failed' => [400, 'Validation failed'],
        'malformed_request' => [400, 'Malformed request'],
        'token_expired' => [401, 'Access token expired'],
        'token_invalid' => [401, 'Token invalid'],
        'insufficient_role' => [403, 'Insufficient role'],
        'not_a_member' => [403, 'Not a member'],
        'username_required' => [403, 'Username required'],
        'not_friends' => [403, 'Not friends'],
        'not_found' => [404, 'Not found'],
        'method_not_allowed' => [405, 'Method not allowed'],
        'revision_conflict' => [409, 'Revision conflict'],
        'full_resync_required' => [409, 'Full resync required'],
        'already_exists' => [409, 'Already exists'],
        'username_taken' => [409, 'Username taken'],
        'username_immutable' => [409, 'Username immutable'],
        'id_conflict' => [409, 'Id conflict'],
        'idempotency_in_progress' => [409, 'Idempotency key in progress'],
        'payload_too_large' => [413, 'Payload too large'],
        'unsupported_media_type' => [415, 'Unsupported media type'],
        'username_invalid' => [422, 'Username invalid'],
        'limit_exceeded' => [422, 'Limit exceeded'],
        'idempotency_key_reused' => [422, 'Idempotency key reused'],
        'client_too_old' => [426, 'Client too old'],
        'rate_limited' => [429, 'Rate limited'],
        'internal_error' => [500, 'Internal error'],
        'service_unavailable' => [503, 'Service unavailable'],
    ];

    /**
     * @return iterable<string, array{ErrorCode}>
     */
    public static function everyErrorCode(): iterable
    {
        foreach (ErrorCode::cases() as $case) {
            yield $case->value => [$case];
        }
    }

    /**
     * 黄金表与 enum 必须**逐项**对应，两个方向都要查。
     *
     * 刻意报出具体缺了哪些 code，而不是只报一个数字对不上 —— 后者在
     * 「加了一个、删了一个」时还会误判成通过。
     */
    public function testGoldenTableCoversEveryCaseAndNothingMore(): void
    {
        $cases = array_map(static fn (ErrorCode $c): string => $c->value, ErrorCode::cases());
        $golden = array_keys(self::GOLDEN);

        self::assertSame(
            [],
            array_values(array_diff($cases, $golden)),
            '这些 ErrorCode 没有 GOLDEN 条目 —— 它们的状态码与 title 无人验证',
        );
        self::assertSame(
            [],
            array_values(array_diff($golden, $cases)),
            '这些 GOLDEN 条目对应的 ErrorCode 已不存在 —— 删 code 时忘了删对照',
        );
    }

    #[DataProvider('everyErrorCode')]
    public function testMatchesTheGoldenTable(ErrorCode $code): void
    {
        self::assertArrayHasKey($code->value, self::GOLDEN, $code->value.' 缺少 GOLDEN 条目');

        [$status, $title] = self::GOLDEN[$code->value];

        self::assertSame($status, $code->httpStatus(), $code->value.' 的 HTTP 状态码变了');
        self::assertSame($title, $code->title(), $code->value.' 的 title 变了');
    }

    /**
     * §6.1 明确：`code` 是机器可读的稳定标识，客户端只对它分支。
     * snake_case 是全表的既有形态，混进一个别的写法会让客户端的映射表出错。
     */
    #[DataProvider('everyErrorCode')]
    public function testValueIsLowerSnakeCase(ErrorCode $code): void
    {
        self::assertMatchesRegularExpression('/^[a-z]+(_[a-z]+)*$/', $code->value);
    }

    #[DataProvider('everyErrorCode')]
    public function testSlugReplacesUnderscoresWithHyphens(ErrorCode $code): void
    {
        self::assertSame(str_replace('_', '-', $code->value), $code->slug());
        self::assertStringNotContainsString('_', $code->slug());
    }

    /**
     * §6.1 的例子就是这个形状：`https://api.n-cards.de/problems/revision-conflict`。
     */
    public function testTypeUriMatchesTheSpecExample(): void
    {
        self::assertSame(
            'https://api.n-cards.de/problems/revision-conflict',
            ErrorCode::RevisionConflict->typeUri('https://api.n-cards.de/problems'),
        );
    }

    public function testTypeUriToleratesATrailingSlashInTheBase(): void
    {
        self::assertSame(
            'https://api.n-cards.de/problems/not-found',
            ErrorCode::NotFound->typeUri('https://api.n-cards.de/problems/'),
        );
    }

    #[DataProvider('everyErrorCode')]
    public function testStatusIsAValidClientOrServerErrorCode(ErrorCode $code): void
    {
        self::assertGreaterThanOrEqual(400, $code->httpStatus());
        self::assertLessThan(600, $code->httpStatus());
    }

    /**
     * `title` 是英文开发者文案。§6.1：服务端绝不返回可展示的德语文案。
     * 德语的 ä/ö/ü/ß 都是非 ASCII —— 这条断言就是那条规则的机械化形式。
     */
    #[DataProvider('everyErrorCode')]
    public function testTitleIsNonEmptyPrintableAscii(ErrorCode $code): void
    {
        self::assertNotSame('', $code->title());
        self::assertMatchesRegularExpression('/^[\x20-\x7E]+$/', $code->title(), $code->value.' 的 title 含非 ASCII 字符');
    }

    public function testTitlesAreUnique(): void
    {
        $titles = array_map(static fn (ErrorCode $c): string => $c->title(), ErrorCode::cases());
        $duplicates = array_keys(array_filter(
            array_count_values($titles),
            static fn (int $occurrences): bool => $occurrences > 1,
        ));

        self::assertSame(
            [],
            $duplicates,
            '这些 title 被多个 code 共用，会让客户端与日志读起来有歧义',
        );
    }

    #[DataProvider('everyErrorCode')]
    public function testIsClientErrorTracksTheStatusCode(ErrorCode $code): void
    {
        self::assertSame($code->httpStatus() < 500, $code->isClientError());
    }

    /**
     * ⚠️ 这条测试守的是 §14.4 的告警质量。
     *
     * 若 `revision_conflict` 这类**正常业务分支**被记成 error/critical，
     * 「API 5xx 率高 > 1% 持续 5min」的告警会被日常流量打满，然后没人再看它。
     */
    #[DataProvider('everyErrorCode')]
    public function testOnlyGenuineServerFaultsLogAtErrorLevel(ErrorCode $code): void
    {
        $level = $code->logLevel();

        self::assertContains($level, ['info', 'warning', 'error']);

        if (ErrorCode::InternalError === $code) {
            self::assertSame('error', $level, '未预期的服务端故障必须记 error');

            return;
        }

        self::assertNotSame('error', $level, $code->value.' 是业务分支或刻意状态，不该记 error');
    }

    public function testDeliberateStatesLogAtWarning(): void
    {
        self::assertSame('warning', ErrorCode::RateLimited->logLevel());
        self::assertSame('warning', ErrorCode::ServiceUnavailable->logLevel());
        self::assertSame('info', ErrorCode::RevisionConflict->logLevel());
        self::assertSame('info', ErrorCode::ValidationFailed->logLevel());
    }
}
