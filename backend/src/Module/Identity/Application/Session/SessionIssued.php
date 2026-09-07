<?php

declare(strict_types=1);

namespace App\Module\Identity\Application\Session;

use App\Shared\Domain\Identity\Uuid;

/**
 * 一对新令牌（契约里的 `Session`）。
 *
 * ============================================================================
 * 为什么住在 `Application\Session\` 而不是 `Application\Otp\`
 * ============================================================================
 * T-104 建它时只有一个生产者（OTP 验证），于是它落在了 `Application\Otp\`。
 * T-105 加了第二个（`POST /auth/token/refresh`），而契约里两者的 200 响应
 * 是**同一个** `Session` schema —— 让刷新流程去 `Application\Otp\` 里取一个 DTO，
 * 等于宣称刷新是 OTP 的一部分，而它不是（刷新根本不碰 `otp_challenges`）。
 *
 * T-106 的 magic consume 会是第三个生产者，形状同样是这个。
 *
 * ============================================================================
 * 为什么是扁平的一堆标量，而不是捎上 User 实体
 * ============================================================================
 * deptrac 里 `Identity.Http` **看不到** `Identity.Domain`，所以控制器拿不到
 * `User` 也读不了它的 getter。把实体塞进这个 DTO，两个控制器都编译不过。
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
