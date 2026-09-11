<?php

declare(strict_types=1);

namespace App\Shared\Application\Cleanup;

/**
 * 一条清理任务跑完之后的结果（T-113）。
 *
 * 存在的理由是 {@see CleanupRunner} 要把「跑完了」与「炸了」一起交回调用方：
 * `app:cleanup` 靠它决定退出码，单测靠它断言「一个任务炸了，其余的照跑」。
 * 用返回值而不是让 runner 自己抛 —— 抛出去的话第一个失败的任务会终止整趟清理，
 * 而那正是本类要避免的事。
 */
final readonly class CleanupResult
{
    /**
     * @param non-empty-string $task       任务名（{@see CleanupTaskInterface::name()}）
     * @param int<0, max>      $rows       处理的行数；失败时为 0
     * @param \Throwable|null  $failure    非 null 即失败
     * @param int<0, max>      $durationMs 墙钟耗时，进日志的 `duration_ms`（§14.4）
     */
    public function __construct(
        public string $task,
        public int $rows,
        public ?\Throwable $failure,
        public int $durationMs,
    ) {
    }

    public function failed(): bool
    {
        return null !== $this->failure;
    }
}
