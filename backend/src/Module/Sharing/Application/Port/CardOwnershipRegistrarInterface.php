<?php

declare(strict_types=1);

namespace App\Module\Sharing\Application\Port;

use App\Shared\Domain\Error\DomainException;
use App\Shared\Domain\Identity\Uuid;

/**
 * 建卡时登记那一行 `card_members(role='owner')`（§5.2，T-110）。
 *
 * ============================================================================
 * 为什么这件事必须在建卡的**同一个事务**里
 * ============================================================================
 * T-110 的任务卡逐字：「建卡时**同时**写入一行 `card_members(role='owner')`
 * —— 单人钱包阶段也必须有，否则 M3 的共享要回头补数据。」
 *
 * 「回头补数据」是轻描淡写：M3 的三条路径都以「每张卡都有一行 owner 成员记录」
 * 为前提 —— T-304 的邀请要数成员、T-303 的级联撤销要按 owner 找卡、
 * T-201 的 change_log audience 要取成员快照。一张没有 owner 行的卡在那三处
 * 都是静默的错误答案，而不是一个异常。
 *
 * 所以「建了卡但没有 owner 行」这种中间态**不允许落地**，哪怕只有一瞬间。
 *
 * ⚠️ **必须在 {@see \App\Shared\Application\Transaction\TransactionRunnerInterface::run()}
 * 内调用。** 实现用同一个接口的 `isInTransaction()` 做运行时护栏，不满足直接抛
 * `\LogicException` —— 那个方法的注释正是为这种「必须在事务内被调用的跨模块
 * Port」写的（它举的例子是 M3 的 `ShareRevokerInterface`，本接口是第一个用上它的）。
 *
 * 护栏不是防御性编程：非事务地调用它不会有任何症状，直到某天一次
 * 部分失败在生产里留下一张孤儿卡 —— 而那时已经没有信息能查出它是怎么来的。
 *
 * ============================================================================
 * ⚠️ 不校验 `SystemLimit::MembersPerCard`
 * ============================================================================
 * 那个限额（20）管的是**邀请**（T-304：owner + 19 viewer）。owner 是第 1 行，
 * 它永远不可能超限。在建卡路径上加一次 `COUNT(*)` 去证明「1 ≤ 20」，
 * 是在每一次建卡上花一次查询买零信息。
 *
 * ============================================================================
 * 唯一的调用方
 * ============================================================================
 * {@see \App\Module\Wallet\Application\Card\CreateCardService}。
 * 这是 Wallet 第一次同步调用另一个模块 —— §4.2 规则 2 的形状：
 * 只看得见对方的 `Application\Port\*` 与 `Dto`。
 */
interface CardOwnershipRegistrarInterface
{
    /**
     * @param Uuid               $cardId   刚建好的那张卡（客户端生成的 UUIDv7）
     * @param Uuid               $ownerId  建卡者，来自 `AuthContext::$userId`
     * @param \DateTimeImmutable $joinedAt ⚠️ 与 `Card::create()` 用的**同一个**
     *                                     `now()`。这里不读时钟：差几微秒的话，
     *                                     M2 的 change_log 会把同一个逻辑事件
     *                                     排成两件事
     *
     * @throws DomainException `id_conflict`（409）—— 这张卡已经有一个活着的
     *                         owner（撞 `uq_card_single_owner`）。M1 阶段够不着：
     *                         同一事务里 `cards` 的主键会先拦下来
     * @throws \LogicException 不在事务里调用（见类注释）
     */
    public function registerOwner(Uuid $cardId, Uuid $ownerId, \DateTimeImmutable $joinedAt): void;
}
