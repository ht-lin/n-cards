<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Console;

use App\Shared\Application\Seed\SeederInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * `bin/console app:seed` —— 本地开发的种子数据入口（§14.1 的 local 环境）。
 *
 * T-003 交付的是**空壳**：命令、契约（{@see SeederInterface}）与收集机制就位，
 * 但一个 seeder 都还没有 —— M0 阶段 src/Module/* 全是空目录，没有任何实体可写。
 * M1 起各模块自行注册实现，本文件不再改动。
 */
#[AsCommand(
    name: 'app:seed',
    description: '写入本地开发用的种子数据（§14.1，仅限 local）',
)]
final class SeedCommand extends Command
{
    /**
     * @param iterable<SeederInterface> $seeders
     */
    public function __construct(
        #[AutowireIterator('app.seeder')]
        private readonly iterable $seeders,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // §14.1：staging 只用合成数据（由部署流水线另行注入），production 绝不造数据。
        // 这道闸放在命令里而不是各 seeder 里 —— 一处拦住，将来新增 seeder 不会漏。
        if ('prod' === $this->environment) {
            $io->error('app:seed 不在 prod 环境运行（§14.1）。');

            return Command::FAILURE;
        }

        $rows = 0;
        $count = 0;

        foreach ($this->seeders as $seeder) {
            $written = $seeder->seed();
            $rows += $written;
            ++$count;
            $io->text(sprintf('  %-16s %d 行', $seeder->name(), $written));
        }

        if (0 === $count) {
            $io->success('0 个 seeder 已注册 —— M0 阶段还没有任何模块提供种子数据。');

            return Command::SUCCESS;
        }

        $io->success(sprintf('%d 个 seeder，共写入 %d 行。', $count, $rows));

        return Command::SUCCESS;
    }
}
