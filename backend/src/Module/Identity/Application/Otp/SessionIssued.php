<?php

declare(strict_types=1);

namespace App\Module\Identity\Application\Otp;

use App\Shared\Domain\Identity\Uuid;

/**
 * 登录成功的结果（契约里的 `Session`）。
 *
 * ============================================================================
 * 为什么是扁平的一堆标量，而不是捎上 User 实体
 * ============================================================================
 * deptrac 里 `Identity.Http` **看不到** `Identity.Domain`，所以控制器拿不到
 * `User` 也读不了它的 getter。把实体塞进这个 DTO，`OtpVerifyController` 就编译不过。
 *
 * 这个约束正好把「控制器不许有业务」从约定变成了机械强制 ——
 * 组响应体这件事因此只能是一层字段搬运，见 {@see OtpChallengeIssued} 的同款注释。
 *
 * ============================================================================
 * ⚠️ `refreshToken` 是这个对象里唯一一次出现的明文
 * ============================================================================
 * 库里存的是它的 SHA-256（`sessions.refresh_token_hash`），明文只在
 * 「生成 → 组响应体 → 发出去」这一条直线上存在，此后服务端再也拿不到它。
 *
 * 所以：**不要给本类加 `__toString()` / `jsonSerialize()`**，也不要把整个 DTO
 * 丢进日志上下文。`PiiRedactionProcessor` 认识 `refresh_token` 这个**键名**，
 * 但认不出一个被 var_export 成字符串的对象。
 */
final readonly class SessionIssued
{
    /**
     * @param string      $accessToken        紧凑序列化的 JWT（EdDSA / Ed25519，§7.1）
     * @param int<1, max> $expiresInSeconds   access token 的剩余寿命，900
     * @param string      $refreshToken       32 字节随机的 base64url，**不透明**，90 天滑动
     * @param string|null $username           §5.2：首次注册后为 null，直到 T-107 设定
     * @param string      $locale             `de` / `en`
     * @param bool        $onboardingComplete 等价于 `null !== $username`（契约里写死了这条等价）
     */
    public function __construct(
        public string $accessToken,
        public int $expiresInSeconds,
        public string $refreshToken,
        public Uuid $userId,
        public ?string $username,
        public string $locale,
        public bool $onboardingComplete,
        public \DateTimeImmutable $userCreatedAt,
    ) {
    }
}
