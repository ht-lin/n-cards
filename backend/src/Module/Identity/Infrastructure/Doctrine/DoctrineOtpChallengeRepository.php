<?php

declare(strict_types=1);

namespace App\Module\Identity\Infrastructure\Doctrine;

use App\Module\Identity\Domain\Entity\OtpChallenge;
use App\Module\Identity\Domain\Repository\OtpChallengeRepositoryInterface;
use App\Shared\Domain\Crypto\HashDigest;
use App\Shared\Domain\Identity\Uuid;
use App\Shared\Infrastructure\Doctrine\HashDigestType;
use Doctrine\DBAL\LockMode;
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

    public function findByMagicTokenHash(HashDigest $magicTokenHash): ?OtpChallenge
    {
        // 走 uq_otp_challenges_magic_token_hash（部分唯一索引，
        // `WHERE magic_token_hash IS NOT NULL` —— 绝大多数行在这一列上是 NULL）。
        //
        // ============================================================
        // ⚠️ PESSIMISTIC_WRITE：这一行查出来就是为了改
        // ============================================================
        // 消费是读-改-写。两个并发 POST 拿同一个令牌进来，不加锁的话各自读到
        // consumed_at IS NULL、各自通过 OtpChallenge::consume() 的判空、
        // 各自 UPDATE 同一行 —— **一个令牌换到两个会话**，而库里只留下一条
        // 看起来完全正常的记录。这与 {@see DoctrineSessionRepository::findByRefreshTokenHash()}
        // 是同一个坑。
        //
        // 用 DQL + setLockMode() 而不是 findOneBy()：后者不接受锁模式。
        //
        // ⚠️ FOR UPDATE 要求在事务里。调用方
        // （{@see \App\Module\Identity\Application\Magic\ConsumeMagicLinkService::consume()}）
        // 把查询与消费一起包在 TransactionRunnerInterface::run() 里 —— 不包的话
        // Doctrine 会抛 TransactionRequiredException，那是**接线错误**，
        // 应该当场炸而不是悄悄退化成无锁。
        $query = $this->entityManager->createQuery(
            \sprintf('SELECT c FROM %s c WHERE c.magicTokenHash = :hash', OtpChallenge::class),
        );

        // 显式给 DBAL 类型，理由同 invalidateActiveFor()：HashDigest 刻意没有
        // 实现 Stringable，不给类型的话 DBAL 会在 __toString 上炸掉。
        $query->setParameter('hash', $magicTokenHash, HashDigestType::NAME);
        $query->setLockMode(LockMode::PESSIMISTIC_WRITE);

        return $query->getOneOrNullResult();
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

    /**
     * 两个条件是 `OR` 而不是「先判死、再判时间」：一条挑战可能因为**被消费**而死
     * （`consumed_at`），也可能因为**过期**而死（`expires_at`），两条时间线各自
     * 与 `$cutoff` 比。
     *
     * 今天 `expires_at = created_at + 10min`，所以第一个条件几乎总是那个生效的；
     * 第二个条件在「宽限期短于 10 分钟」时才单独有意义。写全是因为 OR 的两半
     * 各自对应 {@see OtpChallenge} 上一个独立的状态位，靠「今天 TTL 是 10 分钟」
     * 化简掉的话，改 TTL 会静默改掉清理语义。
     */
    public function deleteDeadBefore(\DateTimeImmutable $cutoff): int
    {
        $query = $this->entityManager->createQuery(
            \sprintf(
                'DELETE FROM %s c'
                .' WHERE c.expiresAt < :cutoff'
                .' OR (c.consumedAt IS NOT NULL AND c.consumedAt < :cutoff)',
                OtpChallenge::class,
            ),
        );

        $query->setParameter('cutoff', $cutoff, Types::DATETIMETZ_IMMUTABLE);

        // `execute()` 在 ORM 的类型声明里是 mixed，而受影响行数按定义非负 ——
        // max() 是把这条事实告诉 PHPStan（接口声明的是 int<0, max>），
        // 不是防御性代码。上面 invalidateActiveFor() 的返回类型是宽的 int，
        // 所以那里不需要这一步。
        return max(0, (int) $query->execute());
    }

    /**
     * ⚠️ `request_ip_hash IS NOT NULL` 这一半不能省：少了它，这个方法会把每一条
     * 够老的挑战都取出来，调用方逐条 `forgetRequestIp()` + `save()` 写一遍 ——
     * 把一个恒空的查询变成一趟对全表的无意义 UPDATE，而返回的「处理行数」
     * 也会开始撒谎（那些行本来就没有 IP 可忘）。
     *
     * 排序按 `created_at` 升序：`$limit` 截断时先处理最老的那批，
     * 于是「今天没处理完，明天接着」不会让某些行永远排在后面。
     */
    public function findWithRequestIpOlderThan(\DateTimeImmutable $cutoff, int $limit): array
    {
        $query = $this->entityManager->createQuery(
            \sprintf(
                'SELECT c FROM %s c'
                .' WHERE c.requestIpHash IS NOT NULL AND c.createdAt < :cutoff'
                .' ORDER BY c.createdAt ASC',
                OtpChallenge::class,
            ),
        );

        $query->setParameter('cutoff', $cutoff, Types::DATETIMETZ_IMMUTABLE);
        $query->setMaxResults($limit);

        /* @var list<OtpChallenge> */
        return $query->getResult();
    }
}
