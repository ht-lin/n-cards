<?php

declare(strict_types=1);

namespace App\Module\Identity\Domain\Entity;

use App\Module\Identity\Domain\ValueObject\DevicePlatform;
use App\Shared\Domain\Identity\Uuid;

/**
 * `devices`（§5.2）—— 一次安装。
 *
 * 类不是 `final`、映射在 XML 里，理由见 {@see User} 的类注释与 ADR-0011。
 *
 * ============================================================================
 * `id` 由**客户端**生成
 * ============================================================================
 * §5.2：「客户端生成，安装级唯一（重装即新设备）」。这有两个后果：
 *
 *   1. 服务端不能假设 id 没被人猜到或伪造 —— 它只是一个标识符，不是凭据。
 *      设备管理页（T-105）的每个操作都必须先校验这台设备属于当前用户。
 *   2. 「重装即新设备」是有意的：重装后 `EncryptedSharedPreferences` 里的
 *      refresh token 已经没了，本来就得重新登录，再复用旧的设备行只会让
 *      设备列表里堆满同一台手机的历史条目。
 *
 * ============================================================================
 * `user` 是一条 ORM 关联，不是一个 uuid 列
 * ============================================================================
 * ⚠️ 这不是风格选择。`doctrine:schema:validate` 拿 ORM 元数据生成的 schema 与真库
 * 内省结果做 diff；把 `user_id` 映射成普通列的话，库里那条 `fk_devices_user_id`
 * 在 ORM 侧没有对应物 → 报「多出一条待删外键」→ **not in sync**，
 * 而 T-101 的验收标准之一就是这条命令要绿。详见 ADR-0011。
 */
class Device
{
    private ?string $pushToken = null;

    private ?\DateTimeImmutable $pushTokenUpdatedAt = null;

    private \DateTimeImmutable $lastSeenAt;

    private ?\DateTimeImmutable $revokedAt = null;

    /**
     * 属性不加 `readonly` 的理由见 {@see User::__construct()} 上的注释。
     */
    private function __construct(
        private Uuid $id,
        private User $user,
        private DevicePlatform $platform,
        private ?string $model,
        private ?string $osVersion,
        private ?string $appVersion,
        private \DateTimeImmutable $createdAt,
    ) {
        $this->lastSeenAt = $createdAt;
    }

    /**
     * 登录时注册这次安装（T-104）。
     *
     * @param Uuid $id 客户端生成的安装级 UUID，也是 JWT 的 `did` claim
     */
    public static function register(
        Uuid $id,
        User $user,
        DevicePlatform $platform,
        ?string $model,
        ?string $osVersion,
        ?string $appVersion,
        \DateTimeImmutable $now,
    ): self {
        return new self($id, $user, $platform, $model, $osVersion, $appVersion, $now);
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function user(): User
    {
        return $this->user;
    }

    public function platform(): DevicePlatform
    {
        return $this->platform;
    }

    public function model(): ?string
    {
        return $this->model;
    }

    public function osVersion(): ?string
    {
        return $this->osVersion;
    }

    public function appVersion(): ?string
    {
        return $this->appVersion;
    }

    /**
     * 同一台设备再次登录时刷新展示信息（系统升级、App 升级）。
     */
    public function describe(?string $model, ?string $osVersion, ?string $appVersion): void
    {
        $this->model = $model;
        $this->osVersion = $osVersion;
        $this->appVersion = $appVersion;
    }

    public function pushToken(): ?string
    {
        return $this->pushToken;
    }

    public function pushTokenUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->pushTokenUpdatedAt;
    }

    /**
     * `PUT /v1/me/devices/{id}/push-token`（T-105）。
     *
     * 时间戳与令牌一起写 —— 分开写的话会出现「有令牌但不知道多旧」的行，
     * 而 FCM 令牌会过期，§4.4 的推送链路需要这个时间来判断是否该催客户端刷新。
     */
    public function updatePushToken(?string $pushToken, \DateTimeImmutable $now): void
    {
        $this->pushToken = $pushToken;
        $this->pushTokenUpdatedAt = $now;
    }

    public function lastSeenAt(): \DateTimeImmutable
    {
        return $this->lastSeenAt;
    }

    public function touch(\DateTimeImmutable $now): void
    {
        $this->lastSeenAt = $now;
    }

    public function revokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function isRevoked(): bool
    {
        return null !== $this->revokedAt;
    }

    /**
     * 远程登出这台设备（`DELETE /v1/me/devices/{id}`，T-105）。
     *
     * 幂等：重复撤销不重置时间戳 —— 那是「什么时候被踢下线的」这个事实，
     * 安全提醒邮件与 `audit_log` 都引用它。
     *
     * ⚠️ 撤销设备**不会**自动撤销它的会话。会话家族的撤销是 T-105 的编排逻辑
     * （`sessions.revoked_reason = user_revoked`），这里只标设备本身。
     * 分开是因为两者的生命周期不同：设备行要留着给设备管理页显示历史，
     * 会话行则必须立刻失效。
     */
    public function revoke(\DateTimeImmutable $now): void
    {
        if (null !== $this->revokedAt) {
            return;
        }

        // 撤销即清推送令牌：ROPA §8.2 的设备行写明保留期是「设备撤销后即删」，
        // 而 push_token 是里面唯一会被发给第三方（FCM / Google Ireland）的字段。
        $this->pushToken = null;
        $this->pushTokenUpdatedAt = $now;
        $this->revokedAt = $now;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
