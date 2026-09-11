<?php

declare(strict_types=1);

namespace App\Shared\Application\Cleanup;

/**
 * {@see RunDailyCleanup} 的消费端（T-113）。
 *
 * ============================================================================
 * ⚠️ 用显式标签注册，**不用** `#[AsMessageHandler]`
 * ============================================================================
 * 那个属性落在 deptrac 的 `Framework.Messaging` 图层，只对 `*.Infrastructure` 开放，
 * 写在 Application 层是 violation。注册在 config/services.yaml 里，与 T-102 的
 * `Notification\Application\SendMailHandler` 逐字同构。
 *
 * 把 Handler 留在 Application 而不是搬去 Infrastructure，理由也与那边相同：
 * 它要计入 Application 层的 85% 行覆盖门槛（tools/coverage-check.php），
 * 而 Infrastructure 不在那个门槛里。
 *
 * ============================================================================
 * ⚠️ 不抛异常
 * ============================================================================
 * {@see CleanupRunner} 已经把每个任务的失败接住并记进日志与
 * `cleanup_errors_total`。在这里把它们重新抛出去的后果是 Messenger 按重投策略
 * **把整趟清理再跑一遍** —— 成功的任务陪着失败的那个一起重跑，而失败的那个
 * （比如撞上外键）重跑一万次也还是同一个结果。
 *
 * 退出码有意义的是 `app:cleanup`（运维手动跑，见 CleanupCommand），不是这里。
 */
final readonly class RunDailyCleanupHandler
{
    public function __construct(private CleanupRunner $runner)
    {
    }

    public function __invoke(RunDailyCleanup $message): void
    {
        $this->runner->run();
    }
}
