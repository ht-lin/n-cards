<?php

declare(strict_types=1);

namespace App\Module\Sharing\Infrastructure\Doctrine;

use App\Module\Sharing\Domain\Entity\CardMember;
use App\Module\Sharing\Domain\Repository\CardMemberRepositoryInterface;
use App\Shared\Domain\Error\DomainException;
use App\Shared\Domain\Error\ErrorCode;
use App\Shared\Domain\Identity\Uuid;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * {@see CardMemberRepositoryInterface} 的 Doctrine 实现。
 *
 * `save()` 直接 flush 的理由见
 * {@see \App\Module\Identity\Infrastructure\Doctrine\DoctrineUserRepository} 的类注释。
 * 建卡那条路径上它与 `DoctrineCardRepository::save()` 同处一个
 * `TransactionRunnerInterface::run()` 里，所以两次 flush 是一次提交。
 *
 * ============================================================================
 * ⚠️ 每一条查询都带 `left_at IS NULL`
 * ============================================================================
 * 与 `DoctrineCardRepository` 对软删的处理逐字同源，理由也一样：没有 Doctrine
 * filter 兜底，因为 `@Filter` 是全局开关，而「忘了关回去」的症状是别的查询
 * 开始返回墓碑行。这里漏掉的后果比那边更重 —— 被移除的成员仍然能看到卡，
 * 那是 §7.2 的 T20（权限残留）。
 */
final readonly class DoctrineCardMemberRepository implements CardMemberRepositoryInterface
{
    /**
     * 与迁移里的 `CREATE UNIQUE INDEX` 及 `CardMember.orm.xml` 里的
     * `<unique-constraint name="…">` **逐字相同**。PG 会把索引名放进违例消息里
     * （`duplicate key value violates unique constraint "uq_card_single_owner"`），
     * 这个匹配靠的就是那个名字。
     */
    private const OWNER_CONSTRAINT = 'uq_card_single_owner';

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function save(CardMember $member): void
    {
        try {
            $this->entityManager->persist($member);
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException $e) {
            // ⚠️ **只翻译 `uq_card_single_owner`。** 复合主键冲突（同一个人被加
            // 进同一张卡两次）是另一回事，它该原样冒泡成 500 —— 那是调用方的
            // bug，不是客户端能修的东西。口径同
            // `DoctrineUserRepository::saveNewUsername()` 只翻 `uq_users_username`。
            if (!str_contains($e->getMessage(), self::OWNER_CONSTRAINT)) {
                throw $e;
            }

            // 这张卡已经有一个活着的 owner，而且不是这次要写的这一行。
            //
            // ⚠️ 这个 throw 发生在 `TransactionRunnerInterface::run()` **内部**
            // （见 CreateCardService），所以 `cards` 的那条 INSERT 会跟着回滚 ——
            // 一张 owner 行输掉竞态的卡正是 T-110 存在要拦的那种孤儿。
            //
            // ⚠️ M1 阶段这一支是**防御性**的：两个并发的同 id `POST /v1/cards`
            // 会先撞 `cards` 的主键（那次 INSERT 在同一事务里排在前面）。
            // 它要到 M3 有第二个写入者（T-304 的邀请接受）时才真正承重。
            throw new DomainException(ErrorCode::IdConflict, 'The supplied id already belongs to another user; generate a new one and retry.', [], [], $e);
        }
    }

    public function findActiveFor(Uuid $userId, array $cardIds): array
    {
        // 空批次不查库（接口约定）。DQL 的 `IN ()` 在空数组上是一条语法错误的
        // SQL，所以这不是优化而是必需 —— 与 `CardViewAssembler` 对空批次
        // 不打 Vault 是同一类特判。
        if ([] === $cardIds) {
            return [];
        }

        /** @var list<CardMember> $members */
        $members = $this->entityManager
            ->createQuery(
                'SELECT m FROM '.CardMember::class.' m'
                .' WHERE m.userId = :userId AND m.cardId IN (:cardIds) AND m.leftAt IS NULL',
            )
            ->setParameter('userId', $userId)
            ->setParameter('cardIds', $cardIds)
            ->getResult();

        $byCardId = [];

        foreach ($members as $member) {
            $byCardId[$member->cardId()->toString()] = $member;
        }

        return $byCardId;
    }
}
