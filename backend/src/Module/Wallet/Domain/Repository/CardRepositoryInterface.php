<?php

declare(strict_types=1);

namespace App\Module\Wallet\Domain\Repository;

use App\Module\Wallet\Domain\Entity\Card;
use App\Shared\Domain\Error\DomainException;
use App\Shared\Domain\Identity\Uuid;

/**
 * `cards` 的持久化（§5.2）。
 *
 * ============================================================================
 * ⚠️ 「软删的行不存在」是**这一层**的不变量
 * ============================================================================
 * 除 {@see findIncludingDeleted()} 外，本接口的每个查找方法都**只**返回
 * `deleted_at IS NULL` 的行。没有 Doctrine filter 兜底 —— 这是手写的
 * `WHERE`，实现里每加一个查询就要再写一次。
 *
 * 把它定成接口级的不变量（而不是「调用方记得过滤」）是因为漏掉的症状是
 * **已删除的卡重新出现在钱包里**，而那不会让任何测试变红，除非有人专门写了
 * 那条用例。`DoctrineCardRepositoryTest` 对每个方法各钉了一条。
 *
 * {@see findIncludingDeleted()} 是唯一的例外，它只有一个调用方
 * （`POST /v1/cards` 的幂等判定），理由写在它自己的注释里。
 */
interface CardRepositoryInterface
{
    /**
     * 写一张卡，**不做乐观锁检查**。
     *
     * 用于建卡与软删 —— 两者都没有「基于旧版本的修改」这回事
     * （建卡时还没有旧版本；删除不带任何来自客户端的内容，见
     * {@see \App\Module\Wallet\Application\Card\DeleteCardService} 的类注释）。
     *
     * ⚠️ Doctrine 仍然会因为 `revision` 是 version 字段而在 UPDATE 上带
     * `WHERE revision = :old` —— 那个 `:old` 是**本进程刚读到**的值，
     * 挡的是「读到 flush 之间被别的请求改了」。它与 `If-Match` 无关。
     */
    public function save(Card $card): void;

    /**
     * 写一张卡，并要求它在库里的 `revision` 仍然是 `$expectedRevision`
     * （§5.4.3 的 `If-Match`）。
     *
     * @param int $expectedRevision 客户端 `If-Match` 头里那个
     *
     * @throws DomainException `revision_conflict`（409），`current` 成员带服务端
     *                         当前状态，客户端据此走 §5.4.3 的冲突解决
     */
    public function saveWithRevision(Card $card, int $expectedRevision): void;

    /**
     * 未删除的一张卡。
     */
    public function find(Uuid $id): ?Card;

    /**
     * 一张卡，**包括已软删的**。
     *
     * 只给 `POST /v1/cards` 的幂等判定用（§5.4.3）：那里要回答的问题是
     * 「这个 id 被占用了吗」，而一个软删的 id **仍然是被占用的** ——
     * 主键还在库里，用 {@see find()} 的话第二次提交会撞进 INSERT 然后
     * 抛主键冲突（一个 500），而不是契约写的 `200` / `409 id_conflict`。
     */
    public function findIncludingDeleted(Uuid $id): ?Card;

    /**
     * `GET /v1/cards` 的一页（§6.1 游标分页，**不使用** offset）。
     *
     * 按 `id` 升序 —— UUIDv7 唯一且随时间递增，做 keyset 键正合适，
     * 而且它就是主键，不需要额外的排序索引。
     *
     * @param Uuid      $ownerId 只取这个人**自己拥有**的卡。共享给他的卡要等
     *                           `card_members`（T-110）—— 在那之前两者是同一个集合
     * @param Uuid|null $after   游标位置：只取 `id > $after` 的行；null 表示第一页
     * @param int       $limit   要取的行数。调用方传的是
     *                           `PageRequest::fetchLimit()`（= 页大小 + 1），
     *                           多出来的那一行是 `has_more` 的判据
     *
     * @return list<Card>
     */
    public function findOwnedPage(Uuid $ownerId, ?Uuid $after, int $limit): array;

    /**
     * 这个人**自己拥有**的、未删除的卡数。
     *
     * §7.5 的 `cards_per_user`（500）用它。⚠️ 只数 `owner_id = :user` ——
     * 共享给他的卡**不计入**（§17.5 Q11），否则 owner 可以通过共享消耗别人的配额。
     *
     * ⚠️ 所以实现**不许 join `card_members`**，哪怕看起来「顺手就能把共享卡也算上」。
     * 那个改动在替身层看不出任何区别（`InMemoryCardRepository` 根本不知道成员表存在），
     * 真库上的护栏是 `tests/Integration/Module/Wallet/Doctrine/CardQuotaScopeTest`。
     */
    public function countOwnedBy(Uuid $ownerId): int;
}
