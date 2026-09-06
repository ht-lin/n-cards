<?php

declare(strict_types=1);

namespace App\Shared\Domain\Token;

/**
 * Ed25519 签名密钥的原始材料 —— seed（32 字节）+ `kid`。
 *
 * ============================================================================
 * ⚠️ 这是全仓库唯一一个真的持有密钥字节的对象
 * ============================================================================
 * 别处的密钥都留在 Vault 里（§5.3：「Vault 内部密钥永不出 Vault」）——
 * Transit 只出借加解密**能力**。JWT 签名密钥是那条规则的例外，
 * 因为 Vault Transit 不支持 EdDSA 的分离签名形态，签名必须在应用进程里做。
 *
 * 例外的代价写在 `infra/vault/policies/ncards-app.hcl` 的 KV 那一条上：
 * 应用主机被完全控制之后，攻击者可以离线伪造任意用户的 access token，
 * 且**驱离之后仍然可以**。缓解手段是事故响应必须包含一次 JWT 密钥轮换
 * （T-404 的 key-rotation runbook）。
 *
 * 三条纪律：
 *   1. **不实现 `__toString()` / `jsonSerialize()`**。一旦有，某天就会有人把它
 *      丢进日志或异常上下文里，而 `PiiRedactionProcessor` 认不出这是密钥。
 *      与 {@see \App\Shared\Domain\Crypto\HashDigest} 刻意不给 `__toString()` 同理。
 *   2. **不出 `Shared\Infrastructure\Token`**。构造它的是
 *      `VaultKvSigningKeyProvider`，消费它的是 `Ed25519AccessTokenSigner`，
 *      没有第三个调用方。
 *   3. 析构时不擦内存 —— PHP 做不到可靠的 `sodium_memzero` 生命周期管理，
 *      假装做到了比不做更坏。真实的边界是进程边界。
 */
final readonly class SigningKey
{
    public const SEED_BYTES = 32;

    /**
     * ⚠️ `non-empty-string` 不是装饰：`sodium_crypto_sign_seed_keypair()` 的签名
     * 要的就是它，而下面的长度校验正是那条保证的来源。把校验搬走或放宽，
     * `Ed25519AccessTokenSigner` 会在 PHPStan level 8 当场红。
     *
     * @var non-empty-string
     */
    public string $seed;

    /**
     * @param string $seed Ed25519 私钥种子，**恰好 32 字节**
     * @param string $kid  JWS header 的 `kid`。§5.3 的 6 个月轮换有 24h 双密钥重叠期，
     *                     验签方靠它区分新旧
     *
     * @throws \InvalidArgumentException 长度不对或 kid 为空。这是**接线错误**
     *                                   （Vault 里的值坏了或解析写错了），不是运行时状况
     */
    public function __construct(
        string $seed,
        public string $kid,
    ) {
        if (self::SEED_BYTES !== \strlen($seed)) {
            // ⚠️ 不回显长度以外的任何东西 —— 异常文案会进日志。
            throw new \InvalidArgumentException(\sprintf('An Ed25519 signing seed must be exactly %d bytes, got %d.', self::SEED_BYTES, \strlen($seed)));
        }

        if ('' === $kid) {
            throw new \InvalidArgumentException('A signing key must carry a non-empty kid.');
        }

        $this->seed = $seed;
    }
}
