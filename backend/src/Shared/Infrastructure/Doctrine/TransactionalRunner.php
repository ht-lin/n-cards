<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine;

use App\Shared\Application\Transaction\TransactionRunnerInterface;
use Doctrine\DBAL\Connection;

/**
 * {@see TransactionRunnerInterface} 的 Doctrine 实现。
 *
 * 实现在 Infrastructure、接口在 Application，这个拆分是 §4.2 那条唯一强制
 * 同事务的跨模块协作（Social 解除好友 → Sharing 级联撤销）**能够成立的前提** ——
 * 完整论证见接口的类注释，不在这里重复。
 *
 * `config/packages/doctrine.yaml` 已设 `use_savepoints: true`，所以嵌套的 run()
 * 会变成 SAVEPOINT 而不是被静默忽略（DBAL 4 已彻底移除后一种模式）。
 */
final readonly class TransactionalRunner implements TransactionRunnerInterface
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * @template T
     *
     * @param \Closure(): T $operation
     *
     * @return T
     */
    public function run(\Closure $operation): mixed
    {
        // 包一层 static fn 而不是直接把 $operation 交出去：
        // Connection::transactional() 会把 Connection 自身作为参数传给回调，
        // 而我们的契约是「无参闭包」—— 不包的话，一个声明了首个参数的闭包
        // 会意外收到 Connection，把 Doctrine 泄回 Application 层。
        return $this->connection->transactional(static fn (): mixed => $operation());
    }

    public function isInTransaction(): bool
    {
        return $this->connection->isTransactionActive();
    }
}
