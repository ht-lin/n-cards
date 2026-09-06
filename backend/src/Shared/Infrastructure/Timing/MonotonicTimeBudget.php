<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Timing;

use App\Shared\Application\Timing\TimeBudget;
use Psr\Log\LoggerInterface;

/**
 * {@see MonotonicTimeEqualizer} 领出的一份预算。
 */
final class MonotonicTimeBudget implements TimeBudget
{
    private const NANOS_PER_MILLI = 1_000_000;

    private const NANOS_PER_MICRO = 1_000;

    private bool $settled = false;

    /**
     * @param int<1, max> $budgetMillis
     * @param int         $startedAtNanos 单调纳秒计数，来自 {@see MonotonicTimeEqualizer::nowNanos()}
     */
    public function __construct(
        private readonly int $budgetMillis,
        private readonly int $startedAtNanos,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function settle(): void
    {
        // 重复 settle 会把预算翻倍，反而制造出新的可分耗时。见接口注释。
        if ($this->settled) {
            return;
        }

        $this->settled = true;

        $elapsedNanos = MonotonicTimeEqualizer::nowNanos() - $this->startedAtNanos;
        $remainingNanos = $this->budgetMillis * self::NANOS_PER_MILLI - $elapsedNanos;

        if ($remainingNanos <= 0) {
            // ⚠️ 这是**安全告警**，不是性能日志：预算被打穿意味着填充没有发生，
            // 于是 §3.8 的时间侧信道当场重新打开，而功能测试不会有任何症状。
            // 处置是把 ncards.otp.request_budget_ms 按实测 p99 调高，
            // 不是把这行日志调成 debug。
            $this->logger->warning('Constant-time budget overrun; timing padding did not apply.', [
                'budget_ms' => $this->budgetMillis,
                'elapsed_ms' => intdiv($elapsedNanos, self::NANOS_PER_MILLI),
            ]);

            return;
        }

        // usleep 收微秒。不足 1 微秒的余数直接丢掉 —— 那个量级远低于
        // 网络与 PHP-FPM 调度的抖动，补它没有意义。
        usleep(intdiv($remainingNanos, self::NANOS_PER_MICRO));
    }
}
