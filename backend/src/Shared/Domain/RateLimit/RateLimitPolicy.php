<?php

declare(strict_types=1);

namespace App\Shared\Domain\RateLimit;

/**
 * `config/packages/rate_limiter.yaml` 里的一条策略。
 *
 * 一条策略 = 一个维度（email_hash / ip / user / …）上的**一组**滑动窗口。
 * 全部窗口同时生效，任一超限即拒绝。
 */
final readonly class RateLimitPolicy
{
    /**
     * §8.2 ROPA：「限流计数保留 **24 小时**」。
     *
     * ⚠️ 这是**合规上限**，不是性能调优参数。Redis 键的 TTL 一律不得超过它 ——
     * §7.5 最长的窗口恰好就是 1 天（OTP 的 10/day），所以正常情况下两者相等；
     * 但将来有人配一个 7 天的窗口时，这里会把 TTL 截断到 24 小时，
     * 于是那条策略会静默地只覆盖最近一天。宁可这样，也不能违反保留期 ——
     * {@see ttlSeconds()} 的调用方要能看到这条注释。
     */
    public const MAX_TTL_SECONDS = 86400;

    /** @var non-empty-list<RateLimitWindow> */
    public array $windows;

    /**
     * @param string                            $name               策略名，即 yaml 里的键
     * @param string                            $dimension          主体的语义维度。**只用于文档与日志**，
     *                                                              不参与计算 —— 真正的主体由调用方传入
     * @param array<array-key, RateLimitWindow> $windows
     * @param bool                              $denyOnStoreFailure Redis 不可达时拒绝（fail-closed）还是放行。
     *                                                              默认 true，见 {@see \App\Shared\Application\RateLimit\RateLimitStoreUnavailable}
     */
    public function __construct(
        public string $name,
        public string $dimension,
        array $windows,
        public bool $denyOnStoreFailure = true,
    ) {
        if ([] === $windows) {
            throw new \InvalidArgumentException(\sprintf('限流策略 "%s" 至少要有一个窗口。', $name));
        }

        $this->windows = array_values($windows);
    }

    /**
     * 最长窗口（秒）。ZSET 里早于 `now - 这个值` 的条目对任何窗口都没用了。
     */
    public function longestWindowSeconds(): int
    {
        return max(array_map(static fn (RateLimitWindow $w): int => $w->windowSeconds, $this->windows));
    }

    /**
     * Redis 键的 TTL。等于最长窗口，但**封顶 24 小时**（§8.2）。
     */
    public function ttlSeconds(): int
    {
        return min($this->longestWindowSeconds(), self::MAX_TTL_SECONDS);
    }
}
