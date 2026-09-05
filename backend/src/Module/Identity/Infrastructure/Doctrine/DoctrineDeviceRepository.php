<?php

declare(strict_types=1);

namespace App\Module\Identity\Infrastructure\Doctrine;

use App\Module\Identity\Domain\Entity\Device;
use App\Module\Identity\Domain\Repository\DeviceRepositoryInterface;
use App\Shared\Domain\Identity\Uuid;
use Doctrine\ORM\EntityManagerInterface;

/**
 * {@see DeviceRepositoryInterface} 的 Doctrine 实现。
 *
 * `save()` 直接 flush 的分工线见 {@see DoctrineUserRepository} 的类注释。
 */
final readonly class DoctrineDeviceRepository implements DeviceRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function save(Device $device): void
    {
        $this->entityManager->persist($device);
        $this->entityManager->flush();
    }

    public function findById(Uuid $id): ?Device
    {
        return $this->entityManager->find(Device::class, $id);
    }
}
