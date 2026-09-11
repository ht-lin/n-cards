<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Scheduler;

use App\Shared\Application\Cleanup\RunDailyCleanup;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

/**
 * §14.2 的 `scheduler` 容器消费的那张时刻表（T-113，ADR-0022）。
 *
 * `#[AsSchedule]` 的默认名就是 `default`，于是 Symfony 自动注册一个名为
 * `scheduler_default` 的 Messenger receiver（`AddScheduleMessengerPass`），
 * 也就是 infra/compose 里那条
 * `bin/console messenger:consume scheduler_default`。
 * **config/packages/messenger.yaml 里不需要、也不应该再写一条 transport** ——
 * 手写一条同名的会把自动注册的那个覆盖掉。
 *
 * ============================================================================
 * 04:30 Europe/Berlin，不是 UTC，也不是 03:xx
 * ============================================================================
 * §9.2 的计划内维护窗口是**每周二 03:00–04:00 CET**。清理落在窗口里的话，
 * 「周二的清理没跑」与「周二本来就停服」会是同一个现象，而区分它们要靠
 * 翻部署记录。04:30 在窗口之后，且仍在流量最低的时段。
 *
 * 用时区而不是 UTC：夏令时切换时，UTC 的固定时刻会在 03:30 与 04:30 之间跳，
 * 一年两次地跳进维护窗口里。`CronExpressionTrigger` 接时区参数，用它。
 *
 * ============================================================================
 * ⚠️ 刻意没有 `->stateful()`，也没有 `->lock()`
 * ============================================================================
 * 两者分别要一个 PSR-6 缓存池与 `symfony/lock`（后者本仓库没装）。不要它们的
 * 前提是 **scheduler 跑单副本**（`docker-compose.prod.yml` 的 `replicas: 1`，
 * 与紧邻的 worker 的 2 正好相反）与**任务幂等**（一律按截止时刻删，不是增量游标）。
 *
 * 代价写在 ADR-0022 里，这里复述一遍以免被「顺手加上更稳」改掉：
 * scheduler 停机跨过 04:30 的话，**那一天的清理就是没跑过**，不会补跑。
 * 这可以接受，因为第二天那一趟的截止点会把前一天该删的一并删掉 ——
 * 保留期是「不超过 N 天」，晚一天删仍然满足 §8.2，而漏删才不满足。
 *
 * 反过来说：把 `replicas` 改成 2 之前**必须**先加 `->lock()`，否则两个副本会
 * 各自派发这条消息，指标翻倍、日志变两份。
 */
#[AsSchedule]
final class DailyMaintenanceSchedule implements ScheduleProviderInterface
{
    public function getSchedule(): Schedule
    {
        return (new Schedule())->add(
            RecurringMessage::cron(
                '30 4 * * *',
                new RunDailyCleanup(),
                new \DateTimeZone('Europe/Berlin'),
            ),
        );
    }
}
