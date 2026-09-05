<?php

declare(strict_types=1);

namespace App\Tests\Integration\Support;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * 跑在**真的迁移过**的 Identity 四张表上的集成用例共用的连接、跳过与清理逻辑。
 *
 * ============================================================================
 * 为什么不是 CREATE TEMPORARY TABLE
 * ============================================================================
 * T-004 的 `UuidTypeRoundTripTest` 与 `TransactionalRunnerTest` 用临时表，那对它们是
 * 对的：它们验的是一个 DBAL 类型或事务语义，跟真实表结构无关。
 *
 * 这里不行。T-101 的验收标准是「集成测试断言 `email_hash` 唯一约束与 `username`
 * 唯一」—— 要断言的**就是迁移建出来的那些约束**。在临时表上重建一遍等于在测
 * 测试自己写的 DDL，迁移文件写错了照样绿。
 *
 * 代价是这些用例依赖「库已经迁移到最新」。CI 天然满足：
 * `.github/workflows/backend.yml` 里 `tools/migration-check.sh --require-db`
 * 排在 `phpunit` **之前**。裸机上没跑过迁移时 skip，并把命令打在消息里。
 *
 * ============================================================================
 * 每条用例包一个事务并回滚
 * ============================================================================
 * 表是真的，数据不能留。`use_savepoints: true`（doctrine.yaml）让仓储内部的
 * flush 与这层外围事务安全嵌套。
 */
trait RequiresIdentitySchema
{
    private Connection $connection;

    private EntityManagerInterface $entityManager;

    /**
     * 连库 + 确认迁移跑过 + 开一个会被回滚的事务。用例的 setUp() 调它。
     */
    private function bootIdentitySchema(): void
    {
        self::bootKernel();

        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);

        try {
            $connection->executeQuery('SELECT 1')->free();
        } catch (\Throwable $e) {
            self::markTestSkipped('Postgres 不可达（'.$e->getMessage().'）。');
        }

        $migrated = $connection->fetchOne("SELECT to_regclass('users')");

        if (null === $migrated) {
            self::markTestSkipped(
                'Identity 的表还没建 —— 先跑 `composer migration:check`'
                .'（或 `bin/console --env=test doctrine:migrations:migrate`）。',
            );
        }

        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $this->connection = $connection;
        $this->entityManager = $entityManager;

        $this->connection->beginTransaction();
    }

    /**
     * 回滚那个事务。用例的 tearDown() 调它。
     */
    private function rollbackIdentitySchema(): void
    {
        if (isset($this->connection) && $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        // 回滚之后 EntityManager 里还留着那些已经不存在于库里的托管实体，
        // 下一条用例继承它们会看到幽灵数据。
        if (isset($this->entityManager)) {
            $this->entityManager->clear();
        }
    }
}
