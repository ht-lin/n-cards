<?php

declare(strict_types=1);

namespace App\Module\Identity\Infrastructure\Doctrine;

use App\Module\Identity\Domain\Entity\Device;
use App\Module\Identity\Domain\Repository\DeviceRepositoryInterface;
use App\Shared\Domain\Identity\Uuid;
use App\Shared\Infrastructure\Doctrine\UuidType;
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

    /**
     * ⚠️ 走 DQL 的 `COUNT` 而不是「查出集合再 count()」：登录路径上每次都要跑，
     * 而一个用户理论上可以有任意多台设备（§7.5 没有给设备数设限额）。
     * 把它们全部水合成实体只为了数一个数，是在为一个从不使用的对象图付钱。
     *
     * `userId` 要显式给 DBAL 类型：`Uuid` 是值对象，不给的话 DBAL 会把它
     * 当 string 绑定 —— 而 {@see Uuid} 虽然实现了 `__toString()`，
     * 出来的是带连字符的字面量，与 `UuidType` 存进 PG 的形态不一定一致。
     * 显式给类型让转换只有一处（与 `DoctrineOtpChallengeRepository` 同一口径）。
     */
    public function countActiveForUser(Uuid $userId): int
    {
        $query = $this->entityManager->createQuery(
            \sprintf(
                'SELECT COUNT(d.id) FROM %s d WHERE d.user = :userId AND d.revokedAt IS NULL',
                Device::class,
            ),
        );

        $query->setParameter('userId', $userId, UuidType::NAME);

        return (int) $query->getSingleScalarResult();
    }

    public function listActiveForUser(Uuid $userId): array
    {
        // ⚠️ `revokedAt IS NULL` 与 countActiveForUser() 是**同一个** active 口径。
        // 两处漂了的话，设备管理页会显示 N 行而新设备提醒信按 M 台判断 ——
        // 而那种不一致没有任何测试会红。
        $query = $this->entityManager->createQuery(
            \sprintf(
                'SELECT d FROM %s d WHERE d.user = :userId AND d.revokedAt IS NULL'
                .' ORDER BY d.lastSeenAt DESC, d.id ASC',
                Device::class,
            ),
        );

        $query->setParameter('userId', $userId, UuidType::NAME);

        // `id ASC` 是第二排序键，不是装饰：`last_seen_at` 完全可能相同
        // （同一次登录里注册的设备，或时钟精度之内的两次 touch），
        // 而没有确定序的分页/断言会间歇性地失败 —— 那类失败最难查。
        return $query->getResult();
    }
}
