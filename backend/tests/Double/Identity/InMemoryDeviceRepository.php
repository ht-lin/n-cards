<?php

declare(strict_types=1);

namespace App\Tests\Double\Identity;

use App\Module\Identity\Domain\Entity\Device;
use App\Module\Identity\Domain\Repository\DeviceRepositoryInterface;
use App\Shared\Domain\Identity\Uuid;

/**
 * 进程内的 `devices` 仓储。
 *
 * ⚠️ `countActiveForUser()` 这里过滤的是 `isRevoked()`，与生产 DQL 的
 * `revoked_at IS NULL` 语义相同。那条 DQL 本身是否按预期收窄，只有真 Postgres
 * 能证明 —— 那条断言在 tests/Integration 里。这里验的是**编排**：
 * 「第一台设备不发提醒信、第二台才发」这条判据落在调用次数与返回值上。
 */
final class InMemoryDeviceRepository implements DeviceRepositoryInterface
{
    /** @var list<Device> */
    private array $devices = [];

    public function __construct(Device ...$devices)
    {
        $this->devices = array_values($devices);
    }

    public function save(Device $device): void
    {
        foreach ($this->devices as $index => $existing) {
            if ($existing->id()->equals($device->id())) {
                $this->devices[$index] = $device;

                return;
            }
        }

        $this->devices[] = $device;
    }

    public function findById(Uuid $id): ?Device
    {
        foreach ($this->devices as $device) {
            if ($device->id()->equals($id)) {
                return $device;
            }
        }

        return null;
    }

    public function countActiveForUser(Uuid $userId): int
    {
        $count = 0;

        foreach ($this->devices as $device) {
            if ($device->user()->id()->equals($userId) && !$device->isRevoked()) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * @return list<Device>
     */
    public function all(): array
    {
        return $this->devices;
    }
}
