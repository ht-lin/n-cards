<?php

declare(strict_types=1);

namespace App\Module\Sharing\Domain\Repository;

use App\Module\Sharing\Domain\Entity\CardMember;
use App\Shared\Domain\Error\DomainException;
use App\Shared\Domain\Identity\Uuid;

/**
 * `card_members` 的持久化（§5.2）。
 *
 * 接口在 `Domain/Repository/` 而不是 `Application/Port/`：后者是**跨模块**的
 * 出口（Port 的 deptrac 允许列表刻意不含本模块 Domain），而这个接口收发的是
 * {@see CardMember} 实体，只在 Sharing 内部用。口径同
 * {@see \App\Module\Wallet\Domain\Repository\CardRepositoryInterface}。
 *
 * ============================================================================
 * ⚠️ 「墓碑行不存在」是**这一层**的不变量
 * ============================================================================
 * 本接口的每个查找方法都**只**返回 `left_at IS NULL` 的行。没有 Doctrine filter
 * 兜底 —— 与 `CardRepositoryInterface` 对软删的处理逐字同源：`@Filter` 是全局
 * 开关，一旦开着，将来 T-305 要读历史成员时就得临时关掉再打开，
 * 而「忘了关回去」的症状是**别的**查询开始返回已经失去访问的人。
 *
 * 把它定成接口级的不变量（而不是「调用方记得过滤」）是因为漏掉的症状是
 * **被移除的成员仍然能看到卡** —— 那是 §7.2 的 T20（权限残留），
 * 而它不会让任何测试变红，除非有人专门写了那条用例。
 * `DoctrineCardMemberRepositoryTest` 对每个方法各钉了一条。
 */
interface CardMemberRepositoryInterface
{
    /**
     * 写一行成员记录。
     *
     * ⚠️ **只有这个方法会翻译 `uq_card_single_owner`。** 撞上它意味着这张卡
     * 已经有一个活着的 owner —— 实现把它翻成 `409 id_conflict`，理由见
     * {@see \App\Module\Sharing\Application\Port\CardOwnershipRegistrarInterface}。
     * 其余任何唯一/外键冲突一律原样冒泡成 500。
     *
     * @throws DomainException `id_conflict`（409）
     */
    public function save(CardMember $member): void;

    /**
     * 某个用户在这一批卡上的**活跃**成员关系。
     *
     * ⚠️ **一条查询**，与 `$cardIds` 的长度无关。这是 §5.3「禁止在循环里逐条
     * 查询」在成员表上的对应物，也是 §9.1 的预算要求的
     * （`GET /v1/sync/bootstrap` 200 张卡 P95 ≤ 700 ms）。
     * 别加一个 `findOneFor(cardId, userId)` —— 那会立刻被抄进 foreach。
     * 单卡场景（`GET /v1/cards/{id}`、placement）传一个只有一项的数组。
     *
     * 走 `idx_card_members_user`：复合主键 `(card_id, user_id)` 的前导列是
     * `card_id`，服务不了这条 `user_id` 打头的谓词。
     *
     * `$cardIds` 为空 → 返回空数组且**不查库**。
     *
     * @param list<Uuid> $cardIds 可含重复；结果按 `card_id` 天然去重（复合主键）
     *
     * @return array<string, CardMember> 键 = `cardId->toString()`。
     *                                   不是成员的卡**不出现**，不抛
     */
    public function findActiveFor(Uuid $userId, array $cardIds): array;
}
