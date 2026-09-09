<?php

declare(strict_types=1);

namespace App\Module\Sharing\Application\Membership;

use App\Module\Sharing\Application\Dto\CardMembership;
use App\Module\Sharing\Application\Dto\CardMembershipMap;
use App\Module\Sharing\Application\Port\CardMembershipReaderInterface;
use App\Module\Sharing\Application\Port\CardOwnershipRegistrarInterface;
use App\Module\Sharing\Application\Port\CardPlacementWriterInterface;
use App\Module\Sharing\Domain\Entity\CardMember;
use App\Module\Sharing\Domain\Repository\CardMemberRepositoryInterface;
use App\Shared\Application\Transaction\TransactionRunnerInterface;
use App\Shared\Domain\Error\DomainException;
use App\Shared\Domain\Error\ErrorCode;
use App\Shared\Domain\Identity\Uuid;

/**
 * Sharing 对外的三个成员端口的**唯一**实现（T-110）。
 *
 * ============================================================================
 * 三个接口一个类
 * ============================================================================
 * 接口是契约，类是 Sharing 内部的事 —— 外面没有任何代码叫得出这个名字，
 * 它们注入的都是接口。拆成三个类只会得到三份「注入同一个仓储」的样板。
 *
 * 反过来，**接口不能合并成一个**：三个方法的前置条件互相矛盾
 * （registrar 必须在事务内、reader 绝不该在、writer 两者都不要求），
 * 而且 reader 要注进 {@see \App\Module\Wallet\Application\Card\CardViewAssembler}
 * —— 那个类不该拿到任何写能力。理由写在各自的接口注释里。
 *
 * ============================================================================
 * 为什么在 Application 而不是 Infrastructure
 * ============================================================================
 * ADR-0018 给 `UserOnboardingStatus`（第一个由模块实现的 Shared 端口）选
 * Infrastructure 的理由逐字是「它不是编排，是一次持久层查询的适配器，
 * **没有业务分支**」。
 *
 * 本类反过来：{@see registerOwner()} 要构造实体并施加「一卡一 owner」，
 * {@see updatePlacement()} 要先判成员资格再决定是写还是抛。那是编排。
 * 它只依赖 {@see CardMemberRepositoryInterface} 与
 * {@see TransactionRunnerInterface}，不碰任何框架类型 ——
 * `Sharing.Application` 的 deptrac 允许列表里本来也没有 `Framework.Persistence`。
 */
final readonly class CardMembershipService implements CardOwnershipRegistrarInterface, CardMembershipReaderInterface, CardPlacementWriterInterface
{
    public function __construct(
        private CardMemberRepositoryInterface $members,
        private TransactionRunnerInterface $transactions,
    ) {
    }

    public function registerOwner(Uuid $cardId, Uuid $ownerId, \DateTimeImmutable $joinedAt): void
    {
        // ⚠️ 运行时护栏，不是防御性编程。非事务地调用这个方法**没有任何症状**，
        // 直到某天一次部分失败在生产里留下一张没有 owner 行的孤儿卡 ——
        // 而那时已经没有信息能查出它是怎么来的。
        //
        // LogicException 而不是 DomainException：这是调用方的接线错误，
        // 没有任何客户端输入能造出它，也就没有客户端可以分支的 code。
        if (!$this->transactions->isInTransaction()) {
            throw new \LogicException('CardOwnershipRegistrarInterface::registerOwner() must be called inside TransactionRunnerInterface::run(): the card row and its owner member row have to be committed together, or M3 sharing inherits an orphaned card.');
        }

        $this->members->save(CardMember::owner($cardId, $ownerId, $joinedAt));
    }

    public function membershipsFor(Uuid $userId, array $cardIds): CardMembershipMap
    {
        // 空批次由仓储自己短路（DQL 的 `IN ()` 是语法错误），这里不用特判。
        $memberships = [];

        foreach ($this->members->findActiveFor($userId, $cardIds) as $key => $member) {
            $memberships[$key] = self::toDto($member);
        }

        return CardMembershipMap::of($memberships);
    }

    public function updatePlacement(Uuid $cardId, Uuid $userId, int $sortOrder, bool $isPinned): void
    {
        // 一次查询同时当鉴权：查不到活跃成员行 = 他不在这张卡上。
        // 分成「先查权限、再查实体、然后写」会开出一个 TOCTOU 窗口，
        // 而 T-303 的级联撤销恰好会在那个窗口里把这一行改掉。
        $member = $this->members->findActiveFor($userId, [$cardId])[$cardId->toString()] ?? null;

        if (!$member instanceof CardMember) {
            // 契约对这个码的语义是「客户端应据此从本地删除该卡（说明共享已被撤销）」
            // —— 正是这里想要的。**不是** insufficient_role：那个码的含义是
            // 「你在这张卡上，但角色不够」，而 placement 对两种角色都开放，
            // 所以走到这里只可能是「你根本不在这张卡上」。
            // 两个码不许合并，见 DomainException::insufficientRole() 的注释。
            throw new DomainException(ErrorCode::NotAMember, 'You are not a member of this card; drop your local copy.');
        }

        $member->place($sortOrder, $isPinned);

        $this->members->save($member);
    }

    private static function toDto(CardMember $member): CardMembership
    {
        $role = $member->role();

        return new CardMembership(
            $role->value,
            // ⚠️ 在这一侧算完才出去 —— Wallet 不做任何角色比较，见
            // CardMembership 的类注释。
            $role->canEdit(),
            $member->sortOrder(),
            $member->isPinned(),
        );
    }
}
