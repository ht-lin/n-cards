<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\RateLimit;

use App\Shared\Domain\RateLimit\RateLimitPolicy;
use App\Shared\Infrastructure\RateLimit\RateLimitPolicyRegistry;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * §7.5 速率表 ↔ `config/packages/rate_limiter.yaml` 的黄金对照表。
 *
 * ============================================================================
 * 为什么需要这个测试
 * ============================================================================
 * §7.5 的速率限制在 T-006 时**没有一条有对应端点** —— 第一个消费者是
 * M1 的 T-103。于是「配置写错了」在几周内没有任何东西会发现：功能测试全绿，
 * 因为根本没有功能碰它。
 *
 * 沿用 `ProblemDetailsSchemaTest` 立下的模式：把规格逐行抄成常量，与配置比对。
 * 改数字而不改规格（或反过来）→ 红，改的人被迫在 PR 里说明理由。
 *
 * ============================================================================
 * ⚠️ 「总计」两条刻意不在配置里
 * ============================================================================
 * 见 {@see NOT_RATE_LIMITED} 的注释。登记在这里是为了让
 * 「§7.5 的行数多于配置的条数」不会被当成漏配 —— 也为了下一个读 §7.5 的人
 * 不用重新推导一遍为什么。
 */
#[CoversNothing]
final class RateLimitPolicyCoverageTest extends TestCase
{
    /**
     * §7.5「速率限制」表的逐行抄写。
     *
     * 形状：策略名 => [维度, [[次数, 窗口秒], ...], fail-closed?]
     *
     * @var array<string, array{string, list<array{int, int}>, bool}>
     */
    private const SPEC = [
        // `POST /auth/otp/request` | email_hash | 1/min, 5/h, 10/day
        'otp_request_email' => ['email_hash', [[1, 60], [5, 3600], [10, 86400]], true],
        // `POST /auth/otp/request` | IP | 20/h
        'otp_request_ip' => ['ip', [[20, 3600]], true],
        // `POST /auth/otp/verify` | IP | 60/h
        'otp_verify_ip' => ['ip', [[60, 3600]], true],
        // `POST /auth/magic/consume` | IP | 60/h（T-106）
        'magic_consume_ip' => ['ip', [[60, 3600]], true],
        // `POST /auth/token/refresh` | session | 60/h
        'token_refresh' => ['session', [[60, 3600]], true],
        // `GET /v1/sync` | device | 60/min
        'sync_device' => ['device', [[60, 60]], true],
        // `GET /v1/users/lookup` | user | 30/min, 300/day
        'user_lookup_user' => ['user', [[30, 60], [300, 86400]], true],
        // `GET /v1/users/lookup` | IP | 100/h
        'user_lookup_ip' => ['ip', [[100, 3600]], true],
        // `GET /v1/config` | IP | 300/min（T-112）—— 两条 fail-open 之一，见下。
        // ⚠️ 按分钟而不像上面三条 IP 策略按小时：本端点是客户端每次冷启动都拉的那一个，
        // 而配额的约束是 CGNAT（一个运营商出口背后可能上千订户），不是攻击者。
        'config_ip' => ['ip', [[300, 60]], false],
        // 全部写接口 | user | 300/min —— 两条 fail-open 之一，见下
        'write_endpoints' => ['user', [[300, 60]], false],
    ];

    /**
     * §7.5 表里**不该**出现在本配置里的行，以及各自的去处。
     *
     * 它们不是滑动窗口而是**生命周期计数**，归持久层。另外 §8.2 的 ROPA 规定
     * 「限流计数保留 24 小时」—— 把一个跨越账号生命周期的计数放进 Redis
     * 会直接违反那条保留期。
     *
     * @var array<string, string>
     */
    private const NOT_RATE_LIMITED = [
        'POST /auth/otp/verify challenge_id 5 次总计' => 'otp_challenges.attempts 列（T-104）',
        // ✅ T-107 已落地：`users.username_attempts` 列（Version20260907170000）。
        // 顺带一提，§7.5 的那一行现在已经**移进限额表**了 —— 它返回
        // 422 limit_exceeded 而不是 429（429 会强迫编一个假的 Retry-After，
        // 而这个计数永不恢复）。所以它不在本文件里有双重理由，见 ADR-0017。
        'POST /v1/me/username user 10 次总计' => 'users.username_attempts 列（T-107）',
        '全局邮件外发总量' => '§14.4 的阈值告警 + 熔断，不是 per-subject 限流',
    ];

    public function testEverySpecRowIsConfigured(): void
    {
        $registry = self::registry();

        foreach (self::SPEC as $name => [$dimension, $windows, $failClosed]) {
            self::assertTrue($registry->has($name), \sprintf('§7.5 的 %s 在 rate_limiter.yaml 里没有配置', $name));

            $policy = $registry->policy($name);

            self::assertSame($dimension, $policy->dimension, $name.' 的维度与 §7.5 不符');
            self::assertSame(
                $windows,
                array_map(
                    static fn ($w): array => [$w->limit, $w->windowSeconds],
                    $policy->windows,
                ),
                $name.' 的窗口与 §7.5 不符',
            );
            self::assertSame($failClosed, $policy->denyOnStoreFailure, $name.' 的降级方向与 ADR-0003 不符');
        }
    }

    /**
     * 反方向：配置里不该有 §7.5 之外的策略偷偷混进生产。
     *
     * `when@test` 的探针策略走的是另一个参数（`ncards.rate_limits.test_only`），
     * 所以这里读到的应该只有生产表。
     */
    public function testConfigurationHasNoUndocumentedPolicies(): void
    {
        self::assertSame(
            array_keys(self::SPEC),
            self::registry()->names(),
            "rate_limiter.yaml 里有 §7.5 没写的策略（或顺序对不上）。\n"
            .'新增限流必须同时更新 §7.5 的表与本测试的 SPEC 常量。',
        );
    }

    /**
     * ⚠️⚠️ 全仓库只允许**这两条** `on_store_failure: allow`，白名单是**封闭集合**。
     *
     * 这条断言存在的理由一个字没变：fail-open 是一个诱人的「让测试变绿」的旋钮。
     * 多标一条就等于悄悄关掉一条安全防线，而症状只在 Redis 故障期间出现 ——
     * 那时没有人在看测试。
     *
     * 两条共用**同一个判据**（ADR-0005 决定 3 定的）：纯防 DoS，不是安全控制。
     * 逐条对照见 ADR-0021 的决定 1 —— `config_ip` 守的端点响应对所有人同值、
     * 没有可枚举的东西、不发信、不碰任何密钥。
     *
     * ⚠️ 想加第三条的话：先写 ADR。`on_store_failure` 省略时仍然是 `deny`，
     * 也就是说放松必须**主动声明**，而不是主动记得收紧。
     */
    public function testOnlyTheTwoDocumentedDosPoliciesFailOpen(): void
    {
        $registry = self::registry();

        $failOpen = array_values(array_filter(
            $registry->names(),
            static fn (string $name): bool => !$registry->policy($name)->denyOnStoreFailure,
        ));

        self::assertSame(
            ['config_ip', 'write_endpoints'],
            $failOpen,
            "只有这两条纯防 DoS 的策略允许 fail-open：\n"
            ."  - write_endpoints（ADR-0005 决定 3）\n"
            ."  - config_ip（ADR-0021）—— GET /v1/config，它是唯一一个刻意不依赖\n"
            ."    PG / Redis / Vault 的端点，fail-closed 等于给它新增一个 Redis 依赖\n"
            ."其余每一条都是安全控制：fail-open 等于在 Redis 故障期间关掉 §7.5 的\n"
            .'OTP 与 username 枚举防线。理由见 ADR-0003、ADR-0005 与 ADR-0021。',
        );
    }

    /**
     * §8.2 ROPA：限流计数保留 24 小时。没有一条策略的 TTL 能超过它。
     */
    public function testNoPolicyRetainsCountersBeyondTheRopaPeriod(): void
    {
        $registry = self::registry();

        foreach ($registry->names() as $name) {
            self::assertLessThanOrEqual(
                RateLimitPolicy::MAX_TTL_SECONDS,
                $registry->policy($name)->ttlSeconds(),
                $name.' 的计数保留期超过了 §8.2 允许的 24 小时',
            );
        }
    }

    /**
     * 文档性断言：把「这几行为什么不在配置里」钉住。
     *
     * 有人把它们「补进」配置的话，上面的 testConfigurationHasNoUndocumentedPolicies
     * 会红，而这里的注释是那次失败的解释。
     */
    public function testLifetimeCountersAreDeliberatelyNotRedisPolicies(): void
    {
        $registry = self::registry();

        foreach (array_keys(self::NOT_RATE_LIMITED) as $row) {
            self::assertNotContains($row, $registry->names());
        }

        // 每一条都必须写明去处 —— 「不在这里」不是理由，「在那里」才是。
        // 往 NOT_RATE_LIMITED 里加一条而只写空去处的话，这里会红。
        self::assertSame(
            array_keys(self::NOT_RATE_LIMITED),
            array_keys(array_filter(self::NOT_RATE_LIMITED, static fn (string $where): bool => '' !== trim($where))),
            'NOT_RATE_LIMITED 的每一行都要写明它到底由谁强制',
        );
    }

    private static function registry(): RateLimitPolicyRegistry
    {
        $path = __DIR__.'/../../../../../config/packages/rate_limiter.yaml';
        self::assertFileExists($path);

        /** @var array{parameters?: array{'ncards.rate_limits'?: array<mixed>}} $parsed */
        $parsed = Yaml::parseFile($path);

        return new RateLimitPolicyRegistry($parsed['parameters']['ncards.rate_limits'] ?? []);
    }
}
