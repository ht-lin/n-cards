<?php

declare(strict_types=1);

namespace App\Module\Wallet\Infrastructure\Doctrine;

use App\Module\Wallet\Domain\Entity\Card;
use App\Module\Wallet\Domain\Repository\CardRepositoryInterface;
use App\Shared\Domain\Error\DomainException;
use App\Shared\Domain\Error\ErrorCode;
use App\Shared\Domain\Identity\Uuid;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;

/**
 * {@see CardRepositoryInterface} 的 Doctrine 实现。
 *
 * `save()` 直接 flush 的理由见
 * {@see \App\Module\Identity\Infrastructure\Doctrine\DoctrineUserRepository} 的类注释。
 *
 * ⚠️ 本类**不做任何加解密**：`Ciphertext` 与 `HashDigest` 是 Application 层
 * 算好之后传进实体的。§5.3 的批量解密必须编排在 Application 层，
 * 塞进仓储就没法批了（见 `CryptoServiceInterface` 的类注释）。
 *
 * ============================================================================
 * ⚠️ 每一条查询都带 `deleted_at IS NULL`
 * ============================================================================
 * 没有 Doctrine filter 兜底 —— 那是刻意的：`@Filter` 是全局开关，
 * 一旦开着，`findIncludingDeleted()` 就得临时关掉它再打开，
 * 而「忘了关回去」的症状是**别的**查询开始返回软删的行。
 * 手写 `WHERE` 的代价是每加一个查询要再写一次，收益是每条查询的行为
 * 都写在它自己那几行里。`DoctrineCardRepositoryTest` 对每个方法各钉了一条。
 */
final readonly class DoctrineCardRepository implements CardRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function save(Card $card): void
    {
        try {
            $this->entityManager->persist($card);
            $this->entityManager->flush();
        } catch (OptimisticLockException $e) {
            // 这一支不是 If-Match 失败，而是「本进程读到这张卡之后、flush 之前，
            // 另一个请求改了它」。对调用方（建卡 / 软删）来说结果一样：
            // 客户端该重读再重试，而 409 revision_conflict 正是那个信号。
            throw $this->conflict($card, $e);
        }
    }

    public function saveWithRevision(Card $card, int $expectedRevision): void
    {
        try {
            // 第一层：客户端给的 revision 与**载入时**的不一致，当场抛，
            // 连 UPDATE 都不发。绝大多数冲突走这一支。
            $this->entityManager->lock($card, LockMode::OPTIMISTIC, $expectedRevision);

            $this->entityManager->flush();
        } catch (OptimisticLockException $e) {
            // 第二层：载入时是对的，但 flush 时 `WHERE revision = :old` 命中 0 行
            // —— 有人在这两步之间抢先改了。Doctrine 自动加的那个 WHERE 是
            // 唯一挡得住这一种的东西，所以本仓储没有手写的 revision 比较
            // （见 UpdateCardService 的类注释）。
            throw $this->conflict($card, $e);
        }
    }

    public function find(Uuid $id): ?Card
    {
        return $this->entityManager
            ->getRepository(Card::class)
            ->findOneBy(['id' => $id, 'deletedAt' => null]);
    }

    public function findIncludingDeleted(Uuid $id): ?Card
    {
        // 唯一一个不过滤软删的查询。为什么它必须存在，见接口上的注释。
        return $this->entityManager->find(Card::class, $id);
    }

    public function findOwnedPage(Uuid $ownerId, ?Uuid $after, int $limit): array
    {
        $qb = $this->entityManager
            ->getRepository(Card::class)
            ->createQueryBuilder('c')
            ->where('c.ownerId = :owner')
            ->andWhere('c.deletedAt IS NULL')
            ->setParameter('owner', $ownerId)
            // keyset 分页（§6.1：不使用 offset）。`id` 是 UUIDv7 主键 ——
            // 唯一、单调、且排序走的就是主键索引。
            ->orderBy('c.id', 'ASC')
            ->setMaxResults($limit);

        if (null !== $after) {
            // 严格大于：游标指向**上一页保留下来的最后一行**，它已经发过了。
            // 写成 `>=` 的话每一页都会重复一条记录。
            $qb->andWhere('c.id > :after')->setParameter('after', $after);
        }

        /* @var list<Card> */
        return $qb->getQuery()->getResult();
    }

    public function countOwnedBy(Uuid $ownerId): int
    {
        $count = $this->entityManager
            ->getRepository(Card::class)
            ->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->where('c.ownerId = :owner')
            // ⚠️ 软删的卡**不计入**额度。不加这一条的话，一个反复建删卡的用户
            // 会在 90 天内被自己的墓碑挤满配额。
            ->andWhere('c.deletedAt IS NULL')
            ->setParameter('owner', $ownerId)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count;
    }

    /**
     * §5.4.3 要求 409 的 problem body 里带服务端**当前**状态，客户端据此做三方合并。
     *
     * ⚠️ 这里读的是**内存里**那个实体的值，不是重新查库的。对第一层（lock 失败）
     * 来说它就是库里的值；对第二层来说它可能已经落后了 —— 但重新查一次
     * 也只是把窗口缩小，不能消除它。客户端拿到 409 之后本来就要重读一次
     * （§5.4.3 的冲突解决第一步），所以这里给的是「你手里的不是最新」这个信号，
     * 不承诺 `current` 就是此刻的最新值。
     */
    private function conflict(Card $card, OptimisticLockException $previous): DomainException
    {
        return new DomainException(
            ErrorCode::RevisionConflict,
            'The card was modified by someone else; re-read it and merge.',
            [],
            [
                'revision' => $card->revision(),
                'updated_at' => $card->updatedAt()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            ],
            $previous,
        );
    }
}
