<?php

declare(strict_types=1);

namespace App\Module\Identity\Domain\Entity;

use App\Module\Identity\Domain\ValueObject\OtpPurpose;
use App\Shared\Domain\Crypto\HashDigest;
use App\Shared\Domain\Error\DomainException;
use App\Shared\Domain\Error\ErrorCode;
use App\Shared\Domain\Identity\Uuid;

/**
 * `otp_challenges`（§5.2）—— 一次 OTP 登录挑战。
 *
 * 类不是 `final`、映射在 XML 里，理由见 {@see User} 的类注释与 ADR-0011。
 *
 * ============================================================================
 * ⚠️ 这张表**没有到 `users` 的外键**
 * ============================================================================
 * 看起来像遗漏，其实是 §3.8 防枚举的承重墙。邮箱不存在时 `POST /auth/otp/request`
 * 同样要建一条挑战（`is_decoy = true`）并返回一个哑 `challenge_id`，
 * 好让响应体与**耗时**都与真实路径不可区分。
 *
 * 有外键的话这条哑挑战根本插不进去 —— 它指向的用户按定义就不存在。
 * 所以这里存的是 `email_hash` 而不是 `user_id`，且没有引用完整性约束。
 *
 * ============================================================================
 * 什么不在这里
 * ============================================================================
 * 这个实体只提供状态迁移，**策略归各自的任务**：
 *   - 6 位码的生成与 `code_hash` 的计算、旧挑战作废、限流三维（T-103）
 *   - 「`attempts` 超过 5 即作废整个挑战」的那个 **5**（T-104，§7.1）——
 *     所以下面是 {@see hasAttemptsLeft()} 收一个上限参数，而不是硬编码
 *   - Magic Link 的 `GET` 不消费 / `POST` 才消费（T-106）
 */
class OtpChallenge
{
    private int $attempts = 0;

    private ?\DateTimeImmutable $consumedAt = null;

    /**
     * 属性不加 `readonly` 的理由见 {@see User::__construct()} 上的注释。
     */
    private function __construct(
        private Uuid $id,
        private HashDigest $emailHash,
        private HashDigest $codeHash,
        private OtpPurpose $purpose,
        private \DateTimeImmutable $expiresAt,
        private bool $isDecoy,
        private ?HashDigest $magicTokenHash,
        private ?HashDigest $requestIpHash,
        private \DateTimeImmutable $createdAt,
    ) {
    }

    /**
     * 真实挑战 —— 邮箱确实存在，码会发出去。
     *
     * @param HashDigest      $codeHash       `HMAC-SHA256(code, pepper)`，§7.1 明令**不存明文**
     * @param HashDigest|null $magicTokenHash Magic Link 令牌的哈希（T-106），不发 Magic Link 时为 null
     * @param HashDigest|null $requestIpHash  限流与滥用分析用；ROPA §8.2 规定 30 天后清理（T-113）
     */
    public static function issue(
        Uuid $id,
        HashDigest $emailHash,
        HashDigest $codeHash,
        OtpPurpose $purpose,
        \DateTimeImmutable $expiresAt,
        ?HashDigest $magicTokenHash,
        ?HashDigest $requestIpHash,
        \DateTimeImmutable $now,
    ): self {
        return new self(
            $id,
            $emailHash,
            $codeHash,
            $purpose,
            $expiresAt,
            false,
            $magicTokenHash,
            $requestIpHash,
            $now,
        );
    }

    /**
     * 哑挑战（§3.8）—— 邮箱不存在时建的，**不发信，验证时永远失败**。
     *
     * ⚠️ `codeHash` 仍然要传一个真实的随机摘要，不能传常量或全零。
     * 攻击者拿不到这一列，但一个可预测的值会让「哑挑战」在库层可辨认，
     * 而 §8.4 的数据导出与将来的运维查询都可能把这个差别泄露出去。
     * T-103 的做法是照常生成一个码、照常算摘要，只是不发信。
     */
    public static function decoy(
        Uuid $id,
        HashDigest $emailHash,
        HashDigest $codeHash,
        OtpPurpose $purpose,
        \DateTimeImmutable $expiresAt,
        ?HashDigest $requestIpHash,
        \DateTimeImmutable $now,
    ): self {
        return new self(
            $id,
            $emailHash,
            $codeHash,
            $purpose,
            $expiresAt,
            true,
            null,
            $requestIpHash,
            $now,
        );
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function emailHash(): HashDigest
    {
        return $this->emailHash;
    }

    public function codeHash(): HashDigest
    {
        return $this->codeHash;
    }

    public function magicTokenHash(): ?HashDigest
    {
        return $this->magicTokenHash;
    }

    public function purpose(): OtpPurpose
    {
        return $this->purpose;
    }

    public function attempts(): int
    {
        return $this->attempts;
    }

    /**
     * 记一次失败的验证尝试。
     *
     * ⚠️ **成功的验证也要先记一次**再比对 —— 否则「码错了」与「码对了」在
     * `attempts` 上留下的痕迹不同，而 §3.8 要求两条路径不可区分。
     * 顺序由 T-104 的处理器负责，这里只提供计数。
     */
    public function recordAttempt(): void
    {
        ++$this->attempts;
    }

    /**
     * @param int $max §7.1 的最大尝试次数（5）。**由调用方传入**：
     *                 这个数字是策略不是不变量，归 T-104
     */
    public function hasAttemptsLeft(int $max): bool
    {
        return $this->attempts < $max;
    }

    public function expiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function isExpiredAt(\DateTimeImmutable $now): bool
    {
        return $now >= $this->expiresAt;
    }

    public function consumedAt(): ?\DateTimeImmutable
    {
        return $this->consumedAt;
    }

    public function isConsumed(): bool
    {
        return null !== $this->consumedAt;
    }

    /**
     * 消费掉这条挑战 —— 验证成功，或 Magic Link 被 `POST` 消费。
     *
     * @throws DomainException `token_invalid`（401）—— 已经消费过了
     *
     * ⚠️ 重复消费**必须抛**而不是静默返回：T-106 的验收标准写明
     * 「`POST` 消费一次后重复 POST 返回 401」。静默成功等于把一次性令牌变成可重放的。
     * 库层没有约束能拦住这件事（`consumed_at` 只是一列时间戳），
     * 所以这条不变量只在这里。
     */
    public function consume(\DateTimeImmutable $at): void
    {
        if (null !== $this->consumedAt) {
            throw new DomainException(ErrorCode::TokenInvalid, 'The OTP challenge has already been consumed.');
        }

        $this->consumedAt = $at;
    }

    /**
     * 哑挑战（§3.8）—— 邮箱不存在时建的，验证必须恒失败。
     */
    public function isDecoy(): bool
    {
        return $this->isDecoy;
    }

    public function requestIpHash(): ?HashDigest
    {
        return $this->requestIpHash;
    }

    /**
     * 清空 `request_ip_hash`（ROPA §8.2：30 天后清理）。由 T-113 的每日任务调用。
     */
    public function forgetRequestIp(): void
    {
        $this->requestIpHash = null;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
