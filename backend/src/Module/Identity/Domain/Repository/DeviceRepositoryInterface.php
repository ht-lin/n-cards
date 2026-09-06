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

    /**
     * 这个用户当前有几台**未撤销**的设备。
     *
     * 唯一的调用方是 T-104 的登录编排，用来回答一个是非题：
     * 「这次新设备登录，是**注册后的第一台**，还是账号已经有别的设备了？」
     *
     * ⚠️ 只在为 true 时才发 §7.1 的新设备提醒信。刚注册的账号第一次登录必然
     * 是「新设备」，给它发一封「检测到新设备登录」是纯噪声 ——
     * 而噪声会训练用户忽略这封信，那正好毁掉它唯一的作用
     * （§7.2 T02：邮箱被接管时，这封信是用户能察觉的唯一信号）。
     *
     * 返回计数而不是布尔，是因为调用方要判断的是「> 1」而不是「> 0」：
     * 判断发生在本次登录的设备已经落库之后。
     *
     * ⚠️ 已撤销的设备**不计入**：用户远程登出了旧手机、只剩新手机，
     * 那么下一次在第三台设备上登录仍然应该收到提醒。
     */
    public function countActiveForUser(Uuid $userId): int;
}
