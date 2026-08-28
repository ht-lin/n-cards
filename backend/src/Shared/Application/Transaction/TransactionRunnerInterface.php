<?php

declare(strict_types=1);

namespace App\Shared\Application\Transaction;

/**
 * 事务边界（§12.2：「Application 层负责编排与**事务边界**」）。
 *
 * ============================================================================
 * ⚠️ 这个纯接口不是装饰，是 §4.2 唯一强制同事务协作的**前提条件**
 * ============================================================================
 * §4.2 的协作点表里有一条：
 *
 * > 解除好友 / 拉黑 → 取消双方全部共享（ADR-14）：`Social` → `Sharing`，
 * > **同步**，经 `Sharing\Application\Port\ShareRevokerInterface::revokeAllBetween()`，
 * > 与好友关系变更在**同一事务**内。
 * > 这是本项目唯一强制要求跨模块同步 + 同事务的协作点。
 *
 * 而 deptrac 里 `Social.Application` 的允许列表**没有** `Framework.Persistence` ——
 * 编排这件事的那一层，按规矩碰不到 `Doctrine\DBAL\Connection`。
 *
 * 也就是说：没有这个接口，§4.2 的这条要求在架构上**无法实现**。要么违反分层，
 * 要么违反同事务。这就是为什么接口在 `Shared\Application`（各模块 Application 层
 * 都能看到）而实现 `Shared\Infrastructure\Doctrine\TransactionalRunner` 在别处。
 *
 * ============================================================================
 * 嵌套语义
 * ============================================================================
 * `config/packages/doctrine.yaml` 已设 `use_savepoints: true`（注释里点名了这个场景）。
 * 于是 `Social` 的外层 run() 开真事务，`Sharing` 的内层 run() 变成 SAVEPOINT，
 * 外层抛异常两边一起回滚 —— 正是 §13.4 必测项 #5 要的
 * 「中途注入异常 → 全部回滚，不出现『好友已解除但共享还在』」。
 *
 * DBAL 4 已彻底移除「静默忽略嵌套事务」模式，所以这个组合天然正确。
 */
interface TransactionRunnerInterface
{
    /**
     * 在一个事务里执行 `$operation`，返回它的返回值。
     *
     * 抛出任何异常 → 回滚并把异常原样向上抛。正常返回 → 提交。
     *
     * ⚠️ 不要在 `$operation` 内部发领域事件 —— 理由见
     * {@see \App\Shared\Application\Bus\EventBusInterface} 的类注释。
     *
     * @template T
     *
     * @param \Closure(): T $operation
     *
     * @return T
     */
    public function run(\Closure $operation): mixed;

    /**
     * 当前是否已经在一个事务里。
     *
     * 用途是给「必须在事务内被调用」的跨模块 Port 加一道便宜的运行时护栏：
     * `ShareRevokerInterface::revokeAllBetween()` 被非事务地调用时可以直接抛，
     * 而不是安静地把 §4.2 的同事务保证漏掉 —— 那种 bug 只会在生产的并发下显形。
     */
    public function isInTransaction(): bool;
}
