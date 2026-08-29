<?php

declare(strict_types=1);

namespace App\Shared\Domain\RateLimit;

/**
 * 「拿策略 `$policy` 检查主体 `$subject`」——
 * {@see \App\Shared\Application\RateLimit\RateLimiterInterface::consumeAll()} 的入参。
 *
 * 之所以要这个小对象而不是 `array{string, string}`：多维度限流的调用点长这样
 *
 * ```php
 * $limiter->consumeAll([
 *     new RateLimitCheck('user_lookup_user', 'user:'.$userId),
 *     new RateLimitCheck('user_lookup_ip', 'ip:'.$request->getClientIp()),
 * ]);
 * ```
 *
 * 用元组数组的话，把 policy 与 subject 写反是编译期看不出来的 —— 而症状是
 * 「限流按错误的维度生效」，功能测试照样绿。
 */
final readonly class RateLimitCheck
{
    /**
     * @param string $policy  `config/packages/rate_limiter.yaml` 里的策略名
     * @param string $subject 被限流的主体。**必须带维度前缀**（`ip:`、`user:`、
     *                        `email:` …）—— 不带的话，一个 IP 字符串与一个恰好
     *                        相同的 device id 会共用同一个计数桶
     * @param int    $tokens  本次消耗几次配额。恒为 1，留着是为了将来「一次请求
     *                        算 N 次」的场景（比如批量端点）
     */
    public function __construct(
        public string $policy,
        public string $subject,
        public int $tokens = 1,
    ) {
        if ('' === $subject) {
            // 空主体会让所有请求挤进同一个桶 —— 那既不是限流也不是放行，
            // 是一个看起来在工作的全局熔断。宁可在调用点炸掉。
            throw new \InvalidArgumentException(\sprintf('限流策略 "%s" 的主体不能为空。', $policy));
        }

        if ($tokens < 1) {
            throw new \InvalidArgumentException(\sprintf('限流策略 "%s" 的 tokens 必须 ≥ 1。', $policy));
        }
    }
}
