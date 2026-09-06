<?php

declare(strict_types=1);

namespace App\Shared\Application\Timing;

/**
 * 一次 {@see TimeEqualizerInterface::begin()} 领到的耗时预算。
 *
 * 做成接口而不是值对象，是因为「还剩多少时间」与「怎么把它睡掉」都属于实现细节：
 * 生产实现用单调时钟 + `usleep`，测试替身只记账不睡（见
 * `tests/Double/Timing/RecordingTimeEqualizer`）。调用方只需要知道
 * 「处理完在返回前调一次 settle()」。
 */
interface TimeBudget
{
    /**
     * 把预算的剩余部分睡掉。
     *
     * ⚠️ **必须在返回响应之前调**，且只在会返回给调用方的那条路径上调 ——
     * 早于分支点抛出的异常（限流 429、Vault 不可达 503）本身不携带
     * 「这个邮箱存不存在」的信息，填充它们只是白白占住 PHP-FPM 的 worker。
     *
     * 重复调用是空操作：第一次就已经把预算耗完了，再睡一次等于把预算翻倍，
     * 那会让耗时**重新**变得可分（调了两次的路径比调一次的慢一倍）。
     */
    public function settle(): void;
}
