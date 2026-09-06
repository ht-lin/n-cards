<?php

declare(strict_types=1);

namespace App\Module\Identity\Infrastructure\Doctrine;

use App\Module\Identity\Domain\Entity\OtpChallenge;
use App\Module\Identity\Domain\Repository\OtpChallengeRepositoryInterface;
use App\Shared\Domain\Crypto\HashDigest;
use App\Shared\Domain\Identity\Uuid;
use App\Shared\Infrastructure\Doctrine\HashDigestType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;

/**
 * {@see OtpChallengeRepositoryInterface} 的 Doctrine 实现。
 *
 * `save()` 直接 flush 的分工线见 {@see DoctrineUserRepository} 的类注释。
 */
final readonly class DoctrineOtpChallengeRepository implements OtpChallengeRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function save(OtpChallenge $challenge): void
    {
        $this->entityManager->persist($challenge);
        $this->entityManager->flush();
    }

    public function findById(Uuid $id): ?OtpChallenge
    {
        return $this->entityManager->find(OtpChallenge::class, $id);
    }

    /**
     * ============================================================================
     * 为什么是批量 DQL，而不是查出来逐条调 OtpChallenge::consume()
     * ============================================================================
     * 两个理由，第二个是硬的：.
     *
     * 1. 逐条是 N+1：一次 SELECT 加 N 次 UPDATE，而这条路径在每一次
     *    `POST /v1/auth/otp/request` 上都要跑。
     * 2. {@see OtpChallenge::consume()} 对已消费的挑战**会抛** `token_invalid`。
     *    那条不变量是给 T-104 / T-106 的**单条**消费用的（「一次性令牌不得重放」），
     *    批量作废不该经过它 —— 经过的话，任何一条恰好在并发里刚被消费掉的旧挑战
     *    都会让整个 OTP 请求 500。
     *
     * ⚠️ 批量 DQL 绕过 UnitOfWork：已经加载进内存的 OtpChallenge 实体不会同步到
     * 新的 `consumed_at`。本方法的调用方（T-103 的 RequestOtpService）此刻手上
     * 没有任何旧挑战实体，所以无碍；将来若有调用方需要，得自己 `clear()` 或重查。
     *
     * ============================================================================
     * 为什么复用 consumed_at 而不是新加一列 voided_at
     * ============================================================================
     * 「活跃」的判据是 `consumed_at IS NULL AND expires_at > now()`，两种「已死」
     * 状态在读取侧没有任何行为差别 —— T-104 对二者都返回 401。加一列要走 §13.5 的
     * expand–contract 三次发布，换来的只有审计时的可读性，而审计真要区分的话
     * `is_decoy` 与 `attempts` 已经把上下文说清楚了。
     */
    public function invalidateActiveFor(HashDigest $emailHash, \DateTimeImmutable $now): int
    {
        $query = $this->entityManager->createQuery(
            \sprintf(
                'UPDATE %s c SET c.consumedAt = :now'
                .' WHERE c.emailHash = :emailHash AND c.consumedAt IS NULL AND c.expiresAt > :now',
                OtpChallenge::class,
            ),
        );

        // 两个参数都要显式给 DBAL 类型：`emailHash` 是 BYTEA（`hash_digest`），
        // 不给的话 DBAL 会把 HashDigest 对象当 string 绑定并在 __toString 上炸掉
        // —— 而 HashDigest 刻意没有实现 Stringable（见它的类注释）。
        $query->setParameter('emailHash', $emailHash, HashDigestType::NAME);
        $query->setParameter('now', $now, Types::DATETIMETZ_IMMUTABLE);

        return (int) $query->execute();
    }
}
