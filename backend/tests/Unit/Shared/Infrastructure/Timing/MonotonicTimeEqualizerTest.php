<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Timing;

use App\Shared\Infrastructure\Timing\MonotonicTimeBudget;
use App\Shared\Infrastructure\Timing\MonotonicTimeEqualizer;
use App\Tests\Double\RecordingLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;

/**
 * §3.8 恒定耗时预算的实现。
 *
 * ⚠️ 这是本仓库里少数**必须**用墙钟断言的测试 —— 被测行为就是「有没有真的睡」。
 * 所以预算取得很小（20 ms），且断言只查「至少睡够了」这一侧的下界，
 * 不查上界：上界会因为 CI runner 的调度抖动而假红，而睡多了不是安全问题。
 */
#[CoversClass(MonotonicTimeEqualizer::class)]
#[CoversClass(MonotonicTimeBudget::class)]
final class MonotonicTimeEqualizerTest extends TestCase
{
    private const BUDGET_MS = 20;

    private const NANOS_PER_MILLI = 1_000_000;

    public function testPadsAFastPathUpToTheBudget(): void
    {
        $logger = new RecordingLogger();
        $equalizer = new MonotonicTimeEqualizer($logger);

        $startedAt = MonotonicTimeEqualizer::nowNanos();
        $equalizer->begin(self::BUDGET_MS)->settle();
        $elapsedMs = (MonotonicTimeEqualizer::nowNanos() - $startedAt) / self::NANOS_PER_MILLI;

        // 只查下界。上界（「没睡过头」）在共享 runner 上会因为调度抖动假红，
        // 而睡多了不构成信息泄露 —— 泄露只来自睡**不够**。
        self::assertGreaterThanOrEqual(self::BUDGET_MS * 0.9, $elapsedMs);
        self::assertSame([], $logger->records, 'A fast path must not warn.');
    }

    /**
     * 两条快慢不同的路径，settle 之后耗时必须落到同一个量级 ——
     * 这正是防枚举依赖的性质。
     */
    public function testTwoPathsOfDifferentCostEndUpTakingTheSameTime(): void
    {
        $equalizer = new MonotonicTimeEqualizer(new RecordingLogger());

        $fast = self::timeOf(static function () use ($equalizer): void {
            $budget = $equalizer->begin(self::BUDGET_MS);
            $budget->settle();
        });

        $slow = self::timeOf(static function () use ($equalizer): void {
            $budget = $equalizer->begin(self::BUDGET_MS);
            usleep(5_000); // 模拟真实路径那次多出来的 INSERT
            $budget->settle();
        });

        // 没有均衡器的话两者会差 5 ms；有了它，差值应该远小于那个数。
        self::assertLessThan(4.0, abs($fast - $slow), 'The padding did not level the two paths.');
    }

    /**
     * 预算被打穿 = 填充没发生 = 防线静默失效。唯一的信号就是这行 warning，
     * 所以它必须真的被记，且要带上两个数字（否则运维不知道该把预算调到多少）。
     */
    public function testWarnsInsteadOfSleepingWhenTheBudgetIsAlreadyBlown(): void
    {
        $logger = new RecordingLogger();
        $equalizer = new MonotonicTimeEqualizer($logger);

        $budget = $equalizer->begin(1);
        usleep(5_000);

        $elapsedMs = self::timeOf(static fn () => $budget->settle());

        self::assertLessThan(2.0, $elapsedMs, 'An overrun budget must not sleep at all.');
        self::assertCount(1, $logger->records);
        self::assertSame(LogLevel::WARNING, $logger->records[0]['level']);
        self::assertArrayHasKey('budget_ms', $logger->records[0]['context']);
        self::assertArrayHasKey('elapsed_ms', $logger->records[0]['context']);
    }

    /**
     * 重复 settle 会把预算翻倍，反而制造出新的可分耗时 ——
     * 「调了两次的路径比调一次的慢一倍」。所以第二次必须是空操作。
     */
    public function testSettlingTwiceDoesNotSleepAgain(): void
    {
        $equalizer = new MonotonicTimeEqualizer(new RecordingLogger());

        $budget = $equalizer->begin(self::BUDGET_MS);
        $budget->settle();

        self::assertLessThan(2.0, self::timeOf(static fn () => $budget->settle()));
    }

    /**
     * 单调时钟只保证「递增」，不保证从 0 起算 —— 但差值必须为正。
     */
    public function testTheMonotonicSourceMovesForward(): void
    {
        $first = MonotonicTimeEqualizer::nowNanos();
        usleep(1_000);

        self::assertGreaterThan($first, MonotonicTimeEqualizer::nowNanos());
    }

    /**
     * @return float 毫秒
     */
    private static function timeOf(callable $work): float
    {
        $startedAt = MonotonicTimeEqualizer::nowNanos();
        $work();

        return (MonotonicTimeEqualizer::nowNanos() - $startedAt) / self::NANOS_PER_MILLI;
    }
}
