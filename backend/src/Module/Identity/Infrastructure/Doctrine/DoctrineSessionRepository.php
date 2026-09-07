<?php

declare(strict_types=1);

namespace App\Module\Identity\Infrastructure\Doctrine;

use App\Module\Identity\Domain\Entity\Session;
use App\Module\Identity\Domain\Repository\SessionRepositoryInterface;
use App\Module\Identity\Domain\ValueObject\SessionRevokedReason;
use App\Shared\Domain\Crypto\HashDigest;
use App\Shared\Domain\Identity\Uuid;
use App\Shared\Infrastructure\Doctrine\HashDigestType;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * {@see SessionRepositoryInterface} 的 Doctrine 实现。
 *
 * `save()` 直接 flush 的分工线见 {@see DoctrineUserRepository} 的类注释。
 */
final readonly class DoctrineSessionRepository implements SessionRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function save(Session $session): void
    {
        $this->entityManager->persist($session);
        $this->entityManager->flush();
    }

    public function findById(Uuid $id): ?Session
    {
        return $this->entityManager->find(Session::class, $id);
    }

    public function findByRefreshTokenHash(HashDigest $refreshTokenHash): ?Session
    {
        // 走 uq_sessions_refresh_token_hash。
        //
        // ⚠️ 返回 null **不等于**令牌无效 —— 见接口上的注释：
        // 一个刚被轮换掉的令牌在这里查不到，但它躺在某一行的 previous_token_hash 里，
        // 而那意味着令牌被窃（§7.1）。拿到 null 之后必须再查一次
        // findByPreviousTokenHash()。
        //
        // ============================================================
        // ⚠️ PESSIMISTIC_WRITE：这一行查出来就是为了改
        // ============================================================
        // 轮换是读-改-写。两个并发刷新拿同一个令牌进来，不加锁的话各自读到
        // 同一行、各自 rotate()，后提交的覆盖先提交的 —— 而先提交的那个
        // 已经把它的新令牌发回给客户端了。那枚令牌从此在库里不存在，
        // 客户端下次刷新会得到 401，且没有任何日志能解释为什么。
        //
        // 用 findOneBy() + LockMode 而不是 DQL：`findOneBy` 不接受锁模式，
        // 所以这里显式写 DQL 并 setLockMode()。
        //
        // ⚠️ FOR UPDATE 要求在事务里。调用方（RefreshTokenService）把整段
        // 读-改-写包在 TransactionRunnerInterface::run() 里 —— 不包的话
        // Doctrine 会抛 TransactionRequiredException，那是**接线错误**，
        // 应该当场炸而不是悄悄退化成无锁。
        $query = $this->entityManager->createQuery(
            \sprintf('SELECT s FROM %s s WHERE s.refreshTokenHash = :hash', Session::class),
        );

        $query->setParameter('hash', $refreshTokenHash, HashDigestType::NAME);
        $query->setLockMode(LockMode::PESSIMISTIC_WRITE);

        return $query->getOneOrNullResult();
    }

    public function findByPreviousTokenHash(HashDigest $previousTokenHash): ?Session
    {
        // ⚠️ `previous_token_hash` 上**没有**唯一索引（只有 current 有
        // uq_sessions_refresh_token_hash），所以理论上可能命中多行。
        // 实践中不会：previous 的值来自某一次 rotate() 里的 current，
        // 而 current 是唯一的 —— 也就是说 previous 继承了那份唯一性。
        //
        // 用 getOneOrNullResult() 而不是 setMaxResults(1)：真出现两行的话
        // 那是一个数据完整性问题（有人手工改过库，或唯一索引丢了），
        // 应该以一个 500 的形式炸出来，而不是随手挑一行继续、
        // 让「撤销了哪条会话」变成不确定的。
        //
        // 这里**不加锁**：调用方拿到它之后走的是撤销路径，
        // 而 Session::revoke() 幂等（重复撤销不重置时间戳、首个 reason 胜出），
        // 所以两个并发重放各撤一次的结果与撤一次相同。
        $query = $this->entityManager->createQuery(
            \sprintf('SELECT s FROM %s s WHERE s.previousTokenHash = :hash', Session::class),
        );

        $query->setParameter('hash', $previousTokenHash, HashDigestType::NAME);

        return $query->getOneOrNullResult();
    }

    public function revokeAllForDevice(
        Uuid $deviceId,
        Uuid $userId,
        SessionRevokedReason $reason,
        \DateTimeImmutable $now,
    ): int {
        // ============================================================
        // ⚠️ 水合成实体逐个 revoke()，**不用**批量 DQL UPDATE
        // ============================================================
        // 批量 UPDATE 看起来更省，但它绕过 Session::revoke()，于是那个方法
        // 承载的两条语义一起消失：
        //
        //   1. **首个 reason 胜出**。一条已因 reuse_detected 被撤销的会话
        //      随后又被远程登出扫到时，留下的必须仍是安全事件那个原因 ——
        //      它是告警与事后调查的依据。靠 WHERE 里的 `revoked_at IS NULL`
        //      也能凑出同样的效果，但那是把一条不变量拆成两处写法，
        //      改任何一处都会悄悄破坏它。
        //   2. **身份映射的一致性**。批量 UPDATE 直接打库，这次请求里
        //      已经水合出来的 Session 实体在内存里仍是「未撤销」的旧状态；
        //      调用方随后 flush 它就会把刚写进去的 revoked_at 冲掉。
        //      ORM 3 拿掉了 `clear($entityName)`，没有便宜的补救。
        //
        // 代价是把这些行水合成对象。可以接受：一台设备上的会话是「在这台机器上
        // 登录过几次」，量级是个位数到几十（refresh 90 天滑动，且同一台设备
        // 重复登录才会多开行）。这与 countActiveForUser() 拒绝水合的理由不冲突 ——
        // 那边是**每次登录**都要跑的计数，这边是一次用户主动操作。
        $sessions = $this->entityManager
            ->getRepository(Session::class)
            ->findBy(['device' => $deviceId, 'user' => $userId, 'revokedAt' => null]);

        foreach ($sessions as $session) {
            $session->revoke($reason, $now);
        }

        $this->entityManager->flush();

        return \count($sessions);
    }
}
