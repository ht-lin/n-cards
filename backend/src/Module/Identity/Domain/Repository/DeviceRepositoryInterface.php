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
 * T-105 补上了 {@see listActiveForUser()}，并在那里回答了本注释原先留下的
 * 两个问题（过滤已撤销的；当前设备不入库、由调用方比对）。
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

    /**
     * 设备管理页的列表（`GET /v1/me/devices`，T-105）。
     *
     * 本接口原先的注释把两个决定留给了 T-105，现在定：
     *
     * 1. **只列未撤销的**（`revoked_at IS NULL`），与 {@see countActiveForUser()}
     *    同一个口径。撤销之后 `push_token` 已被清（{@see Device::revoke()}，ROPA §8.2）、
     *    条目对用户也没有任何可操作性 —— 把它列出来只会让「远程登出」看起来没生效。
     *    ⚠️ 顺带也让「历史设备」不成为一条侧信道：一台被撤销的设备的机型与
     *    最后在线时间，对已经接管了邮箱的攻击者是有情报价值的。
     * 2. **「当前设备」不在这里判**。它由调用方拿 access token 的 `did`
     *    （`AuthContext::$deviceId`）与每一行比对得出，不进库、不加列 ——
     *    「当前」是**请求**的属性，不是设备行的属性，同一台设备在另一个请求里
     *    就不是当前的了。
     *
     * 排序 `last_seen_at DESC`：用户认得出的是「刚用过的那台」。
     *
     * **不分页**。§7.5 没有给设备数设限额，但真实上限是个位数（重装即新设备，
     * 而人不会重装几百次）。真出现异常多的行是 T-113 清理任务的事，
     * 不是在这里加一个客户端永远用不到的游标。
     *
     * @return list<Device> 可能为空 —— 一个所有设备都被远程登出的用户仍然能
     *                      用手里的 access token 打这个端点（§7.1 不做黑名单）
     */
    public function listActiveForUser(Uuid $userId): array;
}
