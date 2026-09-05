<?php

declare(strict_types=1);

namespace App\Module\Identity\Domain\Repository;

use App\Module\Identity\Domain\Entity\Device;
use App\Shared\Domain\Identity\Uuid;

/**
 * `devices` 的持久化出口。
 *
 * 放 `Domain/Repository/` 而不是 `Application/Port/` 的理由见
 * {@see UserRepositoryInterface} 的类注释。
 *
 * ⚠️ 没有 `findByUser()`。设备管理页（`GET /v1/me/devices`）确实需要它，
 * 但那是 T-105 的端点，而「要不要过滤掉已撤销的」「要不要标记当前设备」
 * 这两个决定都在那张卡上，不在这里。
 */
interface DeviceRepositoryInterface
{
    /**
     * ⚠️ 实现会 `flush()`，跨仓储的写要自己包
     * `Shared\Application\Transaction\TransactionRunnerInterface::run()`。
     */
    public function save(Device $device): void;

    /**
     * ⚠️ id 是**客户端生成**的（§5.2），所以它不是凭据：查到之后必须再校验
     * `$device->user()` 是不是当前用户，否则任何人都能拿别人的设备 id 来操作。
     */
    public function findById(Uuid $id): ?Device;
}
