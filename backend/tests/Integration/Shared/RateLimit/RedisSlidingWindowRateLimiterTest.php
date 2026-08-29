<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared\RateLimit;

use App\Shared\Domain\Error\DomainException;
use App\Shared\Domain\Error\ErrorCode;
use App\Shared\Domain\Error\RateLimitExceeded;
use App\Shared\Domain\RateLimit\RateLimitCheck;
use App\Shared\Domain\RateLimit\RateLimitPolicy;
use App\Shared\Infrastructure\Random\SecureRandomness;
use App\Shared\Infrastructure\RateLimit\RateLimitPolicyRegistry;
use App\Shared\Infrastructure\RateLimit\RedisSlidingWindowRateLimiter;
use App\Shared\Infrastructure\Redis\RedisConnectionFactory;
use App\Tests\Double\Time\FrozenClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * 真实 Redis 上的滑动窗口语义。
 *
 * ============================================================================
 * 单测测不到什么
 * ============================================================================
 * 这里验的全是 **Lua 脚本本身**：窗口边界、多窗口取最严、`Retry-After` 的精确值、
 * 「全过才写」的原子性、TTL。这些没有一条能用替身验证 —— 替身会照着我们**以为**
 * 脚本做了什么去行为。
 *
 * 沿用 T-003 的 `DatabaseHealthCheckTest` 与 T-004 的 `RedisIdempotencyStoreTest`
 * 立下的规矩：连不上就 skip，裸机 `composer test` 保持全绿。
 *
 * ⚠️ 时钟是 {@see FrozenClock}。滑动窗口最短也有 60 秒，靠 `sleep()` 验边界的话
 * 这个文件要跑一分多钟 —— 而窗口边界恰恰是最需要密集覆盖的地方。
 */
#[CoversClass(RedisSlidingWindowRateLimiter::class)]
#[CoversClass(RateLimitPolicyRegistry::class)]
#[CoversClass(RateLimitPolicy::class)]
final class RedisSlidingWindowRateLimiterTest extends TestCase
{
    /** 起点取一个真实量级的毫秒时间戳（13 位），好让 Lua 的 `%d` 格式化被真的走到。 */
    private const T0 = 1_756_000_000_000;

    private RedisConnectionFactory $connections;
    private FrozenClock $clock;
    private string $subject;

    protected function setUp(): void
    {
        $dsn = $_ENV['REDIS_URL'] ?? $_SERVER['REDIS_URL'] ?? null;

        if (!\is_string($dsn)) {
            self::markTestSkipped('REDIS_URL 未配置。');
        }

        $this->connections = new RedisConnectionFactory($dsn);

        try {
            $this->connections->create()->ping();
        } catch (\Throwable $e) {
            self::markTestSkipped('Redis 不可达（'.$e->getMessage().'）。起 compose 栈后再跑：docker compose -f infra/compose/docker-compose.base.yml up -d redis');
        }

        $this->clock = new FrozenClock(self::T0);
        // 每个用例一个新主体：残留计数会让重跑假红，而滑动窗口最短 60 秒 ——
        // 「等一分钟再跑」不是可接受的开发循环。
        $this->subject = 'test:'.bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        if (!isset($this->connections)) {
            return;
        }

        try {
            $keys = $this->connections->create()->keys(RedisSlidingWindowRateLimiter::KEY_PREFIX.'test:*:'.hash('sha256', $this->subject));

            if ([] !== $keys) {
                $this->connections->create()->del($keys);
            }
        } catch (\Throwable) {
            // 清理失败无所谓，键本来就有 TTL。
        }
    }

    // ========================================================================
    // 单窗口
    // ========================================================================

    public function testAllowsExactlyTheLimitThenDenies(): void
    {
        $limiter = $this->limiter(['p' => $this->policy([[3, 60]])]);

        for ($i = 1; $i <= 3; ++$i) {
            $limiter->consume('p', $this->subject);
            $this->clock->advance(100);
        }

        $this->expectException(RateLimitExceeded::class);
        $limiter->consume('p', $this->subject);
    }

    /**
     * ⚠️ 被拒的那一次**不该**把自己也记进计数。
     *
     * 记了的话，一个持续重试的客户端会不断把窗口往后推，永远出不来 ——
     * 那不是滑动窗口，是一个被重试锁死的黑洞。
     */
    public function testADeniedAttemptDoesNotExtendTheWindow(): void
    {
        $limiter = $this->limiter(['p' => $this->policy([[1, 60]])]);

        $limiter->consume('p', $this->subject);

        // 在窗口内反复撞墙。
        for ($i = 0; $i < 5; ++$i) {
            $this->clock->advance(1000);
            $this->assertDenied($limiter, 'p');
        }

        // 从**第一次**（而不是最后一次撞墙）起满 60 秒即恢复。
        $this->clock->set(self::T0 + 60_001);

        self::assertTrue(
            $this->allows($limiter, 'p'),
            '窗口只由成功的那一次决定 —— 撞墙不该把窗口往后推',
        );
    }

    public function testTheWindowSlidesRatherThanResetting(): void
    {
        $limiter = $this->limiter(['p' => $this->policy([[2, 60]])]);

        $limiter->consume('p', $this->subject);        // t = 0
        $this->clock->advance(30_000);
        $limiter->consume('p', $this->subject);        // t = 30s
        $this->assertDenied($limiter, 'p');

        // t = 60.001s：第一次滑出窗口，第二次还在 → 恰好空出一格。
        $this->clock->set(self::T0 + 60_001);
        $limiter->consume('p', $this->subject);
        $this->assertDenied($limiter, 'p');

        // t = 90.002s：第二次也滑出。
        $this->clock->set(self::T0 + 90_002);
        self::assertTrue($this->allows($limiter, 'p'));
    }

    /**
     * `Retry-After` = 最老一条滑出窗口的时刻，不是一整个窗口长度。
     */
    public function testRetryAfterIsTheExactTimeUntilTheOldestEntryExpires(): void
    {
        $limiter = $this->limiter(['p' => $this->policy([[1, 3600]])]);

        $limiter->consume('p', $this->subject);
        $this->clock->advance(3000 * 1000); // 3000 秒后

        try {
            $limiter->consume('p', $this->subject);
            self::fail('应该被拒');
        } catch (RateLimitExceeded $e) {
            // 3600 - 3000 = 600 秒，而不是整个 3600。
            self::assertSame(600, $e->retryAfterSeconds());
            self::assertSame(0, $e->remaining());
        }
    }

    // ========================================================================
    // 多窗口（§7.5 的 OTP 三重限速）
    // ========================================================================

    /**
     * 一条策略、三个窗口、**一个** Redis 键。
     */
    public function testTheTightestWindowWins(): void
    {
        $limiter = $this->limiter(['otp' => $this->policy([[1, 60], [5, 3600], [10, 86400]])]);

        $limiter->consume('otp', $this->subject);

        // 分钟窗口立刻挡住，尽管小时与日窗口还很空。
        try {
            $this->clock->advance(1000);
            $limiter->consume('otp', $this->subject);
            self::fail('1/min 应该挡住第二次');
        } catch (RateLimitExceeded $e) {
            self::assertLessThanOrEqual(60, $e->retryAfterSeconds());
        }

        // 跨过分钟窗口后可以继续，直到撞上 5/h。
        for ($i = 2; $i <= 5; ++$i) {
            $this->clock->advance(61_000);
            $limiter->consume('otp', $this->subject);
        }

        $this->clock->advance(61_000);

        try {
            $limiter->consume('otp', $this->subject);
            self::fail('5/h 应该挡住第六次');
        } catch (RateLimitExceeded $e) {
            // 现在挡住它的是**小时**窗口，所以等待远超一分钟。
            self::assertGreaterThan(60, $e->retryAfterSeconds());
            self::assertLessThanOrEqual(3600, $e->retryAfterSeconds());
        }
    }

    public function testAllWindowsShareASingleKey(): void
    {
        $limiter = $this->limiter(['otp' => $this->policy([[10, 60], [10, 3600], [10, 86400]])]);

        $limiter->consume('otp', $this->subject);

        $keys = $this->connections->create()->keys(RedisSlidingWindowRateLimiter::KEY_PREFIX.'test:otp:*');
        self::assertCount(1, $keys, '三个窗口不该产生三个键');
    }

    // ========================================================================
    // TTL —— §8.2 ROPA
    // ========================================================================

    public function testTtlMatchesTheLongestWindowAndNeverExceedsTheRopaPeriod(): void
    {
        $limiter = $this->limiter([
            'day' => $this->policy([[10, 86400]]),
            'week' => $this->policy([[10, 7 * 86400]]),
        ]);

        $limiter->consume('day', $this->subject);
        $limiter->consume('week', $this->subject);

        $client = $this->connections->create();
        $hashed = hash('sha256', $this->subject);

        self::assertEqualsWithDelta(
            86400,
            (int) $client->ttl(RedisSlidingWindowRateLimiter::KEY_PREFIX.'test:day:'.$hashed),
            5,
        );

        // 7 天的窗口被截断到 24 小时 —— §8.2 的保留期是硬约束。
        self::assertLessThanOrEqual(
            RateLimitPolicy::MAX_TTL_SECONDS,
            (int) $client->ttl(RedisSlidingWindowRateLimiter::KEY_PREFIX.'test:week:'.$hashed),
        );
    }

    /**
     * ⚠️ 主体不以明文进键名 —— 它是 §8.2 的安全类数据（email_hash / IP / user id）。
     */
    public function testTheSubjectIsHashedIntoTheKey(): void
    {
        $limiter = $this->limiter(['p' => $this->policy([[10, 60]])]);
        $limiter->consume('p', $this->subject);

        $keys = $this->connections->create()->keys(RedisSlidingWindowRateLimiter::KEY_PREFIX.'test:p:*');

        self::assertCount(1, $keys);
        self::assertStringNotContainsString($this->subject, (string) $keys[0]);
        self::assertStringContainsString(hash('sha256', $this->subject), (string) $keys[0]);
    }

    // ========================================================================
    // 多维度：全过才扣
    // ========================================================================

    /**
     * ⚠️ 这是本文件里最要紧的一条。
     *
     * 第二个维度已经满了 → 第一个维度的配额**必须原封不动**。逐个 consume 的话，
     * 攻击者打爆某个共享出口 IP 的配额之后，就能远程烧掉走那个 IP 的任意受害者
     * 自己的 OTP 额度。
     */
    public function testNoDimensionIsChargedWhenAnotherOneDenies(): void
    {
        $limiter = $this->limiter([
            'email' => $this->policy([[1, 60]]),
            'ip' => $this->policy([[1, 3600]]),
        ]);

        // 先把 IP 维度打满（用另一个主体，模拟同 IP 的别人）。
        $limiter->consume('ip', $this->subject.':shared-ip');

        // 受害者的第一次请求：email 维度还是空的，但 IP 维度已满。
        $this->assertDeniedAll($limiter, [
            new RateLimitCheck('email', $this->subject),
            new RateLimitCheck('ip', $this->subject.':shared-ip'),
        ]);

        // IP 维度的窗口过去之后，受害者的 email 配额应该**仍然是满的**。
        $this->clock->advance(3_600_001);

        self::assertTrue(
            $this->allowsAll($limiter, [
                new RateLimitCheck('email', $this->subject),
                new RateLimitCheck('ip', $this->subject.':shared-ip'),
            ]),
            'email 维度的配额没有被上一次失败的请求烧掉',
        );
    }

    /**
     * §7.5：「两者都触发时优先返回**更长的** `Retry-After`」。
     */
    public function testMultiDimensionReportsTheLongestRetryAfter(): void
    {
        $limiter = $this->limiter([
            'short' => $this->policy([[1, 60]]),
            'long' => $this->policy([[1, 3600]]),
        ]);

        $limiter->consumeAll([
            new RateLimitCheck('short', $this->subject),
            new RateLimitCheck('long', $this->subject),
        ]);

        try {
            $limiter->consumeAll([
                new RateLimitCheck('short', $this->subject),
                new RateLimitCheck('long', $this->subject),
            ]);
            self::fail('应该被拒');
        } catch (RateLimitExceeded $e) {
            self::assertSame(3600, $e->retryAfterSeconds(), '取更长的那个窗口');
            // 多维度时不点名策略 —— 否则等于告诉攻击者该换哪个维度。
            self::assertStringNotContainsString('short', $e->detail());
            self::assertStringNotContainsString('long', $e->detail());
        }
    }

    public function testAnEmptyCheckListIsANoOp(): void
    {
        self::assertTrue($this->allowsAll($this->limiter([]), []));
    }

    // ========================================================================
    // 降级：fail-closed 是默认，fail-open 要显式声明
    // ========================================================================

    /**
     * ⚠️ 与 T-004 的幂等**相反**。理由见 ADR-0003，别顺手改成一致。
     */
    public function testFailsClosedWithA503WhenRedisIsUnreachable(): void
    {
        $limiter = $this->unreachableLimiter(['p' => $this->policy([[10, 60]], denyOnStoreFailure: true)]);

        try {
            $limiter->consume('p', $this->subject);
            self::fail('Redis 不可达时该策略必须拒绝');
        } catch (DomainException $e) {
            // 503「我无法判定」，而不是 429「我判定你超限了」。
            self::assertSame(ErrorCode::ServiceUnavailable, $e->errorCode());
            self::assertSame(503, $e->errorCode()->httpStatus());
        }
    }

    /**
     * 唯一的例外：通用写限流（纯防 DoS，非安全控制）。
     */
    public function testFailsOpenOnlyWhenThePolicyOptedIn(): void
    {
        $limiter = $this->unreachableLimiter(['p' => $this->policy([[10, 60]], denyOnStoreFailure: false)]);

        self::assertTrue(
            $this->allows($limiter, 'p'),
            'on_store_failure: allow 的策略在 Redis 故障时放行',
        );
    }

    // ========================================================================
    // 脚本缓存
    // ========================================================================

    /**
     * `SCRIPT FLUSH`（Redis 重启等价物）之后必须自动回落到 `EVAL`。
     *
     * 没有这条回落，重启之后**每一次限流检查都会失败** —— 而 fail-closed
     * 意味着那等于全站 503。
     */
    public function testRecoversFromAnEmptiedScriptCache(): void
    {
        $limiter = $this->limiter(['p' => $this->policy([[10, 60]])]);

        $limiter->consume('p', $this->subject);
        $this->connections->create()->script('flush');

        self::assertTrue($this->allows($limiter, 'p'), 'NOSCRIPT 之后回落到 EVAL');
    }

    // ========================================================================
    // 夹具
    // ========================================================================

    /**
     * @param list<array{int, int}> $windows
     *
     * @return array{dimension: string, on_store_failure: string, windows: list<array{limit: int, window: int}>}
     */
    private function policy(array $windows, bool $denyOnStoreFailure = true): array
    {
        return [
            'dimension' => 'test',
            'on_store_failure' => $denyOnStoreFailure ? 'deny' : 'allow',
            'windows' => array_map(
                static fn (array $w): array => ['limit' => $w[0], 'window' => $w[1]],
                $windows,
            ),
        ];
    }

    /**
     * @param array<string, array<mixed>> $policies
     */
    private function limiter(array $policies): RedisSlidingWindowRateLimiter
    {
        return new RedisSlidingWindowRateLimiter(
            $this->connections,
            new RateLimitPolicyRegistry($policies),
            $this->clock,
            new SecureRandomness(),
            // 键里的环境段。用 `test` 让 tearDown 的通配清理能匹配上。
            'test',
        );
    }

    /**
     * 指向一个没人监听的端口 —— 比 stop 掉容器更快也更可重复。
     *
     * @param array<string, array<mixed>> $policies
     */
    private function unreachableLimiter(array $policies): RedisSlidingWindowRateLimiter
    {
        return new RedisSlidingWindowRateLimiter(
            new RedisConnectionFactory('redis://127.0.0.1:1'),
            new RateLimitPolicyRegistry($policies),
            $this->clock,
            new SecureRandomness(),
            'test',
        );
    }

    /**
     * 「这一次没被拒」—— 用返回值而不是 `assertTrue(true)`，
     * 后者在 phpstan-phpunit 下是一条 always-true 的死断言。
     */
    private function allows(RedisSlidingWindowRateLimiter $limiter, string $policy): bool
    {
        try {
            $limiter->consume($policy, $this->subject);
        } catch (RateLimitExceeded) {
            return false;
        }

        return true;
    }

    /**
     * @param list<RateLimitCheck> $checks
     */
    private function allowsAll(RedisSlidingWindowRateLimiter $limiter, array $checks): bool
    {
        try {
            $limiter->consumeAll($checks);
        } catch (RateLimitExceeded) {
            return false;
        }

        return true;
    }

    private function assertDenied(RedisSlidingWindowRateLimiter $limiter, string $policy): void
    {
        try {
            $limiter->consume($policy, $this->subject);
            self::fail(\sprintf('策略 %s 应该拒绝这一次', $policy));
        } catch (RateLimitExceeded $e) {
            self::assertGreaterThanOrEqual(1, $e->retryAfterSeconds());
            self::assertSame(0, $e->remaining());
        }
    }

    /**
     * @param list<RateLimitCheck> $checks
     */
    private function assertDeniedAll(RedisSlidingWindowRateLimiter $limiter, array $checks): void
    {
        try {
            $limiter->consumeAll($checks);
            self::fail('多维度检查应该被拒');
        } catch (RateLimitExceeded $e) {
            self::assertGreaterThanOrEqual(1, $e->retryAfterSeconds());
        }
    }
}
