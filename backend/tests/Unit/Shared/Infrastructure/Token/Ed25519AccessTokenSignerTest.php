<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Token;

use App\Shared\Domain\Crypto\CryptoUnavailable;
use App\Shared\Domain\Token\AccessTokenClaims;
use App\Shared\Infrastructure\Token\Ed25519AccessTokenSigner;
use App\Tests\Double\Identity\IdentityEntities;
use App\Tests\Double\Token\StaticSigningKeyProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * §7.1「Access | JWT，EdDSA (Ed25519)，claims `sub, sid, did, jti, iat, exp`」。
 *
 * ============================================================================
 * ⚠️ 这里必须真的验一次签名，不能只断言「有三段」
 * ============================================================================
 * 我们自己写了 JWS 的紧凑序列化（理由见被测类的注释）。手写实现最容易出的错
 * 恰好是**两边一致地错**：签的是 base64 而不是 base64url、签的是解码后的字节、
 * 或者签的是 payload 而不是 `header.payload`。这些错误在「自己签自己验」的
 * 测试里全部通过，直到某个第三方工具（jwt.io、API 网关、Android 侧的库）
 * 来验它时才暴露 —— 那时线上已经全是签坏了的 token。
 *
 * 所以下面用 `sodium_crypto_sign_verify_detached()` 独立地重算一遍：
 * 它不认识我们的代码，只认识 RFC 8032。
 */
#[CoversClass(Ed25519AccessTokenSigner::class)]
final class Ed25519AccessTokenSignerTest extends TestCase
{
    private const KID = 'a1b2c3d4e5f60708';

    public function testTheHeaderDeclaresEdDsaAndCarriesTheKid(): void
    {
        $header = self::decodeSegment($this->sign()->token, 0);

        self::assertSame('EdDSA', $header['alg']);
        self::assertSame('JWT', $header['typ']);
        // §5.3 的 6 个月轮换有 24h 双密钥重叠期，验签方靠 kid 选密钥。
        // 漏了它，轮换当天旧 token 会变成「试完所有密钥都不过」。
        self::assertSame(self::KID, $header['kid']);
    }

    /**
     * §7.1 的 claim 集是一份**封闭清单**。多一个字段就是一个单向门：
     * token 是不透明凭证、客户端会缓存它，加进去的字段一旦被读了就拿不掉。
     */
    public function testThePayloadCarriesExactlyTheSpecClaims(): void
    {
        $claims = self::claims();
        $payload = self::decodeSegment($this->sign($claims)->token, 1);

        self::assertSame(['sub', 'sid', 'did', 'jti', 'iat', 'exp'], array_keys($payload));

        self::assertSame($claims->subject->toString(), $payload['sub']);
        self::assertSame($claims->sessionId->toString(), $payload['sid']);
        self::assertSame($claims->deviceId->toString(), $payload['did']);
        self::assertSame($claims->tokenId->toString(), $payload['jti']);
    }

    /**
     * RFC 7519：`iat` / `exp` 是**秒级 NumericDate**，不是毫秒、不是字符串。
     */
    public function testTheTimestampsAreIntegerSecondsNotMillisOrStrings(): void
    {
        $claims = self::claims();
        $payload = self::decodeSegment($this->sign($claims)->token, 1);

        self::assertIsInt($payload['iat']);
        self::assertIsInt($payload['exp']);
        self::assertSame($claims->issuedAt->getTimestamp(), $payload['iat']);
        self::assertSame(900, $payload['exp'] - $payload['iat'], '§7.1: 15 minutes.');
    }

    /**
     * `expires_in` 由 `exp - iat` 算，与配置常量同源 —— 分开算迟早会漂。
     */
    public function testExpiresInMatchesTheClaimWindow(): void
    {
        self::assertSame(900, $this->sign()->expiresInSeconds);
    }

    /**
     * ⚠️ 本文件最重要的一条。见类注释。
     */
    public function testTheSignatureVerifiesAgainstTheRawEd25519PublicKey(): void
    {
        $provider = StaticSigningKeyProvider::fromSeed(kid: self::KID);
        $token = (new Ed25519AccessTokenSigner($provider))->sign(self::claims())->token;

        [$header, $payload, $signature] = explode('.', $token);

        $publicKey = sodium_crypto_sign_publickey(
            sodium_crypto_sign_seed_keypair($provider->currentKey()->seed),
        );

        self::assertTrue(
            sodium_crypto_sign_verify_detached(
                self::nonEmpty(self::base64UrlDecode($signature)),
                // 签的是**拼接后的两段**，不是各自签一次、也不是解码后的字节。
                $header.'.'.$payload,
                $publicKey,
            ),
        );
    }

    /**
     * ⚠️ base64url（RFC 7515 §2）：`+/` → `-_`，去掉 `=` 填充。
     *
     * 用普通 base64 签出来的 token 在任何标准验签方那里都是非法的。
     * 这条断言之所以能咬住，是因为它检查的是**字符集**，而不是「解得开吗」——
     * 后者对两种编码都成立。
     */
    public function testEverySegmentIsBase64UrlWithoutPadding(): void
    {
        $token = $this->sign()->token;

        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/', $token);
        self::assertStringNotContainsString('=', $token);
        self::assertStringNotContainsString('+', $token);
        self::assertStringNotContainsString('/', $token);
    }

    /**
     * 每次签名都重新问一次密钥 —— 缓存的决定归
     * {@see \App\Shared\Infrastructure\Token\VaultKvSigningKeyProvider}（它带 TTL）。
     * 签名器自己缓存的话，那个 TTL 就被架空了，而 §5.3 的双密钥重叠期只有 24h。
     */
    public function testAsksTheProviderOnEverySignature(): void
    {
        $provider = StaticSigningKeyProvider::fromSeed(kid: self::KID);
        $signer = new Ed25519AccessTokenSigner($provider);

        $signer->sign(self::claims());
        $signer->sign(self::claims());

        self::assertSame(2, $provider->calls());
    }

    /**
     * 取不到密钥 → 503，可重试（Vault 每次重启后都是封印状态，要人工 unseal）。
     * 异常原样冒泡，签名器不吞也不改写。
     */
    public function testPropagatesTheProviderFailureUntouched(): void
    {
        $provider = StaticSigningKeyProvider::fromSeed();
        $provider->failWith(new CryptoUnavailable('The JWT signing key could not be read.'));

        $this->expectException(CryptoUnavailable::class);

        (new Ed25519AccessTokenSigner($provider))->sign(self::claims());
    }

    // ========================================================================
    // 支撑
    // ========================================================================

    private function sign(?AccessTokenClaims $claims = null): \App\Shared\Domain\Token\IssuedAccessToken
    {
        return (new Ed25519AccessTokenSigner(StaticSigningKeyProvider::fromSeed(kid: self::KID)))
            ->sign($claims ?? self::claims());
    }

    private static function claims(): AccessTokenClaims
    {
        $now = IdentityEntities::now('2026-09-06T12:00:00+00:00');

        return new AccessTokenClaims(
            IdentityEntities::id(1),
            IdentityEntities::id(2),
            IdentityEntities::id(3),
            IdentityEntities::id(4),
            $now,
            $now->modify('+900 seconds'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function decodeSegment(string $token, int $index): array
    {
        $decoded = json_decode(self::base64UrlDecode(explode('.', $token)[$index]), true, 8, \JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded);

        /* @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * PHPStan 侧的收窄：`sodium_crypto_sign_*` 的签名要 `non-empty-string`，
     * 而 `base64_decode()` 与 PEM 解析返回的都是 `string`。
     *
     * 用一条断言而不是 `@var` 或强转：空串在这里是**真实的失败模式**
     * （base64 解不开、KV 里是空值），静默放过它会让 sodium 抛一个
     * 与根因无关的异常。
     *
     * @return non-empty-string
     */
    private static function nonEmpty(string $value): string
    {
        self::assertNotSame('', $value);

        return $value;
    }

    private static function base64UrlDecode(string $segment): string
    {
        $decoded = base64_decode(strtr($segment, '-_', '+/'), true);

        self::assertIsString($decoded);

        return $decoded;
    }
}
