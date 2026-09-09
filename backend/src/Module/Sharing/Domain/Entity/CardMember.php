<?php

declare(strict_types=1);

namespace App\Module\Sharing\Domain\Entity;

use App\Module\Sharing\Domain\ValueObject\CardRole;
use App\Shared\Domain\Identity\Uuid;

/**
 * `card_members`（§5.2 / §17.1）—— 某个人在某张卡上的成员关系。
 *
 * 类不是 `final`、映射在 XML 里、属性不加 `readonly`，理由见
 * {@see \App\Module\Identity\Domain\Entity\User} 的类注释与 ADR-0011。
 *
 * ============================================================================
 * 这张表属于 **Sharing**，不属于 Wallet
 * ============================================================================
 * §4.2 的模块图逐字把「卡成员（owner/viewer）、共享邀请、退出共享、
 * 好友解除时的级联撤销」划给 Sharing。M3 的三张卡都在这张表上写：
 * T-303 的 `ShareRevokerInterface`（级联撤销写 `left_at`）、T-304 的邀请接受
 * （插 viewer 行）、T-305 的成员管理。归 Wallet 会让那三张卡反过来经 Wallet
 * 的 Port 去写别人的表，Sharing 变成空壳。
 *
 * 代价是 Wallet 读一张卡时要跨模块拿角色与 placement —— 那条缝在
 * {@see \App\Module\Sharing\Application\Port\CardMembershipReaderInterface}，
 * 是**一次批量查询**，不是 N+1。
 *
 * ============================================================================
 * ⚠️ 三个 uuid 列，**没有一个**是 `many-to-one`
 * ============================================================================
 * `cardId` → `cards`、`userId` / `addedBy` → `users`。三条外键**全都跨模块**
 * （Sharing → Wallet、Sharing → Identity ×2），而 deptrac 里
 * `Sharing.Domain: [Shared.Domain]` —— 既看不见 `Wallet.Domain` 也看不见
 * `Identity.Domain`。
 *
 * 库层的三条外键照建（见 `Version20260909110000`），ORM 侧由
 * {@see \App\Shared\Infrastructure\Doctrine\CrossModuleForeignKeys} 补进 schema。
 * 完整论证见 ADR-0019 —— 本类是那条规则的第二个使用者，也是第一个
 * **指向另一个模块业务表**（而不是 `users`）的。
 *
 * ============================================================================
 * ⚠️ 复合主键：本仓库第一个
 * ============================================================================
 * §17.1 给这张表的主键是 `(card_id, user_id)`，**没有代理 id** —— 其余每个实体
 * 都是单列 UUID 主键。XML 里因此有两个 `<id>` 元素，各带
 * `<generator strategy="NONE"/>`。
 *
 * 后果之一：`EntityManager::find()` 要传数组
 * （`['cardId' => …, 'userId' => …]`），别顺手写成单值。
 *
 * ============================================================================
 * `sortOrder` / `isPinned` 是**每成员私有**的，这就是它们在这里的全部理由
 * ============================================================================
 * §5.2：同一张共享卡，Anna 置顶、Bob 不置顶，互不影响。把它们放回 `cards`
 * 等于把共享功能做没了（`Card` 的类注释里也钉了这一条）。
 *
 * ⚠️ 它们**不参与 revision**。`PUT /v1/cards/{id}/placement` 改的是这一行，
 * 不是那张卡 —— 契约逐字要求它不递增卡的 `revision`，也不需要 `If-Match`。
 * 这也是 viewer **唯一**的上行写入端点。
 *
 * ============================================================================
 * `leftAt` 是墓碑，M3 才会重度使用 —— 但现在就要建对
 * ============================================================================
 * 移除成员 / 退出共享 / 好友解除的级联撤销都写它（而不是删行），因为
 * §5.4 要靠这一行给对方下发「你已失去访问」的同步记录。
 *
 * ⚠️ 「活跃成员」恒等于 `leftAt === null`。这条不变量有两个强制点：
 *   - 库层：`uq_card_single_owner` 的谓词里就有它（所以一张卡可以有历史 owner
 *     行，但只能有一个活着的）；`idx_card_members_user` 同样是部分索引。
 *   - 仓储层：每条查询都手写 `left_at IS NULL`，口径同
 *     {@see \App\Module\Wallet\Domain\Repository\CardRepositoryInterface} 对软删的处理。
 *
 * T-110 **不写** `leftAt`：软删一张卡不给 owner 行写墓碑，因为 §5.2 要求
 * 卡墓碑的 audience 取**删除前**的成员快照（「若先删 `card_members` 再算
 * audience，viewer 将永远收不到墓碑」）。见 `DeleteCardService` 的类注释。
 */
class CardMember
{
    private int $sortOrder = 0;

    private bool $isPinned = false;

    private ?Uuid $addedBy = null;

    private ?\DateTimeImmutable $leftAt = null;

    /**
     * 属性不加 `readonly` 的理由见 {@see \App\Module\Identity\Domain\Entity\User::__construct()}。
     */
    private function __construct(
        private Uuid $cardId,
        private Uuid $userId,
        private CardRole $role,
        private \DateTimeImmutable $joinedAt,
    ) {
    }

    /**
     * 建卡时的那一行（T-110）。
     *
     * ⚠️ `$joinedAt` 由调用方传进来，**不是**这里读时钟：`CreateCardService`
     * 用同一个 `now()` 建卡与建这一行，两个时间戳必须逐字相同 ——
     * 差几微秒的话，M2 的 change_log 会把同一个逻辑事件排成两件事。
     *
     * ⚠️ **没有** `addedBy`：owner 不是被谁加进来的。§17.1 那一列是可空的，
     * 正是为了这一格。
     */
    public static function owner(Uuid $cardId, Uuid $ownerId, \DateTimeImmutable $joinedAt): self
    {
        return new self($cardId, $ownerId, CardRole::Owner, $joinedAt);
    }

    /**
     * 接受共享邀请时的那一行（T-304）。
     *
     * 现在就建好是因为「viewer 行长什么样」是这张表的一半语义，而
     * {@see owner()} 单独存在会让人以为 `role` 是可以随手传的参数 ——
     * 它不是：`owner` 那一行受 `uq_card_single_owner` 约束，viewer 不受。
     *
     * ⚠️ `$addedBy` 是**邀请人**（必然是 owner，因为只有 owner 能邀请）。
     * 它是审计线索，不是权限来源 —— 别拿它判断任何东西。
     */
    public static function viewer(Uuid $cardId, Uuid $userId, Uuid $addedBy, \DateTimeImmutable $joinedAt): self
    {
        $member = new self($cardId, $userId, CardRole::Viewer, $joinedAt);
        $member->addedBy = $addedBy;

        return $member;
    }

    public function cardId(): Uuid
    {
        return $this->cardId;
    }

    public function userId(): Uuid
    {
        return $this->userId;
    }

    public function role(): CardRole
    {
        return $this->role;
    }

    public function isOwner(): bool
    {
        return CardRole::Owner === $this->role;
    }

    public function sortOrder(): int
    {
        return $this->sortOrder;
    }

    public function isPinned(): bool
    {
        return $this->isPinned;
    }

    public function addedBy(): ?Uuid
    {
        return $this->addedBy;
    }

    public function joinedAt(): \DateTimeImmutable
    {
        return $this->joinedAt;
    }

    public function leftAt(): ?\DateTimeImmutable
    {
        return $this->leftAt;
    }

    /** 「活跃成员」的**唯一**判据。见类注释。 */
    public function isActive(): bool
    {
        return null === $this->leftAt;
    }

    /**
     * `PUT /v1/cards/{id}/placement`（§6.2，T-110）。
     *
     * ⚠️ 这里**不**手写「值相同就 return」那道短路（`Card` 的 mutator 都有一道）。
     * 那些方法需要它是因为它们还要写 `updatedAt` —— 不短路的话一个没改任何
     * 东西的 `PATCH` 也会推进时间戳并递增 `revision`。本类没有那两个字段，
     * 而「值相同 → 不发 UPDATE」由 Doctrine 的 UnitOfWork 对着载入时的快照
     * 算 changeset 时自然做到。加一道手写的短路只是把同一件事写两遍。
     *
     * ⚠️ **不动卡的 `revision`**，本类根本够不着它。契约逐字要求 placement
     * 不递增 revision —— 那是结构性成立的，不是靠记得别写那一行。
     *
     * ⚠️ `$sortOrder` 的范围校验**不在这里**：那一列是 `INTEGER`，越界是一个
     * 400 而不是一个不变量违例，强制点在
     * {@see \App\Module\Wallet\Application\Card\CardPlacementPayload}。
     * 领域层收到的已经是合法值。
     */
    public function place(int $sortOrder, bool $isPinned): void
    {
        $this->sortOrder = $sortOrder;
        $this->isPinned = $isPinned;
    }

    /**
     * 立墓碑：这个人不再是这张卡的成员（§5.2）。
     *
     * ============================================================================
     * T-110 建好它，M3 才有调用方
     * ============================================================================
     * 任务卡逐字：「`left_at` 是成员墓碑字段，M3 会重度使用，**此处先建好**」。
     * 三条路径会调它，全在 M3：
     *
     *   - T-305 移除成员 / viewer 自行退出共享；
     *   - T-303 解除好友或拉黑 → 同事务级联撤销双方全部共享
     *     （`UPDATE card_members SET left_at = now() …`，那是 §7.2 T20
     *     「权限残留」的唯一防线）。
     *
     * ⚠️ **写墓碑而不是删行**，这不是软删的习惯问题：§5.4 要靠这一行给对方
     * 下发「你已失去访问」的同步记录（T-201 的 change_log）。直接 DELETE
     * 的话，被移除的那个人的客户端永远收不到信号，那张卡会**永久留在他的
     * 本地库里** —— 一个删不掉、也刷新不掉的幽灵。
     *
     * ⚠️ owner 行也可以立墓碑（`uq_card_single_owner` 的谓词里带
     * `left_at IS NULL`，正是为了让一张卡能有历史 owner 行）。但 M1/M3 都不会
     * 这么做：owner 没有「退出」这个概念，只能删卡（§5.2 的角色矩阵）。
     *
     * 幂等：已经立过的不重置 —— 重置会把「什么时候失去访问」这个事实改掉，
     * 而那是 T-201 的 audience 计算要用的。口径同 Wallet 的 `Card::softDelete()`
     * （那边是 `deleted_at`，同一个墓碑模式；这里不写 `{@see}` 是因为
     * `Sharing.Domain` 看不见 `Wallet.Domain`，一个跨模块的类引用哪怕只在
     * 注释里也该避免）。
     */
    public function leave(\DateTimeImmutable $at): void
    {
        if (null !== $this->leftAt) {
            return;
        }

        $this->leftAt = $at;
    }
}
