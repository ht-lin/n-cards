<?php

declare(strict_types=1);

namespace App\Shared\Application\Cleanup;

use App\Shared\Application\Metrics\MetricsInterface;
use App\Shared\Domain\Time\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * 跑一趟每日清理（T-113）。
 *
 * 两个调用方，同一段逻辑：
 *   - {@see RunDailyCleanupHandler} —— scheduler 容器
 *     每天 04:30 触发的那条消息
 *   - {@see \App\Shared\Infrastructure\Console\CleanupCommand} —— 运维手动入口
 *
 * ============================================================================
 * ⚠️ 错误隔离在这里，不在各任务里
 * ============================================================================
 * 一个任务抛异常**不得**影响其余任务。具体的失败场景是已知的：僵尸行删除撞上
 * `cards.owner_id` 的 `ON DELETE RESTRICT`（理论上不可能 —— ADR-0018 的拦截器让
 * `username IS NULL` 的用户拿不到任何能建卡的端点 —— 但真发生时，代价不该是
 * 「otp_challenges 从此再也不清理」，那是一个会静默堆积 PII 的后果）。
 *
 * 放在 runner 而不是让每个任务自己 try/catch，是因为「新增一条清理只需实现接口」
 * 这句话必须成立：一个忘了包 try/catch 的新任务不该有能力拖垮整趟清理。
 *
 * ============================================================================
 * ⚠️ 时钟只读一次
 * ============================================================================
 * 所有任务共用同一个 `$now`。分开读的话，「删了 A 没删 B」会随两次读取之间的
 * 毫秒数漂移 —— 在跨午夜的那一趟里，两个任务的 7 天截止点可能落在不同的日子。
 */
final readonly class CleanupRunner
{
    /**
     * @param iterable<CleanupTaskInterface> $tasks
     */
    public function __construct(
        #[AutowireIterator('app.cleanup_task')]
        private iterable $tasks,
        private ClockInterface $clock,
        private LoggerInterface $logger,
        private MetricsInterface $metrics,
    ) {
    }

    /**
     * @param non-empty-string|null $only 只跑这一个任务（`app:cleanup --task=`）；
     *                                    null 表示全跑
     *
     * @return list<CleanupResult> 与注册顺序一致；`$only` 不匹配任何任务时为空数组，
     *                             由调用方决定那是不是错误（命令当作错误，消息不会传 $only）
     */
    public function run(?string $only = null): array
    {
        $now = $this->clock->now();
        $results = [];

        foreach ($this->tasks as $task) {
            $name = $task->name();

            if (null !== $only && $only !== $name) {
                continue;
            }

            $results[] = $this->runOne($task, $name, $now);
        }

        return $results;
    }

    /**
     * 已注册的任务名，供 `app:cleanup --task=` 报错时提示可选值。
     *
     * @return list<non-empty-string>
     */
    public function taskNames(): array
    {
        $names = [];

        foreach ($this->tasks as $task) {
            $names[] = $task->name();
        }

        return $names;
    }

    /**
     * @param non-empty-string $name
     */
    private function runOne(CleanupTaskInterface $task, string $name, \DateTimeImmutable $now): CleanupResult
    {
        $startedAt = hrtime(true);

        try {
            $rows = $task->run($now);
        } catch (\Throwable $e) {
            $durationMs = $this->elapsedMillis($startedAt);

            // ⚠️ 记 error 而不是重新抛。这一行是这类失败**唯一**的信号 ——
            // §14.4 的告警接上之前（T-405），它是 Loki 里能搜到的东西。
            // 异常本身进 context 而不是拼进 msg：§14.4 要求日志是结构化 JSON。
            $this->logger->error('cleanup task failed', [
                'task' => $name,
                'duration_ms' => $durationMs,
                'exception' => $e,
            ]);

            $this->metrics->counter('cleanup_errors_total', ['task' => $name]);

            return new CleanupResult($name, 0, $e, $durationMs);
        }

        $durationMs = $this->elapsedMillis($startedAt);

        $this->logger->info('cleanup task finished', [
            'task' => $name,
            'rows' => $rows,
            'duration_ms' => $durationMs,
        ]);

        // ⚠️ 两个计数器，不是一个。
        //
        // `cleanup_runs_total` 每趟恒 +1，`cleanup_rows_total` 只在真删了行时才加。
        // 合成一个的话，「清理跑了但没东西可删」（正常，rows=0）与「清理根本没跑」
        // （scheduler 挂了）在指标上完全一样 —— 而后者是本卡唯一需要告警的故障。
        // 另外 MetricsInterface::counter() 的 $by 是 positive-int，0 传不进去。
        $this->metrics->counter('cleanup_runs_total', ['task' => $name]);

        if ($rows > 0) {
            $this->metrics->counter('cleanup_rows_total', ['task' => $name], $rows);
        }

        return new CleanupResult($name, $rows, null, $durationMs);
    }

    /**
     * @return int<0, max>
     */
    private function elapsedMillis(float|int $startedAt): int
    {
        // hrtime(true) 是单调纳秒，不受 NTP 步进影响 —— 与 ClockInterface 的分工
        // 同 MonotonicTimeEqualizer：墙钟用来判「删到哪一天」，单调钟用来量耗时。
        // max(0, …) 不是防御性代码，是给 PHPStan 的 int<0, max> 收窄。
        $elapsedNanos = (int) (hrtime(true) - $startedAt);

        return max(0, intdiv($elapsedNanos, 1_000_000));
    }
}
