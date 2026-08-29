<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Domain\RateLimit;

use App\Shared\Domain\RateLimit\RateLimitCheck;
use App\Shared\Domain\RateLimit\RateLimitPolicy;
use App\Shared\Domain\RateLimit\RateLimitWindow;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RateLimitPolicy::class)]
#[CoversClass(RateLimitWindow::class)]
#[CoversClass(RateLimitCheck::class)]
final class RateLimitPolicyTest extends TestCase
{
    public function testLongestWindowDrivesTheTtl(): void
    {
        // §7.5 的 OTP 三重限速。
        $policy = new RateLimitPolicy('otp_request_email', 'email_hash', [
            new RateLimitWindow(1, 60),
            new RateLimitWindow(5, 3600),
            new RateLimitWindow(10, 86400),
        ]);

        self::assertSame(86400, $policy->longestWindowSeconds());
        self::assertSame(86400, $policy->ttlSeconds());
    }

    /**
     * ⚠️ §8.2 ROPA：「限流计数保留 **24 小时**」。这是合规上限，不是调优参数。
     */
    public function testTtlIsCappedAtTheRopaRetentionPeriod(): void
    {
        $policy = new RateLimitPolicy('hypothetical', 'user', [new RateLimitWindow(10, 7 * 86400)]);

        self::assertSame(7 * 86400, $policy->longestWindowSeconds());
        self::assertSame(RateLimitPolicy::MAX_TTL_SECONDS, $policy->ttlSeconds());
        self::assertSame(86400, $policy->ttlSeconds());
    }

    /**
     * ⚠️ 默认 fail-CLOSED。省略 `on_store_failure` 就是最严格的那个选择。
     */
    public function testDefaultsToFailClosed(): void
    {
        $policy = new RateLimitPolicy('x', 'ip', [new RateLimitWindow(1, 60)]);

        self::assertTrue($policy->denyOnStoreFailure);
    }

    public function testRejectsAPolicyWithoutWindows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new RateLimitPolicy('x', 'ip', []);
    }

    /**
     * `limit: 0` 会把端点彻底关死，而症状看起来像「限流工作得太好了」。
     * 配错要在构造期炸。
     */
    public function testRejectsNonsensicalWindows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new RateLimitWindow(0, 60);
    }

    public function testRejectsSubSecondWindows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new RateLimitWindow(1, 0);
    }

    /**
     * 空主体会让所有请求挤进同一个桶 —— 既不是限流也不是放行，
     * 是一个看起来在工作的全局熔断。
     */
    public function testRejectsAnEmptySubject(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new RateLimitCheck('otp_request_ip', '');
    }

    public function testRejectsNonPositiveTokens(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new RateLimitCheck('otp_request_ip', 'ip:127.0.0.1', 0);
    }
}
