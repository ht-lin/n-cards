<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Domain\RateLimit;

use App\Shared\Domain\RateLimit\RateLimitDecision;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * §7.5 的合并规则：「多维度同时触发时优先返回**更长的** `Retry-After`」。
 *
 * 这条规则很容易写反（取 min 看起来「对用户更友好」），而写反的后果是
 * 客户端按一个偏短的值重试、必然再次被拒 —— 于是限流变成了一个忙等循环，
 * 反而放大了它本该挡住的负载。
 */
#[CoversClass(RateLimitDecision::class)]
final class RateLimitDecisionTest extends TestCase
{
    public function testAllowedCarriesRemainingAndNoRetryAfter(): void
    {
        $decision = RateLimitDecision::allowed(7);

        self::assertTrue($decision->allowed);
        self::assertSame(7, $decision->remaining);
        self::assertSame(0, $decision->retryAfterSeconds);
    }

    /**
     * `Retry-After: 0` 会让客户端立刻重试，而那一次必然还是被拒。
     */
    public function testDeniedRetryAfterIsNeverBelowOneSecond(): void
    {
        self::assertSame(1, RateLimitDecision::denied(0, 0)->retryAfterSeconds);
        self::assertSame(1, RateLimitDecision::denied(0, -5)->retryAfterSeconds);
        self::assertSame(42, RateLimitDecision::denied(0, 42)->retryAfterSeconds);
    }

    public function testRemainingIsNeverNegative(): void
    {
        self::assertSame(0, RateLimitDecision::allowed(-3)->remaining);
        self::assertSame(0, RateLimitDecision::denied(-3, 10)->remaining);
    }

    /**
     * §7.5 的明文要求。
     */
    public function testMergeTakesTheLongerRetryAfter(): void
    {
        $merged = RateLimitDecision::denied(0, 30)->mergeWith(RateLimitDecision::denied(0, 3600));

        self::assertFalse($merged->allowed);
        self::assertSame(3600, $merged->retryAfterSeconds, '两个维度都触发时取更长的等待');
    }

    /**
     * `remaining` 取更小的：客户端关心的是「我下一次能不能发」，
     * 而那由最紧的那个维度决定。
     */
    public function testMergeTakesTheSmallerRemaining(): void
    {
        $merged = RateLimitDecision::allowed(299)->mergeWith(RateLimitDecision::allowed(4));

        self::assertTrue($merged->allowed);
        self::assertSame(4, $merged->remaining);
    }

    /**
     * 任一维度拒绝即拒绝，且被拒维度的 `Retry-After` 不会被放行维度的 0 冲掉。
     */
    public function testMergeIsDeniedWhenAnyDimensionIsDenied(): void
    {
        $merged = RateLimitDecision::allowed(299)->mergeWith(RateLimitDecision::denied(0, 60));

        self::assertFalse($merged->allowed);
        self::assertSame(0, $merged->remaining);
        self::assertSame(60, $merged->retryAfterSeconds);
    }

    public function testMergeIsCommutative(): void
    {
        $a = RateLimitDecision::allowed(10);
        $b = RateLimitDecision::denied(0, 90);

        $left = $a->mergeWith($b);
        $right = $b->mergeWith($a);

        self::assertSame($left->allowed, $right->allowed);
        self::assertSame($left->remaining, $right->remaining);
        self::assertSame($left->retryAfterSeconds, $right->retryAfterSeconds);
    }
}
