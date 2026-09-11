<?php

declare(strict_types=1);

namespace App\Shared\Application\Cleanup;

/**
 * scheduler 容器每天 04:30（Europe/Berlin）派发的那条消息（T-113）。
 *
 * 刻意是空的：「跑哪些任务」由 `app.cleanup_task` 标签决定，不由消息体决定 ——
 * 否则 T-204 / T-402 / T-403 / T-404 每加一条清理都要改这个类和
 * {@see \App\Shared\Infrastructure\Scheduler\DailyMaintenanceSchedule}。
 *
 * 也刻意没有时间戳字段：`$now` 由 {@see CleanupRunner} 在**执行时刻**读一次
 * （见该类注释）。把派发时刻塞进消息体的话，一条因为重投而晚了几分钟的消息
 * 会用一个过去的截止点去删 —— 结果正确但难以解释，而且重投语义会跟着变。
 *
 * ⚠️ 不实现 `\Stringable`：`RecurringMessage::cron()` 只在用「哈希 cron 表达式」
 * （含 `#`）时才要求消息可字符串化，我们用的是固定的 `30 4 * * *`。
 */
final readonly class RunDailyCleanup
{
}
