<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Timing;

use App\Shared\Application\Timing\TimeBudget;
use App\Shared\Application\Timing\TimeEqualizerInterface;
use Psr\Log\LoggerInterface;

/**
 * {@see TimeEqualizerInterface} 的生产实现：单调时钟 + `usleep`。
 *
 * ============================================================================
 * ⚠️ 为什么用 hrtime() 而不是 ClockInterface
 * ============================================================================
 * {@see \App\Shared\Domain\Time\ClockInterface} 是**墙钟**。填充要算的是
 * 「从 begin() 到现在过了多久」，墙钟在这段窗口内可能被 NTP 步进
 * （chrony 默认对 < 128 ms 的偏差走 slew、更大的直接 step），
 * 于是算出的已用时间可能为负、也可能凭空多出几百毫秒 ——
 * 前者会让填充睡过头，后者会让填充直接不睡，也就是防线失效。
 *
 * `hrtime(true)` 返回的是单调纳秒计数，不受任何时间调整影响。它不能用来
 * 表达「几点」，但这里要的恰恰只有「过了多久」。
 *
 * 这也是本类必须在 Infrastructure 的原因：`hrtime()` 是环境相关的时间源，
 * 与 `SystemClock` 同一档。
 */
final readonly class MonotonicTimeEqualizer implements TimeEqualizerInterface
{
    public function __construct(private LoggerInterface $logger)
    {
    }

    public function begin(int $budgetMillis): TimeBudget
    {
        return new MonotonicTimeBudget($budgetMillis, self::nowNanos(), $this->logger);
    }

    /**
     * `hrtime(true)` 在 64 位平台上返回 int；PHPStan 只知道它是 `int|float`，
     * 所以在这里收口一次，让调用点拿到确定的类型。
     */
    public static function nowNanos(): int
    {
        return (int) hrtime(true);
    }
}
