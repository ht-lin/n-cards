<?php

declare(strict_types=1);

namespace App\Tests\Double\RateLimit;

use App\Shared\Application\RateLimit\RateLimiterInterface;
use App\Shared\Domain\Error\RateLimitExceeded;
use App\Shared\Domain\RateLimit\RateLimitCheck;

/**
 * 记下每一次限流检查，默认全部放行。
 *
 * 滑动窗口本身由 tests/Integration/Shared/RateLimit 打真 Redis 验；
 * 这个替身只回答两个编排层面的问题：
 *
 *   1. 调用方传的**策略名与主体前缀**对不对（`email:` / `ip:`，
 *      不带前缀的话两个维度会共用计数桶，见 RateLimitCheck 的注释）；
 *   2. 它是不是**一次 consumeAll** 而不是两次 consume ——
 *      后者会重新打开 ADR-0005 关掉的那个「烧掉受害者配额」的洞。
 */
final class RecordingRateLimiter implements RateLimiterInterface
{
    /** @var list<list<RateLimitCheck>> 每次 consumeAll 记一组；consume 记成单元素组 */
    private array $batches = [];

    private ?RateLimitExceeded $failure = null;

    /**
     * 让下一次检查抛 429。
     */
    public function denyWith(RateLimitExceeded $failure): void
    {
        $this->failure = $failure;
    }

    public function consume(string $policy, string $subject, int $tokens = 1): void
    {
        $this->consumeAll([new RateLimitCheck($policy, $subject, $tokens)]);
    }

    public function consumeAll(array $checks): void
    {
        $this->batches[] = $checks;

        if (null !== $this->failure) {
            throw $this->failure;
        }
    }

    /**
     * @return list<list<RateLimitCheck>>
     */
    public function batches(): array
    {
        return $this->batches;
    }

    /**
     * 最近一批检查的 `policy => subject` 映射。
     *
     * @return array<string, string>
     */
    public function lastBatch(): array
    {
        $last = $this->batches[array_key_last($this->batches)] ?? [];

        $map = [];

        foreach ($last as $check) {
            $map[$check->policy] = $check->subject;
        }

        return $map;
    }
}
