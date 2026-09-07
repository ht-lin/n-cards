<?php

declare(strict_types=1);

namespace App\Shared\Domain\Token;

use App\Shared\Domain\Identity\Uuid;

/**
 * Access token 的 claim 集（§7.1）—— **恰好五个 + exp，一个不多一个不少**。
 *
 * ============================================================================
 * 为什么是一个值对象而不是一个 array
 * ============================================================================
 * §7.1 把 claim 集写成了一份封闭清单：`sub, sid, did, jti, iat, exp`。
 * 用 `array<string, mixed>` 表达它，等于把「有哪些 claim」这条约束从类型系统里删掉：
 * 谁想往 token 里塞一个 `email` 或 `username`，编译期不会有任何阻力。
 *
 * 而往 access token 里塞东西是个**单向门**：token 是不透明凭证、15 分钟有效、
 * 客户端会缓存它，加进去的字段一旦被 Android 侧读了就再也拿不掉。
 * §3.8 特别在意的是 PII —— 一个带 `email` 的 JWT 会躺在
 * EncryptedSharedPreferences、崩溃日志和任何抓过包的中间层里。
 *
 * ⚠️ 往这里加字段之前先问：这条信息为什么不能由服务端按 `sub` 查？
 * 答案通常是「查一次要一趟 DB」，而那是完全可以接受的代价。
 *
 * ============================================================================
 * 三个 id 各自的用途
 * ============================================================================
 *   `sub`  用户 id —— 「这是谁」
 *   `sid`  会话 id（`sessions.id`）—— 「哪一次登录」。T-105 的远程登出与
 *          §7.1 的会话家族撤销按它作用；撤销后 refresh 立刻失效，
 *          但**服务端不做 access token 黑名单**（§7.1 接受那 15 分钟窗口）
 *   `did`  设备 id（`devices.id`，客户端生成、安装级唯一）—— 「哪台机器」。
 *          §5.4 的 `GET /v1/sync` 按它限流（60/min）
 *
 * `jti` 是这枚 token 自己的 id，用于日志关联与将来的重放调查；
 * 它**不**被服务端存储，所以不要指望能用它撤销一枚 token。
 */
final readonly class AccessTokenClaims
{
    /**
     * @param \DateTimeImmutable $issuedAt  `iat`
     * @param \DateTimeImmutable $expiresAt `exp` —— §7.1：`iat + 15min`
     */
    public function __construct(
        public Uuid $subject,
        public Uuid $sessionId,
        public Uuid $deviceId,
        public Uuid $tokenId,
        public \DateTimeImmutable $issuedAt,
        public \DateTimeImmutable $expiresAt,
    ) {
    }

    /**
     * JWT payload 的字面形状。
     *
     * ⚠️ 键名是**契约**（`docs/api/openapi.yaml` 的 `bearerAuth` 描述逐字列了它们），
     * 不是实现细节 —— 改一个字，全部在线的客户端在下一次鉴权时就对不上。
     *
     * 而且**这张表的键集是封闭的**：`Ed25519AccessTokenVerifier` 会拒掉
     * 多一个或少一个 claim 的 token（T-105）。往这里加字段的话，
     * 那边的 `REQUIRED_CLAIMS` 要一起改，否则自己签出来的 token 自己验不过。
     * 这个「一起改」是刻意的 —— 它让往 access token 里偷偷塞 PII 变成一次显眼的编辑。
     *
     * `iat` / `exp` 按 RFC 7519 是**秒级 NumericDate**，不是毫秒、不是字符串。
     *
     * @return array{sub: string, sid: string, did: string, jti: string, iat: int, exp: int}
     */
    public function toPayload(): array
    {
        return [
            'sub' => $this->subject->toString(),
            'sid' => $this->sessionId->toString(),
            'did' => $this->deviceId->toString(),
            'jti' => $this->tokenId->toString(),
            'iat' => $this->issuedAt->getTimestamp(),
            'exp' => $this->expiresAt->getTimestamp(),
        ];
    }

    /**
     * 剩余有效秒数 —— 直接进响应体的 `expires_in`（§7.1：900）。
     *
     * 由 `exp - iat` 算而不是由配置常量直接回填：两者必须同源，
     * 否则「配置改了 15 分钟、响应体还说 900」这种偏差没有任何测试会红。
     */
    public function expiresInSeconds(): int
    {
        return $this->expiresAt->getTimestamp() - $this->issuedAt->getTimestamp();
    }
}
