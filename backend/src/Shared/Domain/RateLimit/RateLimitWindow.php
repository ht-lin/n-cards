<?php

declare(strict_types=1);

namespace App\Shared\Domain\RateLimit;

/**
 * 一个滑动窗口：`$limit` 次 / `$windowSeconds` 秒。
 *
 * §7.5 的 `POST /auth/otp/request` 一行同时给了三个窗口（1/min、5/h、10/day），
 * 所以窗口是**列表**而不是策略的两个标量字段。
 */
final readonly class RateLimitWindow
{
    /**
     * @param int $limit         窗口内允许的次数，必须 ≥ 1
     * @param int $windowSeconds 窗口长度（秒），必须 ≥ 1
     */
    public function __construct(
        public int $limit,
        public int $windowSeconds,
    ) {
        // 构造期校验：配错了要在容器编译后的首次实例化时就炸，
        // 而不是等到某个真实请求进来才发现 `limit: 0` 把端点彻底关死了。
        if ($limit < 1) {
            throw new \InvalidArgumentException(\sprintf('限流窗口的 limit 必须 ≥ 1，收到 %d。', $limit));
        }

        if ($windowSeconds < 1) {
            throw new \InvalidArgumentException(\sprintf('限流窗口的 window 必须 ≥ 1 秒，收到 %d。', $windowSeconds));
        }
    }
}
