<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\RateLimit;

use App\Shared\Infrastructure\RateLimit\RateLimitPolicyRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(RateLimitPolicyRegistry::class)]
final class RateLimitPolicyRegistryTest extends TestCase
{
    public function testParsesAMultiWindowPolicy(): void
    {
        $registry = new RateLimitPolicyRegistry([
            'otp_request_email' => [
                'dimension' => 'email_hash',
                'windows' => [
                    ['limit' => 1, 'window' => 60],
                    ['limit' => 5, 'window' => 3600],
                    ['limit' => 10, 'window' => 86400],
                ],
            ],
        ]);

        $policy = $registry->policy('otp_request_email');

        self::assertSame('otp_request_email', $policy->name);
        self::assertSame('email_hash', $policy->dimension);
        self::assertCount(3, $policy->windows);
        self::assertSame(1, $policy->windows[0]->limit);
        self::assertSame(86400, $policy->windows[2]->windowSeconds);
    }

    /**
     * ⚠️ 省略 `on_store_failure` 就是 fail-CLOSED。这是 §7.5 与 ADR-0003 的默认，
     * 也是「新增策略时要主动声明放松，而不是主动记得收紧」的落点。
     */
    public function testOmittingOnStoreFailureMeansFailClosed(): void
    {
        $registry = new RateLimitPolicyRegistry([
            'x' => ['dimension' => 'ip', 'windows' => [['limit' => 1, 'window' => 60]]],
        ]);

        self::assertTrue($registry->policy('x')->denyOnStoreFailure);
    }

    public function testAllowIsOptedIntoExplicitly(): void
    {
        $registry = new RateLimitPolicyRegistry([
            'x' => [
                'dimension' => 'user',
                'on_store_failure' => 'allow',
                'windows' => [['limit' => 300, 'window' => 60]],
            ],
        ]);

        self::assertFalse($registry->policy('x')->denyOnStoreFailure);
    }

    /**
     * 只有 `deny` / `allow` 两个值。打错字（`open`、`true`、`fail_open`）必须炸 ——
     * 静默回落到任一方向都是错的：回落到 deny 会在故障时意外全站 503，
     * 回落到 allow 会静默关掉一条安全防线。
     */
    #[DataProvider('invalidStoreFailureValues')]
    public function testRejectsAnyOtherOnStoreFailureValue(mixed $value): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new RateLimitPolicyRegistry([
            'x' => [
                'dimension' => 'ip',
                'on_store_failure' => $value,
                'windows' => [['limit' => 1, 'window' => 60]],
            ],
        ]);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidStoreFailureValues(): iterable
    {
        yield 'typo' => ['open'];
        yield 'legacy name' => ['fail_open'];
        yield 'boolean' => [true];
        yield 'null' => [null];
    }

    // ========================================================================
    // 配置错误在**构造期**就炸，而不是等到第一个真实请求
    // ========================================================================

    public function testRejectsAPolicyWithoutWindows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new RateLimitPolicyRegistry(['x' => ['dimension' => 'ip']]);
    }

    public function testRejectsAPolicyWithoutDimension(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new RateLimitPolicyRegistry(['x' => ['windows' => [['limit' => 1, 'window' => 60]]]]);
    }

    /**
     * `window: '1 minute'`（Symfony RateLimiter 的写法）会静默地被 tonumber 变成 nil，
     * 于是 Lua 里的窗口长度成了 0。必须在解析期拒掉。
     */
    public function testRejectsNonIntegerWindowValues(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new RateLimitPolicyRegistry([
            'x' => ['dimension' => 'ip', 'windows' => [['limit' => 1, 'window' => '1 minute']]],
        ]);
    }

    public function testRejectsAZeroLimit(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new RateLimitPolicyRegistry([
            'x' => ['dimension' => 'ip', 'windows' => [['limit' => 0, 'window' => 60]]],
        ]);
    }

    // ========================================================================
    // 查找
    // ========================================================================

    /**
     * 未定义的策略名是**配置错误**，不是运行时状况 —— 抛异常而不是静默放行。
     * 静默放行意味着一个打错的策略名等于一条被悄悄关掉的限流。
     */
    public function testUnknownPolicyThrowsAndListsWhatExists(): void
    {
        $registry = new RateLimitPolicyRegistry([
            'otp_request_ip' => ['dimension' => 'ip', 'windows' => [['limit' => 20, 'window' => 3600]]],
        ]);

        self::assertFalse($registry->has('otp_request_email'));

        try {
            $registry->policy('otp_request_email');
            self::fail('未定义的策略名应该抛异常');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('otp_request_email', $e->getMessage());
            // 异常消息是运维唯一能看到的东西，要能自解释。
            self::assertStringContainsString('otp_request_ip', $e->getMessage());
            self::assertStringContainsString('rate_limiter.yaml', $e->getMessage());
        }
    }

    /**
     * `when@test` 追加的策略与生产策略共存，而不是替换掉整张表。
     */
    public function testTestOnlyPoliciesAreMergedOnTopOfTheProductionOnes(): void
    {
        $registry = new RateLimitPolicyRegistry(
            ['write_endpoints' => ['dimension' => 'user', 'windows' => [['limit' => 300, 'window' => 60]]]],
            ['_probe' => ['dimension' => 'ip', 'windows' => [['limit' => 3, 'window' => 60]]]],
        );

        self::assertSame(['write_endpoints', '_probe'], $registry->names());
    }
}
