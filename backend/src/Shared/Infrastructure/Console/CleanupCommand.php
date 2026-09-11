<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Console;

use App\Shared\Application\Cleanup\CleanupRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * `bin/console app:cleanup` —— 每日清理的**手动**入口（§8.2 / T-113）。
 *
 * 日常由 scheduler 容器按 {@see \App\Shared\Infrastructure\Scheduler\DailyMaintenanceSchedule}
 * 触发；这个命令是给运维的：scheduler 停机跨过 04:30（那一趟不会补跑，见该类注释）、
 * 或者要单独复跑某一条时用。步骤见 docs/runbooks/scheduled-cleanup.md。
 *
 * ============================================================================
 * 与 SeedCommand 的两处差别
 * ============================================================================
 * 1. **没有 prod 闸。** `app:seed` 在 prod 直接拒跑（§14.1：生产绝不造数据），
 *    而清理恰恰**主要**在 prod 跑 —— 它是 §8.2 保留期的执行点。
 * 2. **有退出码语义。** 任何一个任务失败即 `FAILURE`，好让运维脚本与人都能一眼
 *    看出来。消息那一侧相反（{@see \App\Shared\Application\Cleanup\RunDailyCleanupHandler}
 *    不抛），理由见那里。
 */
#[AsCommand(
    name: 'app:cleanup',
    description: '跑一趟数据保留期清理（§8.2；日常由 scheduler 容器自动触发）',
)]
final class CleanupCommand extends Command
{
    public function __construct(private readonly CleanupRunner $runner)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'task',
            null,
            InputOption::VALUE_REQUIRED,
            '只跑这一个任务（名字见 CleanupTaskInterface::name()，例如 identity.dead_otp_challenges）',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var string|null $only */
        $only = $input->getOption('task');

        if (null !== $only && '' === $only) {
            $only = null;
        }

        $results = $this->runner->run($only);

        // ⚠️ 「结果为空」有两种截然不同的原因，必须分开处理。
        //
        // 打错任务名是**错误**：运维在生产上敲 `--task=identity.otp_challenges`
        // （少了 `dead_`）之后看到一行绿色的成功，会以为清理跑过了，
        // 而 §8.2 的保留期那天就是没执行。错误信息里列出可选值，
        // 免得人得去翻源码找名字。
        if ([] === $results && null !== $only) {
            $io->error(\sprintf(
                '没有名为 "%s" 的清理任务。已注册：%s',
                $only,
                implode('、', $this->runner->taskNames()) ?: '（一个都没有）',
            ));

            return Command::FAILURE;
        }

        // 另一种是「一个任务都没注册」，那是成功 —— 与 `app:seed` 的空壳分支
        // 同一个理由（compose 起栈后的第一条命令不该是红的）。
        // 今天走不到：Identity 注册了三个。它是给「将来有人把三个都搬走」留的。
        if ([] === $results) {
            $io->success('0 个清理任务已注册。');

            return Command::SUCCESS;
        }

        $failed = 0;

        foreach ($results as $result) {
            if ($result->failed()) {
                ++$failed;
                $io->text(\sprintf(
                    '  <error>✗</> %-34s %s',
                    $result->task,
                    $result->failure?->getMessage() ?? '未知错误',
                ));

                continue;
            }

            $io->text(\sprintf('  ✓ %-34s %6d 行  %d ms', $result->task, $result->rows, $result->durationMs));
        }

        if ($failed > 0) {
            // 细节（含堆栈）已经由 CleanupRunner 记进日志了，这里只给结论。
            $io->error(\sprintf('%d / %d 个清理任务失败，详见日志。', $failed, \count($results)));

            return Command::FAILURE;
        }

        $io->success(\sprintf(
            '%d 个清理任务，共处理 %d 行。',
            \count($results),
            array_sum(array_map(static fn ($r) => $r->rows, $results)),
        ));

        return Command::SUCCESS;
    }
}
