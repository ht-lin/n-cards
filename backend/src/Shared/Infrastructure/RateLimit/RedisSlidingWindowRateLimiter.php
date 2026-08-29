<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\RateLimit;

use App\Shared\Application\RateLimit\RateLimiterInterface;
use App\Shared\Application\RateLimit\RateLimitStoreUnavailable;
use App\Shared\Domain\Error\DomainException;
use App\Shared\Domain\Error\ErrorCode;
use App\Shared\Domain\Error\RateLimitExceeded;
use App\Shared\Domain\Random\RandomnessInterface;
use App\Shared\Domain\RateLimit\RateLimitCheck;
use App\Shared\Domain\RateLimit\RateLimitDecision;
use App\Shared\Domain\RateLimit\RateLimitPolicy;
use App\Shared\Domain\Time\ClockInterface;
use App\Shared\Infrastructure\Redis\RedisConnectionFactory;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * §7.5 速率限制的 Redis 实现：**ZSET 时间戳日志 + 一条 Lua 脚本**。
 *
 * ============================================================================
 * 为什么不是 symfony/rate-limiter
 * ============================================================================
 * 完整论证在 `docs/adr/0005-rate-limiting-topology.md`，最要命的一条：它默认的
 * `CacheStorage` 建在 symfony/cache 的 RedisAdapter 上，而那个适配器**静默吞掉**
 * Redis 异常当作 cache miss —— 于是 Redis 一挂，限流器认为每个人都是第一次来，
 * **静默 fail-open**。§7.5 的 OTP 与 username 枚举防线在故障期间凭空消失，
 * 日志里只有一行 "Failed to fetch key"。那不是配置能关掉的行为。
 *
 * 这里反过来：Predis 的异常**直接冒泡**（`RedisConnectionFactory` 已经设了
 * `exceptions => true`），fail-closed 是默认而不是补丁。
 *
 * ============================================================================
 * 算法：一个键、一个 ZSET、N 个窗口
 * ============================================================================
 * member 是随机 token，score 是毫秒时间戳。**一条策略的全部窗口共用同一个键**：
 * 条目保留到最长窗口，每个窗口用 `ZCOUNT(now - window, now)` 各自计数。
 * 于是 §7.5 的 OTP 三重限速（1/min + 5/h + 10/day）与单重限速的存储成本相同。
 *
 * 选精确日志而不是 Symfony 那种两桶近似：§7.5 的上限都很小（最大 300），
 * ZSET 的内存可以忽略；而精确日志能算出**精确的** `Retry-After`
 * （= 被违反窗口里最老一条的时间戳 + 窗口长度 − 现在），近似算法只能给窗口长度。
 *
 * ============================================================================
 * ⚠️ 「先全查、全过才写」必须在同一条脚本里
 * ============================================================================
 * 拆成多次往返的话，部分窗口被扣、另一部分被拒，计数从此偏高 ——
 * OTP 的 1/min 会变成实际上更严格且不可解释的东西。Lua 在 Redis 里是原子执行的，
 * 所以清理 + 全窗口检查 + 写入是一个不可分割的步骤，不需要任何锁。
 *
 * ============================================================================
 * ⚠️ now 由 PHP 传入，脚本里**不调** TIME
 * ============================================================================
 * 两个理由：① 测试要能用 `tests/Double/Time/FrozenClock` 精确驱动窗口边界；
 * ② 脚本保持无副作用地确定性（同样的 KEYS/ARGV 永远产生同样的写入）。
 * 代价是多个 app 容器之间的时钟偏移会体现在窗口边界上 —— §14.2 是单机部署，
 * 且 NTP 的偏移量级（毫秒）对 60 秒起步的窗口无关紧要。
 */
final readonly class RedisSlidingWindowRateLimiter implements RateLimiterInterface
{
    /**
     * 键前缀带版本号：将来改存储格式时换 `v2` 并让旧键自然过期，
     * 与 {@see \App\Shared\Infrastructure\Redis\RedisIdempotencyStore::KEY_PREFIX} 同一套约定。
     */
    public const KEY_PREFIX = 'ncards:rl:v1:';

    /**
     * ARGV 布局：`now_ms, tokens, ttl, commit, seed, [limit, window]...`.
     *
     * ⚠️ 所有数字都经 `string.format('%d', …)` 才拼进命令参数。
     * Lua 5.1 的数字是 double，隐式 `tostring` 用 `%.14g` —— 毫秒时间戳有 13 位，
     * 今天恰好没事，但任何一次算术让它多出一位（或有人改用微秒）就会变成
     * `1.7567891234568e+15`，Redis 收到的是一个**语法上合法但语义完全错误**的
     * score。症状是限流静默失效，不是报错。
     */
    private const SCRIPT = <<<'LUA'
        local function n(x) return string.format('%d', x) end

        local now    = tonumber(ARGV[1])
        local tokens = tonumber(ARGV[2])
        local ttl    = tonumber(ARGV[3])
        local commit = tonumber(ARGV[4]) == 1
        local seed   = ARGV[5]

        -- 1. 清掉对任何窗口都已无用的条目（早于最长窗口的）。
        local longest = 0
        for i = 6, #ARGV, 2 do
          local w = tonumber(ARGV[i + 1])
          if w > longest then longest = w end
        end
        redis.call('ZREMRANGEBYSCORE', KEYS[1], '-inf', '(' .. n(now - longest * 1000))

        -- 2. 逐窗口检查。remaining 取各窗口的最小值，retry 取被违反窗口的最大值。
        --    下界用 '(' 开区间：恰好落在 now - window 上的条目已经滑出窗口了。
        local remaining = -1
        local retry     = 0
        local denied    = false

        for i = 6, #ARGV, 2 do
          local limit  = tonumber(ARGV[i])
          local window = tonumber(ARGV[i + 1])
          local from   = '(' .. n(now - window * 1000)
          local count  = redis.call('ZCOUNT', KEYS[1], from, '+inf')

          local left = limit - count
          if left < 0 then left = 0 end
          if remaining < 0 or left < remaining then remaining = left end

          if count + tokens > limit then
            denied = true
            -- 该窗口里最老的一条滑出窗口的时刻，就是最早能重试的时刻。
            -- ⚠️ WITHSCORES 必须排在 LIMIT 之前，否则 Redis 报语法错误。
            local oldest = redis.call('ZRANGEBYSCORE', KEYS[1], from, '+inf', 'WITHSCORES', 'LIMIT', 0, 1)
            local wait = window
            if oldest[2] then
              wait = math.ceil((tonumber(oldest[2]) + window * 1000 - now) / 1000)
            end
            if wait < 1 then wait = 1 end
            if wait > retry then retry = wait end
          end
        end

        if denied then
          return {0, remaining, retry}
        end

        -- 3. 全部窗口都通过才写入。member 用调用方传来的 seed，保证脚本确定性
        --    （脚本内不能用 math.random / TIME，否则复制与 AOF 会不一致）。
        if commit then
          for i = 1, tokens do
            redis.call('ZADD', KEYS[1], n(now), seed .. ':' .. i)
          end
          redis.call('EXPIRE', KEYS[1], n(ttl))
          remaining = remaining - tokens
          if remaining < 0 then remaining = 0 end
        end

        return {1, remaining, 0}
        LUA;

    public function __construct(
        private RedisConnectionFactory $connections,
        private RateLimitPolicyRegistry $policies,
        private ClockInterface $clock,
        private RandomnessInterface $randomness,
        #[Autowire('%kernel.environment%')]
        private string $environment,
        private ?LoggerInterface $logger = null,
    ) {
    }

    public function consume(string $policy, string $subject, int $tokens = 1): void
    {
        $this->consumeAll([new RateLimitCheck($policy, $subject, $tokens)]);
    }

    public function consumeAll(array $checks): void
    {
        if ([] === $checks) {
            return;
        }

        // ------------------------------------------------------------------
        // 单维度：一次往返直接消耗。这是绝大多数调用点的形状。
        // ------------------------------------------------------------------
        if (1 === \count($checks)) {
            $check = $checks[0];
            $decision = $this->evaluate($check, commit: true);

            if (!$decision->allowed) {
                throw new RateLimitExceeded($decision->retryAfterSeconds, $decision->remaining, $check->policy);
            }

            return;
        }

        // ------------------------------------------------------------------
        // ⚠️ 多维度：两阶段（先全查，全过才全扣）。
        //
        // 逐个 consume 的话，攻击者打爆某个共享出口 IP 的配额之后，每一个走那个 IP
        // 的正常用户在被 IP 维度拒绝之前，**自己的 email 维度配额已经被扣掉了**
        // —— 于是攻击者能远程烧掉任意受害者的 OTP 额度（§7.5 的 1/min 会变成
        // 「这一分钟你也别想登录」）。那正是这套限流要防的东西。
        //
        // 代价是多一轮往返。两阶段之间的竞态是良性的：并发下最坏是多放行一次，
        // 而 §9.3 的峰值约 15 req/s，同一主体同时发两个请求本身就极少。
        // ------------------------------------------------------------------
        $this->assertAllowed($this->evaluateAll($checks, commit: false));

        // 第二阶段。理论上不该再被拒（第一阶段刚查过），但两阶段之间是有窗口的 ——
        // 真被拒了就照常抛，绝不能因为「第一阶段说可以」而放行。
        $this->assertAllowed($this->evaluateAll($checks, commit: true));
    }

    /**
     * @param list<RateLimitCheck> $checks
     */
    private function evaluateAll(array $checks, bool $commit): RateLimitDecision
    {
        $verdict = null;

        foreach ($checks as $check) {
            $decision = $this->evaluate($check, $commit);
            $verdict = null === $verdict ? $decision : $verdict->mergeWith($decision);
        }

        return $verdict ?? RateLimitDecision::allowed(0);
    }

    /**
     * ⚠️ 多维度被拒时**不点名具体策略**。
     *
     * 「是 IP 维度拒的」等于告诉攻击者该换 IP 还是换邮箱 —— §3.8 的枚举防线同理：
     * 错误响应不该成为一个「哪条防线先倒」的探针。单维度时点名是安全的
     * （只有一条，没有可泄露的信息），那条路径在 consumeAll() 上半段。
     */
    private function assertAllowed(RateLimitDecision $decision): void
    {
        if (!$decision->allowed) {
            throw new RateLimitExceeded($decision->retryAfterSeconds, $decision->remaining);
        }
    }

    /**
     * 一次 Lua 调用，外加 Redis 故障时的降级分支。
     *
     * @param bool $commit false = 只查不扣（两阶段的第一阶段）
     */
    private function evaluate(RateLimitCheck $check, bool $commit): RateLimitDecision
    {
        $policy = $this->policies->policy($check->policy);

        try {
            return $this->run($policy, $check, $commit);
        } catch (RateLimitStoreUnavailable $e) {
            return $this->degrade($policy, $e);
        }
    }

    /**
     * §7.5 的降级分支。默认 fail-CLOSED —— 与 T-004 的幂等**相反**，理由见
     * {@see RateLimitStoreUnavailable} 的类注释与 ADR-0003，**不要**顺手改成一致。
     */
    private function degrade(RateLimitPolicy $policy, RateLimitStoreUnavailable $e): RateLimitDecision
    {
        $this->logger?->error('Rate limit store unavailable', [
            'policy' => $policy->name,
            'fail_closed' => $policy->denyOnStoreFailure,
            'exception' => $e,
        ]);

        if (!$policy->denyOnStoreFailure) {
            // 唯一走到这里的是 `write_endpoints`（纯防 DoS，非安全控制）。
            // remaining 报 0 —— 我们确实不知道还剩几次，报一个编出来的数更糟。
            return RateLimitDecision::allowed(0);
        }

        // ⚠️ 503 而不是 429：429 的语义是「我判定你超限了」，而这里的真实情况是
        // 「我**无法判定**」。客户端对两者的反应也该不同（§5.4.3 的 outbox 重试策略）。
        // `ApiProblemExceptionListener::headersFor()` 会给它配上 Retry-After。
        throw new DomainException(ErrorCode::ServiceUnavailable, 'Rate limiting is temporarily unavailable.', [], [], $e);
    }

    /**
     * @throws RateLimitStoreUnavailable Redis 不可达 / 脚本执行失败
     */
    private function run(RateLimitPolicy $policy, RateLimitCheck $check, bool $commit): RateLimitDecision
    {
        // 全部转成字符串：Predis 的 `eval(string $script, int $numkeys, string ...$keyOrArg)`
        // 声明的就是字符串变参，混进 int 会在 phpstan level 8 报错。
        $argv = [
            (string) $this->clock->nowMillis(),
            (string) $check->tokens,
            (string) $policy->ttlSeconds(),
            $commit ? '1' : '0',
            // 每次写入用一串新随机 token 作 ZSET member 前缀。必须唯一：
            // 重复的 member 会被 ZADD 当成「更新 score」而不是「新增一条」，
            // 于是同一毫秒内的两次请求只会计一次。
            bin2hex($this->randomness->bytes(8)),
        ];

        foreach ($policy->windows as $window) {
            $argv[] = (string) $window->limit;
            $argv[] = (string) $window->windowSeconds;
        }

        try {
            $raw = $this->execute($this->key($policy, $check->subject), $argv);
        } catch (\Throwable $e) {
            throw new RateLimitStoreUnavailable(\sprintf('Failed to evaluate rate limit policy "%s".', $policy->name), 0, $e);
        }

        if (!\is_array($raw) || !isset($raw[0], $raw[1], $raw[2])) {
            throw new RateLimitStoreUnavailable(\sprintf('Rate limit script returned an unexpected shape for policy "%s".', $policy->name));
        }

        $allowed = 1 === (int) $raw[0];
        $remaining = (int) $raw[1];

        return $allowed
            ? RateLimitDecision::allowed($remaining)
            : RateLimitDecision::denied($remaining, (int) $raw[2]);
    }

    /**
     * `EVALSHA` 优先，`NOSCRIPT` 时回落到 `EVAL`。
     *
     * 每个请求发一整份脚本（约 1.5 KB）是纯浪费；但 Redis 重启后脚本缓存是空的，
     * 所以回落分支不是可选的 —— 没有它，重启之后**每一次限流检查都会失败**，
     * 而 fail-closed 意味着那等于全站 503。
     *
     * @param list<string> $argv
     */
    private function execute(string $key, array $argv): mixed
    {
        $client = $this->connections->create();
        $sha = sha1(self::SCRIPT);

        try {
            return $client->evalsha($sha, 1, $key, ...$argv);
        } catch (\Throwable $e) {
            if (!str_contains($e->getMessage(), 'NOSCRIPT')) {
                throw $e;
            }
        }

        return $client->eval(self::SCRIPT, 1, $key, ...$argv);
    }

    /**
     * `ncards:rl:v1:<env>:<policy>:<sha256(subject)>`.
     *
     * ⚠️ 主体**必须**哈希后才进键名。它是 email_hash / IP / user id ——
     * §8.2 ROPA 里的「安全」类数据。Redis 的 `KEYS *` 或一次 RDB 泄露不该等于
     * 一份「最近 24 小时活跃邮箱哈希 / IP」的清单。哈希不影响功能：
     * 我们只需要相等性，从不需要读回原值。
     *
     * 键里带 `<env>` 是为了让开发机上 `composer test` 的计数不会与手工
     * `docker compose exec` 出来的 dev 请求共用同一个桶。
     */
    private function key(RateLimitPolicy $policy, string $subject): string
    {
        return self::KEY_PREFIX.$this->environment.':'.$policy->name.':'.hash('sha256', $subject);
    }
}
