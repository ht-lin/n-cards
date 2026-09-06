<?php

declare(strict_types=1);

namespace App\Tests\Integration\Module\Identity\Doctrine;

use App\Module\Identity\Domain\Entity\Device;
use App\Module\Identity\Domain\Entity\User;
use App\Module\Identity\Domain\Repository\DeviceRepositoryInterface;
use App\Module\Identity\Infrastructure\Doctrine\DoctrineDeviceRepository;
use App\Module\Identity\Infrastructure\Doctrine\DoctrineUserRepository;
use App\Tests\Double\Identity\IdentityEntities;
use App\Tests\Integration\Support\RequiresIdentitySchema;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * `devices` 经 ORM 的真实往返，重点是 T-104 新加的
 * {@see DeviceRepositoryInterface::countActiveForUser()}。
 *
 * ============================================================================
 * 为什么这条要打真库
 * ============================================================================
 * 它是一条带两个条件的 DQL COUNT（`d.user = :userId AND d.revokedAt IS NULL`），
 * 而 `:userId` 是一个值对象，必须以 `uuid` 类型绑定。绑错的话不会报错，
 * 而是**恒返回 0** —— 于是「第二台设备该发提醒信」这条永远不成立，
 * §7.2 T02（邮箱被接管 → 账号被接管）唯一的用户侧信号静默消失。
 *
 * 进程内替身按 PHP 逻辑遍历，验的是「我以为的语义」，看不见这一层。
 */
#[CoversClass(DoctrineDeviceRepository::class)]
final class DoctrineDeviceRepositoryTest extends KernelTestCase
{
    use RequiresIdentitySchema;

    private DeviceRepositoryInterface $repository;

    private DoctrineUserRepository $users;

    /**
     * 直接 `new` 而不从容器取，理由见 {@see DoctrineUserRepositoryTest} 的 setUp 注释。
     */
    protected function setUp(): void
    {
        $this->bootIdentitySchema();

        $this->repository = new DoctrineDeviceRepository($this->entityManager);
        $this->users = new DoctrineUserRepository($this->entityManager);
    }

    protected function tearDown(): void
    {
        $this->rollbackIdentitySchema();

        parent::tearDown();
    }

    public function testRoundTripsADevice(): void
    {
        $device = IdentityEntities::device($this->user('anna'), IdentityEntities::id(41));

        $this->repository->save($device);
        $this->entityManager->clear();

        $loaded = $this->repository->findById($device->id());

        self::assertNotNull($loaded);
        self::assertSame('Pixel 6a', $loaded->model());
        self::assertFalse($loaded->isRevoked());
    }

    public function testCountsOnlyThisUsersDevices(): void
    {
        $anna = $this->user('anna');
        $ben = $this->user('ben', 2);

        $this->repository->save(IdentityEntities::device($anna, IdentityEntities::id(42)));
        $this->repository->save(IdentityEntities::device($anna, IdentityEntities::id(43)));
        $this->repository->save(IdentityEntities::device($ben, IdentityEntities::id(44)));

        self::assertSame(2, $this->repository->countActiveForUser($anna->id()));
        self::assertSame(1, $this->repository->countActiveForUser($ben->id()));
    }

    /**
     * ⚠️ 已撤销的**不计入**：用户远程登出了旧手机、只剩新手机，
     * 那么下一次在第三台设备上登录仍然应该收到提醒信。
     */
    public function testDoesNotCountRevokedDevices(): void
    {
        $anna = $this->user('anna');

        $active = IdentityEntities::device($anna, IdentityEntities::id(45));
        $revoked = IdentityEntities::device($anna, IdentityEntities::id(46));
        $revoked->revoke(IdentityEntities::now());

        $this->repository->save($active);
        $this->repository->save($revoked);
        $this->entityManager->clear();

        self::assertSame(1, $this->repository->countActiveForUser($anna->id()));
    }

    /**
     * 一台设备都没有时返回 0 而不是抛 —— 这是**注册后第一次登录**的常态路径。
     */
    public function testReturnsZeroForAUserWithoutDevices(): void
    {
        self::assertSame(0, $this->repository->countActiveForUser($this->user('anna')->id()));
    }

    /**
     * {@see Device::reactivate()} 在库里也要真的把 `revoked_at` 清掉 ——
     * 只在内存里清的话，下一次登录仍然会把这台设备算成「已撤销」，
     * 于是提醒信每次都发（噪声），而设备管理页上它一直显示为已登出。
     */
    public function testReactivatingADeviceClearsRevokedAtInTheDatabase(): void
    {
        $anna = $this->user('anna');
        $device = IdentityEntities::device($anna, IdentityEntities::id(47));
        $device->revoke(IdentityEntities::now());

        $this->repository->save($device);

        $device->reactivate(IdentityEntities::now()->modify('+1 hour'));
        $this->repository->save($device);
        $this->entityManager->clear();

        $loaded = $this->repository->findById($device->id());

        self::assertNotNull($loaded);
        self::assertFalse($loaded->isRevoked());
        self::assertNull($loaded->revokedAt());
        self::assertSame(1, $this->repository->countActiveForUser($anna->id()));
    }

    private function user(string $seed, int $nth = 1): User
    {
        $user = IdentityEntities::user(
            id: IdentityEntities::id($nth),
            emailHash: IdentityEntities::digest($seed),
        );

        $this->users->save($user);

        return $user;
    }
}
