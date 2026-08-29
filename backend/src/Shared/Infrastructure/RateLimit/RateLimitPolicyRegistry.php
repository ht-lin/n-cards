<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\RateLimit;

use App\Shared\Domain\RateLimit\RateLimitPolicy;
use App\Shared\Domain\RateLimit\RateLimitWindow;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * `%ncards.rate_limits%`（`config/packages/rate_limiter.yaml`）→ {@see RateLimitPolicy} 对象。
 *
 * ============================================================================
 * 为什么在构造期就全量解析
 * ============================================================================
 * 配错一条策略（少个 `windows`、`limit: 0`、多打一个字母）应该在**首次实例化**时
 * 就炸，而不是等到那条策略第一次被真实请求碰到 —— 而 §7.5 里好几条策略要到
 * M1 / M2 才有端点，「第一次被碰到」可能是上线好几周之后。
 *
 * 这条「构造期校验」的先例与陷阱见 {@see \App\Shared\Infrastructure\Http\ClientVersionListener}
 * 的类注释：`EventDispatcher::sortListeners()` 会在调用任何监听器之前实例化
 * `kernel.request` 上的全部监听器，所以这里抛出的异常会早于 `RequestIdListener`。
 * 那对**配置错误**来说恰恰是想要的（进程起不来 > 静默不限流），但也意味着
 * 异常消息必须能自解释 —— 它是运维唯一能看到的东西。
 *
 * 顺带：`tests/Integration/ContainerCompilesTest` 会实例化整个容器，
 * 所以一条配错的策略在 CI 里就会红。
 */
final class RateLimitPolicyRegistry
{
    /** @var array<string, RateLimitPolicy> */
    private readonly array $policies;

    /**
     * @param array<mixed> $config   `%ncards.rate_limits%`
     * @param array<mixed> $testOnly `%ncards.rate_limits.test_only%`，生产恒为空
     */
    public function __construct(
        #[Autowire('%ncards.rate_limits%')]
        array $config,
        #[Autowire('%ncards.rate_limits.test_only%')]
        array $testOnly = [],
    ) {
        $policies = [];

        /** @var mixed $raw */
        foreach ([...$config, ...$testOnly] as $name => $raw) {
            if (!\is_string($name)) {
                throw new \InvalidArgumentException('限流策略名必须是字符串。');
            }

            $policies[$name] = self::parse($name, $raw);
        }

        $this->policies = $policies;
    }

    /**
     * @throws \InvalidArgumentException 策略名不存在。这是**配置错误**而不是运行时状况 ——
     *                                   调用方不该 catch 它，它该在开发期就炸
     */
    public function policy(string $name): RateLimitPolicy
    {
        return $this->policies[$name]
            ?? throw new \InvalidArgumentException(\sprintf('限流策略 "%s" 未定义。已知策略：%s。检查 config/packages/rate_limiter.yaml。', $name, implode(', ', array_keys($this->policies))));
    }

    public function has(string $name): bool
    {
        return isset($this->policies[$name]);
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->policies);
    }

    private static function parse(string $name, mixed $raw): RateLimitPolicy
    {
        if (!\is_array($raw)) {
            throw new \InvalidArgumentException(\sprintf('限流策略 "%s" 必须是一个映射。', $name));
        }

        $dimension = $raw['dimension'] ?? null;

        if (!\is_string($dimension) || '' === $dimension) {
            throw new \InvalidArgumentException(\sprintf('限流策略 "%s" 缺少 dimension。', $name));
        }

        $windows = $raw['windows'] ?? null;

        if (!\is_array($windows) || [] === $windows) {
            throw new \InvalidArgumentException(\sprintf('限流策略 "%s" 至少要有一个 window。', $name));
        }

        $parsed = [];

        /** @var mixed $window */
        foreach ($windows as $window) {
            if (!\is_array($window) || !\is_int($window['limit'] ?? null) || !\is_int($window['window'] ?? null)) {
                throw new \InvalidArgumentException(\sprintf('限流策略 "%s" 的窗口必须形如 {limit: <int>, window: <int 秒>}。', $name));
            }

            $parsed[] = new RateLimitWindow($window['limit'], $window['window']);
        }

        return new RateLimitPolicy(
            $name,
            $dimension,
            $parsed,
            // ⚠️ `array_key_exists` 而不是 `??`：`on_store_failure:`（键在、值为空）
            // 在 YAML 里解析成 null，`??` 会把它悄悄当成「没写」而回落到 deny。
            // 那个回落方向虽然是安全的，但它掩盖了一个打错的配置 —— 而下一次
            // 那个人想写的可能是 allow。写了键就必须写对值。
            self::parseStoreFailure($name, \array_key_exists('on_store_failure', $raw) ? $raw['on_store_failure'] : 'deny'),
        );
    }

    /**
     * ⚠️ 默认 `deny`（fail-closed）。省略这一行就是最严格的那个选择 ——
     * 新增策略时要主动声明放松，而不是主动记得收紧。理由见
     * {@see \App\Shared\Application\RateLimit\RateLimitStoreUnavailable} 与 ADR-0003。
     */
    private static function parseStoreFailure(string $name, mixed $value): bool
    {
        return match ($value) {
            'deny' => true,
            'allow' => false,
            default => throw new \InvalidArgumentException(\sprintf('限流策略 "%s" 的 on_store_failure 只能是 "deny"（默认）或 "allow"。', $name)),
        };
    }
}
