<?php

declare(strict_types=1);

namespace App\Module\Identity\Infrastructure\Doctrine;

use App\Module\Identity\Domain\Entity\Session;
use App\Module\Identity\Domain\Repository\SessionRepositoryInterface;
use App\Shared\Domain\Crypto\HashDigest;
use App\Shared\Domain\Identity\Uuid;
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
        // 而那意味着令牌被窃（§7.1）。T-105 拿到 null 之后必须再查一次 previous。
        return $this->entityManager
            ->getRepository(Session::class)
            ->findOneBy(['refreshTokenHash' => $refreshTokenHash]);
    }
}
