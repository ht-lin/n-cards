<?php

declare(strict_types=1);

namespace App\Module\Identity\Application\Session;

use App\Module\Identity\Domain\Entity\Device;
use App\Module\Identity\Domain\Repository\DeviceRepositoryInterface;
use App\Module\Identity\Domain\Repository\SessionRepositoryInterface;
use App\Module\Identity\Domain\ValueObject\SessionRevokedReason;
use App\Shared\Application\Metrics\MetricsInterface;
use App\Shared\Application\Transaction\TransactionRunnerInterface;
use App\Shared\Domain\Error\DomainException;
use App\Shared\Domain\Error\ErrorCode;
use App\Shared\Domain\Http\AuthContext;
use App\Shared\Domain\Identity\Uuid;
use App\Shared\Domain\Time\ClockInterface;

/**
 * 设备管理页的三个端点（§6.2 / §7.2 T02，T-105）。
 *
 * §7.2 把「邮箱被接管 → 账号被接管」记为**已接受**的风险，缓解手段只有三条：
 * 新设备登录提醒信、**这个页面**、以及二期的 Passkey。
 * 也就是说这三个端点是用户在账号被接管后唯一能自救的地方。
 *
 * ============================================================================
 * ⚠️⚠️ 不属于当前用户的设备一律 404，**不是** 403
 * ============================================================================
 * `devices.id` 由**客户端**生成（§5.2），所以它不是凭据 —— 任何人都可以拿一个
 * 别人的设备 id 来试。403 等于回答「这个 id 存在，只是不是你的」，
 * 那是一条现成的存在性信道，而设备 id 会出现在另一个用户的设备管理页上。
 *
 * 404 让「不存在」与「不是你的」不可区分。三个端点共用
 * {@see ownedDevice()} 这一个入口，正是为了让这条约束没有第二种写法。
 *
 * ============================================================================
 * ⚠️ 撤销设备 ≠ 撤销它的会话，两件事都要做
 * ============================================================================
 * {@see Device::revoke()} 的注释写着：「撤销设备**不会**自动撤销它的会话，
 * 会话家族的撤销是 T-105 的编排逻辑」。只调 `Device::revoke()` 就交差的话，
 * 被踢下线的设备手里那枚 refresh token 仍然有效 **90 天** ——
 * 而「远程登出后该设备的 refresh 立即失效」正是本卡验收标准的第二条。
 */
final readonly class DeviceService
{
    public function __construct(
        private DeviceRepositoryInterface $devices,
        private SessionRepositoryInterface $sessions,
        private TransactionRunnerInterface $transactions,
        private MetricsInterface $metrics,
        private ClockInterface $clock,
    ) {
    }

    /**
     * `GET /v1/me/devices` —— 只列未撤销的，按 last_seen_at 倒序。
     *
     * @return list<DeviceView>
     */
    public function list(AuthContext $auth): array
    {
        return array_map(
            // 「当前设备」= access token 的 `did` 与这一行的 id 相等。
            // ⚠️ 用 `did` 而不是「最近 last_seen_at 的那台」：后者在两台设备
            // 几乎同时活跃时会标错，而标错的后果是用户远程登出了自己正在用的手机。
            fn (Device $device): DeviceView => new DeviceView(
                $device->id(),
                $device->platform()->value,
                $device->model(),
                $device->osVersion(),
                $device->appVersion(),
                $device->id()->equals($auth->deviceId),
                $device->lastSeenAt(),
                $device->createdAt(),
                $device->pushTokenUpdatedAt(),
            ),
            $this->devices->listActiveForUser($auth->userId),
        );
    }

    /**
     * `DELETE /v1/me/devices/{id}` —— 远程登出。
     *
     * @throws DomainException `not_found`（404）—— 不存在，或不属于当前用户
     */
    public function revoke(AuthContext $auth, Uuid $deviceId): void
    {
        $device = $this->ownedDevice($auth, $deviceId);

        $now = $this->clock->now();

        // 幂等：已撤销的设备重复删仍然 204，且**不**重置 revoked_at ——
        // 那是「什么时候被踢下线的」这个事实（Device::revoke() 自己也这么写）。
        if ($device->isRevoked()) {
            return;
        }

        // 两步必须同生共死：只撤设备 → 令牌还能用 90 天；只撤会话 → 设备行
        // 还挂着 push_token，而 ROPA §8.2 说「设备撤销后即删」。
        $revokedSessions = $this->transactions->run(function () use ($device, $auth, $deviceId, $now): int {
            // Device::revoke() 顺手清掉 push_token（ROPA §8.2：它是设备行里
            // 唯一会被发给第三方的字段）。
            $device->revoke($now);
            $this->devices->save($device);

            // `user_revoked`：用户在设备管理页上主动踢掉了这台设备。
            // ⚠️ 已因 reuse_detected 撤销的会话不会被改写成这个原因 ——
            // 仓储逐行走 Session::revoke()，而那个方法首个 reason 胜出。
            return $this->sessions->revokeAllForDevice(
                $deviceId,
                $auth->userId,
                SessionRevokedReason::UserRevoked,
                $now,
            );
        });

        // 一台设备上可能有多条会话（同一台机器重复登录会各开一行），
        // 计数按**会话**而不是按操作 —— 指标问的是「有多少会话被撤销了」。
        // 0 条时不打点：`$by` 是 positive-int，且一个空事件只会让曲线难读。
        if ($revokedSessions > 0) {
            $this->metrics->counter(
                'session_revoked_total',
                ['reason' => SessionRevokedReason::UserRevoked->value],
                $revokedSessions,
            );
        }
    }

    /**
     * `PUT /v1/me/devices/{id}/push-token`。
     *
     * ⚠️ 本任务**只落库**，不接 FCM。整条推送链路归 T-306（M3），
     * 那需要一个还不存在的推送 Port —— 与 T-104「只发信、不发推送」同一个做法。
     * 这个端点先存在，是因为客户端在 M1 就要开始上报，否则 T-306 上线当天
     * 一个 token 都没有。
     *
     * @param string|null $pushToken null 表示客户端主动清除（用户关掉了通知权限）
     *
     * @throws DomainException `not_found`（404）
     */
    public function updatePushToken(AuthContext $auth, Uuid $deviceId, ?string $pushToken): void
    {
        $device = $this->ownedDevice($auth, $deviceId);

        // 已撤销的设备拒收。允许的话，一台被远程登出的设备可以继续刷新
        // push_token，把自己留在推送目标里 —— 而用户以为它已经被踢掉了。
        // 与 §5.2「设备撤销后即删 push_token」直接冲突。
        if ($device->isRevoked()) {
            throw self::notFound();
        }

        // 令牌与时间戳一起写（Device::updatePushToken() 的注释解释了为什么
        // 不能分开）。同时 touch：能上报 push token 说明这台设备刚活跃过。
        $device->updatePushToken($pushToken, $this->clock->now());
        $device->touch($this->clock->now());

        $this->devices->save($device);
    }

    /**
     * 三个端点共用的归属校验 —— 见类注释「一律 404」。
     *
     * @throws DomainException `not_found`（404）
     */
    private function ownedDevice(AuthContext $auth, Uuid $deviceId): Device
    {
        $device = $this->devices->findById($deviceId);

        // ⚠️ 两个条件合成一个 404，且顺序无所谓 —— 重点是**响应上分不出来**。
        // 拆成两条各带各的文案，就等于把「这个 id 存不存在」漏出去了。
        if (null === $device || !$device->user()->id()->equals($auth->userId)) {
            throw self::notFound();
        }

        return $device;
    }

    private static function notFound(): DomainException
    {
        // 文案里不回显 device id：它由客户端提供，原样回显是一个反射面，
        // 而且这条 detail 会进日志。
        return new DomainException(ErrorCode::NotFound, 'No such device.');
    }
}
