<?php

declare(strict_types=1);

namespace App\Tests\Double\Timing;

use App\Shared\Application\Timing\TimeBudget;
use App\Shared\Application\Timing\TimeEqualizerInterface;

/**
 * 只记账、**不真睡**的耗时均衡器。
 *
 * 单测里用真实现的话，每个用例都要多花一个预算的墙钟时间
 * （150 ms × 几十条 = 几秒），而单测断言的是「有没有 settle」，
 * 不是「睡够了没有」。后者由
 * tests/Unit/Shared/Infrastructure/Timing/MonotonicTimeBudgetTest 单独盯。
 */
final class RecordingTimeEqualizer implements TimeEqualizerInterface
{
    /** @var list<int> 依次记下每次 begin() 领到的预算 */
    private array $budgets = [];

    private int $settleCount = 0;

    public function begin(int $budgetMillis): TimeBudget
    {
        $this->budgets[] = $budgetMillis;

        return new class($this->settleCount) implements TimeBudget {
            public function __construct(private int &$settleCount)
            {
            }

            public function settle(): void
            {
                ++$this->settleCount;
            }
        };
    }

    /**
     * @return list<int>
     */
    public function budgets(): array
    {
        return $this->budgets;
    }

    /**
     * ⚠️ 用例应该断言它**恰好等于**请求次数：漏调等于填充没发生（防线失效），
     * 多调等于把预算翻倍（反而制造出新的可分耗时）。
     */
    public function settleCount(): int
    {
        return $this->settleCount;
    }
}
