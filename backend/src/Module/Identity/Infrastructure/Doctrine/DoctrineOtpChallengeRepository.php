<?php

declare(strict_types=1);

namespace App\Module\Identity\Infrastructure\Doctrine;

use App\Module\Identity\Domain\Entity\OtpChallenge;
use App\Module\Identity\Domain\Repository\OtpChallengeRepositoryInterface;
use App\Shared\Domain\Identity\Uuid;
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
}
