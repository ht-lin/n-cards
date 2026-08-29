<?php

declare(strict_types=1);

namespace App\Shared\Domain\RateLimit;

/**
 * 一次限流判定的结果。
 *
 * 两个数字都直接变成响应头（§7.5：「限流响应**必须**带 `Retry-After` 与
 * `X-RateLimit-Remaining`」），所以它们的语义要精确：
 *
 *   - `remaining` 是**全部窗口里最小的**剩余次数。三个窗口 1/min、5/h、10/day
 *     下刚用掉第一次，剩余是 0（分钟窗口），不是 9（日窗口）—— 客户端关心的
 *     是「我下一次能不能发」。
 *   - `retryAfterSeconds` 是**被违反的窗口里最长的**等待时间，向上取整，下限 1。
 *     取最长是因为任一窗口未恢复就仍会被拒；给一个偏短的值只会诱导客户端空转重试。
 */
final readonly class RateLimitDecision
{
    private function __construct(
        public bool $allowed,
        public int $remaining,
        public int $retryAfterSeconds,
    ) {
    }

    public static function allowed(int $remaining): self
    {
        return new self(true, max(0, $remaining), 0);
    }

    public static function denied(int $remaining, int $retryAfterSeconds): self
    {
        // Retry-After 的下限是 1：`Retry-After: 0` 会让客户端立刻重试，
        // 而那一次必然还是被拒 —— 等于把一个限流变成一个忙等循环。
        return new self(false, max(0, $remaining), max(1, $retryAfterSeconds));
    }

    /**
     * 多维度合并：任一拒绝即拒绝，取**更长的** `Retry-After` 与**更小的** `remaining`。
     *
     * §7.5 对 `GET /v1/users/lookup` 的旁注明确要求了前半句：
     * 「两者都触发时优先返回更长的 `Retry-After`」。
     */
    public function mergeWith(self $other): self
    {
        return new self(
            $this->allowed && $other->allowed,
            min($this->remaining, $other->remaining),
            max($this->retryAfterSeconds, $other->retryAfterSeconds),
        );
    }
}
