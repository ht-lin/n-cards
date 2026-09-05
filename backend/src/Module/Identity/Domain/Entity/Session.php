<?php

declare(strict_types=1);

namespace App\Module\Identity\Domain\Entity;

use App\Module\Identity\Domain\ValueObject\SessionRevokedReason;
use App\Shared\Domain\Crypto\HashDigest;
use App\Shared\Domain\Error\DomainException;
use App\Shared\Domain\Error\ErrorCode;
use App\Shared\Domain\Identity\Uuid;

/**
 * `sessions`（§5.2 / §7.1）—— 一条 refresh token 家族。
 *
 * 类不是 `final`、映射在 XML 里，理由见 {@see User} 的类注释与 ADR-0011。
 *
 * ============================================================================
 * `id` 就是 JWT 的 `sid` claim
 * ============================================================================
 * §7.1 的 access token claims 是 `sub, sid, did, jti, iat, exp`。`sid` = 本行的 id，
 * `did` = {@see Device} 的 id。服务端**不做** access token 黑名单（15 分钟窗口可接受），
 * 但会话一旦撤销，refresh 立刻失效 —— 所以撤销的落点是这张表，不是令牌本身。
 *
 * ============================================================================
 * `previous_token_hash` 是重放检测的全部机制
 * ============================================================================
 * §7.1 的轮换规则：每次刷新签发新 refresh token，旧的立即失效并记进
 * `previous_token_hash`。**收到一个已经用过的 refresh token = 判定令牌被窃**，
 * 后果是撤销整个会话家族 + `audit_log(reuse_detected)` + 告警 + 安全提醒邮件。
 *
 * 这个实体只提供 {@see rotate()} 这一步状态迁移。**检测与处置归 T-105**：
 * 「拿一个摘要去查是谁的 previous」是仓储的事，「查到了要撤销哪些行」是编排的事。
 *
 * ⚠️ 摘要用 {@see HashDigest} 而不是 `string`，比较必须走 `HashDigest::equals()`
 * （常量时间）。用 `===` 比 refresh token 摘要是一条可用的侧信道 —— 见该类的注释。
 */
class Session
{
    private ?HashDigest $previousTokenHash = null;

    private ?\DateTimeImmutable $revokedAt = null;

    private ?SessionRevokedReason $revokedReason = null;

    /**
     * 属性不加 `readonly` 的理由见 {@see User::__construct()} 上的注释。
     */
    private function __construct(
        private Uuid $id,
        private User $user,
        private Device $device,
        private HashDigest $refreshTokenHash,
        private \DateTimeImmutable $expiresAt,
        private \DateTimeImmutable $createdAt,
    ) {
    }

    /**
     * 登录成功时开一条会话（T-104）。
     *
     * @param Uuid               $id               JWT 的 `sid` claim
     * @param HashDigest         $refreshTokenHash refresh token 本身是 32 字节随机、不透明，
     *                                             只有 SHA-256 摘要进库（§7.1）
     * @param \DateTimeImmutable $expiresAt        now() + 90d，每次轮换顺延
     */
    public static function start(
        Uuid $id,
        User $user,
        Device $device,
        HashDigest $refreshTokenHash,
        \DateTimeImmutable $expiresAt,
        \DateTimeImmutable $now,
    ): self {
        return new self($id, $user, $device, $refreshTokenHash, $expiresAt, $now);
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function user(): User
    {
        return $this->user;
    }

    public function device(): Device
    {
        return $this->device;
    }

    public function refreshTokenHash(): HashDigest
    {
        return $this->refreshTokenHash;
    }

    public function previousTokenHash(): ?HashDigest
    {
        return $this->previousTokenHash;
    }

    /**
     * 轮换 refresh token（§7.1）—— 当前摘要挪到 `previous`，新摘要接位。
     *
     * @throws DomainException `token_invalid`（401）—— 会话已被撤销
     *
     * ⚠️ **只保留一代 previous**，不是一条链。§7.1 的重放检测只需要判断
     *「这个令牌是不是刚被换掉的那个」：客户端在任意时刻手里只有一个 refresh token，
     * 更早的那些它自己已经丢弃了。留整条链既没有额外的检出能力，
     * 又会让这一行随会话年龄无限增长。
     *
     * ⚠️ 撤销检查在这里，**但被窃检测不在**。判断「传进来的旧令牌是不是等于
     * previous_token_hash」需要先按摘要找到行，那是仓储与 T-105 的编排。
     */
    public function rotate(HashDigest $refreshTokenHash, \DateTimeImmutable $expiresAt): void
    {
        if (null !== $this->revokedAt) {
            throw new DomainException(ErrorCode::TokenInvalid, 'The session has been revoked and cannot be rotated.');
        }

        $this->previousTokenHash = $this->refreshTokenHash;
        $this->refreshTokenHash = $refreshTokenHash;
        $this->expiresAt = $expiresAt;
    }

    public function expiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function isExpiredAt(\DateTimeImmutable $now): bool
    {
        return $now >= $this->expiresAt;
    }

    public function revokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function revokedReason(): ?SessionRevokedReason
    {
        return $this->revokedReason;
    }

    public function isRevoked(): bool
    {
        return null !== $this->revokedAt;
    }

    /**
     * 撤销这条会话。
     *
     * 幂等，且**首个 reason 胜出**：一条会话先因 `reuse_detected` 被撤销、
     * 随后又被账号删除流程扫到时，留下的必须是安全事件那个原因 ——
     * 它是 `audit_log` 与告警的依据，被 `account_deleted` 覆盖掉就再也查不出
     * 这个账号曾经发生过令牌被窃。
     */
    public function revoke(SessionRevokedReason $reason, \DateTimeImmutable $now): void
    {
        if (null !== $this->revokedAt) {
            return;
        }

        $this->revokedAt = $now;
        $this->revokedReason = $reason;
    }

    /**
     * 这条会话现在还能用来换令牌吗。
     */
    public function isActiveAt(\DateTimeImmutable $now): bool
    {
        return !$this->isRevoked() && !$this->isExpiredAt($now);
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
