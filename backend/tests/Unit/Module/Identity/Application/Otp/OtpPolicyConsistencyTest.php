<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Identity\Application\Otp;

use App\Module\Identity\Application\Otp\VerifyOtpService;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * §7.1「OTP 参数」表 ↔ `config/packages/ncards_otp.yaml`，
 * 外加一条**跨文件**的一致性：`resend_after_seconds` 与
 * `rate_limiter.yaml` 里 `otp_request_email` 的 1/min 窗口必须是同一个数。
 *
 * ============================================================================
 * 为什么这条跨文件断言值得单独存在
 * ============================================================================
 * 那个 60 有两个面：
 *
 *   - **告诉客户端**：它直接进 202 的响应体，Android 照它做重发倒计时；
 *   - **强制执行**：真正拦住第二次请求的是限流器的 `{limit: 1, window: 60}`。
 *
 * 两边不一致的症状极难查：客户端按 45 秒重试却吃到 429，服务端日志里那是一次
 * 完全正常的限流，**没有任何既有测试会红**（响应体合契约、限流合规格，
 * 只是两者对不上）。用户看到的是「明明说好 45 秒的，怎么还不让我重发」。
 *
 * 沿用 `RateLimitPolicyCoverageTest` 立下的模式：把规格抄成常量，与配置比对。
 */
#[CoversNothing]
final class OtpPolicyConsistencyTest extends TestCase
{
    /**
     * §7.1「OTP 参数（MUST）」那张表的逐行抄写。
     *
     * @var array<string, int>
     */
    private const SPEC = [
        // 码长 6 位数字
        'ncards.otp.code_digits' => 6,
        // 有效期 10 分钟
        'ncards.otp.ttl_seconds' => 600,
        // 最大尝试 5 次（VerifyOtpService 消费，见下方那条断言）
        'ncards.otp.max_attempts' => 5,
        // 重发间隔 60 秒
        'ncards.otp.resend_after_seconds' => 60,
    ];

    /**
     * §7.5 的 `POST /auth/otp/request | email_hash | 1/min`。
     */
    private const RESEND_POLICY = 'otp_request_email';

    /**
     * §7.1 那张表之外的两个耗时预算 —— 它们是防枚举的填充参数，不是协议常量。
     *
     * @var list<string>
     */
    private const TIMING_BUDGETS = [
        'ncards.otp.request_budget_ms',
        'ncards.otp.verify_budget_ms',
    ];

    public function testEveryOtpParameterMatchesTheSpec(): void
    {
        self::assertSame(self::SPEC, array_intersect_key(self::otpParameters(), self::SPEC));
    }

    /**
     * 没有多余的参数：加一个而不写进 SPEC 的人会在这里被拦下，
     * 被迫说明它对应 §7.1 的哪一行（或者为什么它不属于这个文件）。
     */
    public function testTheConfigCarriesNothingBeyondTheSpecAndTheTimingBudget(): void
    {
        self::assertSame(
            [...array_keys(self::SPEC), ...self::TIMING_BUDGETS],
            array_keys(self::otpParameters()),
        );
    }

    /**
     * ⚠️ 这条就是本文件存在的理由。见类注释。
     */
    public function testTheAdvertisedResendIntervalEqualsTheEnforcedOneMinuteWindow(): void
    {
        $windows = self::rateLimitWindows(self::RESEND_POLICY);

        // 1/min 那个窗口 —— 另外两个（5/h、10/day）与重发间隔无关。
        $perMinute = array_values(array_filter($windows, static fn (array $w): bool => 1 === $w['limit']));

        self::assertCount(1, $perMinute, self::RESEND_POLICY.' 应该恰好有一个 limit=1 的窗口');
        self::assertSame(
            self::otpParameters()['ncards.otp.resend_after_seconds'],
            $perMinute[0]['window'],
            'ncards_otp.yaml 的 resend_after_seconds 与 rate_limiter.yaml 的 1/min 窗口必须是同一个数',
        );
    }

    /**
     * 耗时预算不在 §7.1 里（那张表没有这一项），但它必须是个正数 ——
     * 0 或负数会让 MonotonicTimeBudget 永远走「越界」分支，
     * 也就是**填充静默失效**，而没有任何功能测试会红。
     */
    #[DataProvider('timingBudgets')]
    public function testEveryTimingBudgetIsPositive(string $parameter): void
    {
        self::assertGreaterThan(0, self::otpParameters()[$parameter]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function timingBudgets(): iterable
    {
        foreach (self::TIMING_BUDGETS as $parameter) {
            yield $parameter => [$parameter];
        }
    }

    /**
     * `max_attempts` 从 T-103 起就配着、却一直没有消费者。T-104 接上了它 ——
     * 这条断言钉住那次接线。
     *
     * ⚠️ 它是 §7.5「`POST /auth/otp/verify` | challenge_id | 5 次总计」的
     * **唯一**强制点：那一条刻意不在 rate_limiter.yaml 里（它是生命周期计数，
     * 不是滑动窗口，且 §8.2 的 ROPA 只给限流计数 24 小时保留期）。
     * 没有这条断言，谁把 services.yaml 里那一行删掉都不会有测试红，
     * 而症状是「验证码可以无限次猜」。
     */
    public function testTheAttemptCeilingIsWiredIntoTheVerifyService(): void
    {
        $path = __DIR__.'/../../../../../../config/services.yaml';
        self::assertFileExists($path);

        /** @var array{services?: array<string, array{arguments?: array<string, string>}>} $parsed */
        $parsed = Yaml::parseFile($path);

        $arguments = $parsed['services'][VerifyOtpService::class]['arguments'] ?? [];

        self::assertSame('%ncards.otp.max_attempts%', $arguments['$maxAttempts'] ?? null);
    }

    /**
     * @return array<string, int>
     */
    private static function otpParameters(): array
    {
        $path = __DIR__.'/../../../../../../config/packages/ncards_otp.yaml';
        self::assertFileExists($path);

        /** @var array{parameters?: array<string, int>} $parsed */
        $parsed = Yaml::parseFile($path);

        return $parsed['parameters'] ?? [];
    }

    /**
     * @return list<array{limit: int, window: int}>
     */
    private static function rateLimitWindows(string $policy): array
    {
        $path = __DIR__.'/../../../../../../config/packages/rate_limiter.yaml';
        self::assertFileExists($path);

        /** @var array{parameters?: array{'ncards.rate_limits'?: array<string, array{windows?: list<array{limit: int, window: int}>}>}} $parsed */
        $parsed = Yaml::parseFile($path);

        $policies = $parsed['parameters']['ncards.rate_limits'] ?? [];
        self::assertArrayHasKey($policy, $policies);

        return $policies[$policy]['windows'] ?? [];
    }
}
