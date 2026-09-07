<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Token;

use App\Shared\Application\Token\AccessTokenVerifierInterface;
use App\Shared\Domain\Crypto\CryptoUnavailable;
use App\Shared\Domain\Error\DomainException;
use App\Shared\Domain\Error\ErrorCode;
use App\Shared\Domain\Token\AccessTokenClaims;
use App\Shared\Domain\Token\SigningKey;
use App\Shared\Infrastructure\Token\Ed25519AccessTokenSigner;
use App\Shared\Infrastructure\Token\Ed25519AccessTokenVerifier;
use App\Tests\Double\Identity\IdentityEntities;
use App\Tests\Double\Token\StaticSigningKeyProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * §7.1 的验签侧（T-105）。
 *
 * ============================================================================
 * ⚠️ 这个文件里最重要的不是「合法 token 能过」，而是「伪造的过不去」
 * ============================================================================
 * 「自己签自己验」的往返测试几乎必然通过，它证明不了任何安全性质 ——
 * 一个把 `alg` 当开关用的实现、一个遍历所有密钥挨个试的实现、
 * 一个先解析 payload 再验签的实现，往返测试全都是绿的。
 *
 * 所以下面的重点是四组**否定**用例，各自对应被测类注释里的一条纪律：
 *   - `alg: none` / `alg: HS256`（JWT 历史上最经典的两个洞）
 *   - 未知 `kid` 不能靠遍历蒙过去
 *   - 篡改任何一段都要被拒
 *   - claim 集多一个少一个都要被拒
 *
 * 还有一条容易被忽略的：**伪造的过期 token 必须是 `token_invalid`，
 * 不是 `token_expired`** —— 后者会让客户端去刷新，而不是清会话跳登录。
 * 那条断言在 {@see testAForgedExpiredTokenIsInvalidRatherThanExpired()}。
 */
#[CoversClass(Ed25519AccessTokenVerifier::class)]
final class Ed25519AccessTokenVerifierTest extends TestCase
{
    private const KID = 'a1b2c3d4e5f60708';

    private const NOW = '2026-09-06T12:00:00+00:00';

    // ========================================================================
    // 往返 —— 必要但远不充分
    // ========================================================================

    public function testAFreshlySignedTokenRoundTripsWithEveryClaimIntact(): void
    {
        $claims = self::claims();
        $provider = self::provider();

        $verified = $this->verifier($provider)->verify(self::sign($claims, $provider), self::now());

        self::assertTrue($claims->subject->equals($verified->subject));
        self::assertTrue($claims->sessionId->equals($verified->sessionId));
        self::assertTrue($claims->deviceId->equals($verified->deviceId));
        self::assertTrue($claims->tokenId->equals($verified->tokenId));
        self::assertSame($claims->issuedAt->getTimestamp(), $verified->issuedAt->getTimestamp());
        self::assertSame($claims->expiresAt->getTimestamp(), $verified->expiresAt->getTimestamp());
    }

    /**
     * §5.3 的 24h 重叠期：轮换当天，用**上一代**密钥签的 token 仍然要验得过。
     *
     * 这条不成立的话，每次密钥轮换都会把全体在线用户在轮换那一刻踢下线 ——
     * 而那正是 `SigningKeyProviderInterface` 上「验两把、签一把」那段注释
     * 要防的事。
     */
    public function testATokenSignedWithThePreviousKeyStillVerifiesDuringTheOverlap(): void
    {
        $previous = new SigningKey(hash('sha256', 'the-old-seed', true), 'old-kid-00000001');

        // 用旧密钥签一枚 token（模拟轮换之前签发的）。
        $tokenFromOldKey = self::sign(self::claims(), StaticSigningKeyProvider::fromSeed('the-old-seed', 'old-kid-00000001'));

        // 轮换之后：签名用新密钥，但验签集合里两把都在。
        $current = self::provider();
        $current->alsoVerifyWith($previous);

        $verified = $this->verifier($current)->verify($tokenFromOldKey, self::now());

        self::assertTrue(self::claims()->subject->equals($verified->subject));
    }

    // ========================================================================
    // 纪律 1 —— alg 是常量，不是开关
    // ========================================================================

    /**
     * `alg: none` 是 JWT 最出名的洞：验签方「照 header 说的办」，
     * 而 header 说的是「不用验」。
     */
    public function testRejectsTheAlgNoneForgery(): void
    {
        $forged = self::assemble(
            ['alg' => 'none', 'typ' => 'JWT', 'kid' => self::KID],
            self::claims()->toPayload(),
            '',
        );

        $this->assertRejectedAsInvalid($forged);
    }

    /**
     * 第二个经典洞：把 `alg` 换成 HS256，拿**公钥**当 HMAC 密钥 ——
     * 公钥是公开的，于是任何人都能签出「合法」token。
     *
     * 这里连 HMAC 都不用真算：只要实现敢按 header 选算法，它就已经错了。
     */
    public function testRejectsAnAlgorithmSubstitutionToHmac(): void
    {
        $provider = self::provider();
        $publicKey = StaticSigningKeyProvider::publicKeyOf($provider->currentKey());

        $header = ['alg' => 'HS256', 'typ' => 'JWT', 'kid' => self::KID];
        $payload = self::claims()->toPayload();

        $signingInput = self::encode($header).'.'.self::encode($payload);

        $this->assertRejectedAsInvalid(
            $signingInput.'.'.self::base64UrlEncode(hash_hmac('sha256', $signingInput, $publicKey, true)),
            $provider,
        );
    }

    public function testRejectsAnUnknownAlgorithm(): void
    {
        $this->assertRejectedAsInvalid(self::forgeWithHeader(['alg' => 'EdDSA25519', 'typ' => 'JWT', 'kid' => self::KID]));
    }

    // ========================================================================
    // 纪律 2 —— kid 直接选，不遍历
    // ========================================================================

    /**
     * ⚠️ 关键点：签名本身是**有效的**（用真密钥签的），只有 `kid` 对不上。
     *
     * 一个「遍历所有密钥挨个试」的实现会让这条用例变绿 ——
     * 而那种实现会让轮换配错静默通过，直到某天两把密钥都不匹配才炸。
     */
    public function testRejectsAValidSignatureCarryingAnUnknownKid(): void
    {
        $signed = self::sign(self::claims(), StaticSigningKeyProvider::fromSeed(kid: 'some-other-kid00'));

        // 验签方只认识 self::KID，而这枚 token 的 kid 是别的。
        $this->assertRejectedAsInvalid($signed);
    }

    public function testRejectsAMissingKid(): void
    {
        $this->assertRejectedAsInvalid(self::forgeWithHeader(['alg' => 'EdDSA', 'typ' => 'JWT']));
    }

    // ========================================================================
    // 纪律 3 —— 先验签，再信 payload
    // ========================================================================

    /**
     * ⚠️ 一枚**伪造的**过期 token 必须得到 `token_invalid`，不是 `token_expired`。
     *
     * 差别是实打实的：`token_expired` 告诉客户端「去刷新」，
     * 于是一个攻击者可以用一枚随手编的过期 token 让客户端走上刷新路径。
     * 只有「先验签、再看 exp」的实现才能给出正确答案。
     */
    public function testAForgedExpiredTokenIsInvalidRatherThanExpired(): void
    {
        $past = self::now()->modify('-1 hour');

        // 未签名（签名段是垃圾），但 payload 声称自己过期了。
        $forged = self::assemble(
            ['alg' => 'EdDSA', 'typ' => 'JWT', 'kid' => self::KID],
            self::claims($past)->toPayload(),
            str_repeat("\x00", 64),
        );

        $this->assertRejectedAsInvalid($forged);
    }

    /**
     * 真的签过、真的过期了 → `token_expired`，客户端据此去刷新。
     */
    public function testAGenuinelyExpiredTokenReportsTokenExpired(): void
    {
        $provider = self::provider();
        $token = self::sign(self::claims(), $provider);

        try {
            // 签发时刻 + 901 秒：exp 是 iat+900。
            $this->verifier($provider)->verify($token, self::now()->modify('+901 seconds'));
            self::fail('An expired token must be rejected.');
        } catch (DomainException $e) {
            self::assertSame(ErrorCode::TokenExpired, $e->errorCode());
        }
    }

    /**
     * ⚠️ 无 clock skew 容差：`exp` 那一秒本身就算过期（`>=`），
     * 口径与 `Session::isExpiredAt()` 一致。
     */
    public function testTheExpirySecondItselfCountsAsExpired(): void
    {
        $provider = self::provider();
        $token = self::sign(self::claims(), $provider);

        try {
            $this->verifier($provider)->verify($token, self::now()->modify('+900 seconds'));
            self::fail('exp itself must count as expired.');
        } catch (DomainException $e) {
            self::assertSame(ErrorCode::TokenExpired, $e->errorCode());
        }

        // 前一秒仍然有效 —— 证明上面那条卡在边界上，而不是整体早了一截。
        $this->verifier($provider)->verify($token, self::now()->modify('+899 seconds'));
    }

    // ========================================================================
    // 篡改与畸形
    // ========================================================================

    /**
     * 三段各改一个字符。任何一段被动过，签名都不该过。
     */
    #[DataProvider('tamperedSegments')]
    public function testRejectsATamperedToken(int $segment): void
    {
        $provider = self::provider();
        $parts = explode('.', self::sign(self::claims(), $provider));

        // 翻转该段的最后一个字符（在 base64url 字母表内，保证形状仍然合法 ——
        // 我们要测的是签名校验，不是「解不开就拒」）。
        $parts[$segment] = substr($parts[$segment], 0, -1).('A' === substr($parts[$segment], -1) ? 'B' : 'A');

        $this->assertRejectedAsInvalid(implode('.', $parts), $provider);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function tamperedSegments(): iterable
    {
        yield 'header' => [0];
        yield 'payload' => [1];
        yield 'signature' => [2];
    }

    #[DataProvider('malformedTokens')]
    public function testRejectsAMalformedToken(string $token): void
    {
        $this->assertRejectedAsInvalid($token);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedTokens(): iterable
    {
        yield 'empty' => [''];
        yield 'one segment' => ['abc'];
        yield 'two segments' => ['abc.def'];
        yield 'four segments' => ['a.b.c.d'];
        yield 'not base64url' => ['!!!.???.***'];
        // ⚠️ 顶层是数组而不是对象。不拦的话会一路走到数组访问才以一个
        // 费解的类型错误炸掉（500），而它其实只是一枚畸形 token（401）。
        yield 'header is a json list' => ['WzEsMl0.WzEsMl0.AAAA'];
    }

    // ========================================================================
    // claim 集是封闭的
    // ========================================================================

    /**
     * ⚠️ 双向：少一个是残缺，**多一个**说明签名侧偷偷加了 claim
     * （见 AccessTokenClaims 的类注释：那是一扇单向门）。
     *
     * 这两条用真密钥签，所以过不去的原因只可能是 claim 集，不是签名。
     */
    #[DataProvider('wrongClaimSets')]
    public function testRejectsAWrongClaimSet(callable $mutate): void
    {
        $provider = self::provider();

        /** @var array<string, mixed> $payload */
        $payload = $mutate(self::claims()->toPayload());

        $this->assertRejectedAsInvalid(self::signPayload($payload, $provider), $provider);
    }

    /**
     * @return iterable<string, array{callable}>
     */
    public static function wrongClaimSets(): iterable
    {
        yield 'missing sid' => [static function (array $p): array {
            unset($p['sid']);

            return $p;
        }];

        yield 'extra claim smuggled in' => [static function (array $p): array {
            // 这正是我们要挡的东西：一个带 email 的 JWT 会躺在
            // EncryptedSharedPreferences、崩溃日志和任何抓过包的中间层里。
            $p['email'] = 'anna@example.de';

            return $p;
        }];

        yield 'iat as a string' => [static function (array $p): array {
            $p['iat'] = (string) $p['iat'];

            return $p;
        }];

        // ⚠️ 这一条挡的是一个**本仓库特有**的真实风险：这里到处都是毫秒时间戳
        // （FrozenClock 的构造参数、Uuid7Generator、timestampMillis()），
        // 而 RFC 7519 的 NumericDate 是秒。把 claims 改成毫秒的话，`is_int`
        // 照样通过、exp 变成公元 55000 年 —— 每一枚 token 都永不过期。
        // 验签器靠「iat 不能在未来」把它拒掉。
        yield 'timestamps in milliseconds' => [static function (array $p): array {
            $p['iat'] *= 1000;
            $p['exp'] *= 1000;

            return $p;
        }];

        yield 'issued in the future' => [static function (array $p): array {
            $p['iat'] += 60;

            return $p;
        }];

        yield 'sub is not a uuid' => [static function (array $p): array {
            $p['sub'] = 'not-a-uuid';

            return $p;
        }];
    }

    /**
     * ⚠️ 一个 `sub` 写坏的 token 必须是 401 而不是 422。
     *
     * `Uuid::fromString()` 抛的是 `validation_failed`（400，带一个名为 `id`
     * 的字段错误）—— 那会成为一条能把伪造 token 与真 token 区分开的信道，
     * 也会让客户端以为是自己的请求体有问题。所以实现必须用 `tryFromString()`。
     */
    public function testABrokenUuidClaimIsUnauthorisedNotUnprocessable(): void
    {
        $provider = self::provider();
        $payload = self::claims()->toPayload();
        $payload['did'] = 'clearly-not-a-uuid';

        try {
            $this->verifier($provider)->verify(self::signPayload($payload, $provider), self::now());
            self::fail('A malformed uuid claim must be rejected.');
        } catch (DomainException $e) {
            self::assertSame(ErrorCode::TokenInvalid, $e->errorCode());
            self::assertSame([], $e->fieldErrors(), 'A rejected token must not leak field-level errors.');
        }
    }

    // ========================================================================
    // 取不到密钥 ≠ 令牌无效
    // ========================================================================

    /**
     * ⚠️ Vault 挂了要 503，**不能**压成 401。
     *
     * 压成 401 的后果：一次 Vault 故障会让全体客户端清空本地会话、退回登录页，
     * 而登录本身也是坏的（同一个 Vault）—— 一次可恢复的运维事件被放大成
     * 全体用户重新走邮箱验证码。
     */
    public function testPropagatesAKeyProviderOutageAsUnavailable(): void
    {
        $provider = self::provider();
        $token = self::sign(self::claims(), $provider);

        $provider->failWith(new CryptoUnavailable('The JWT signing key could not be read.'));

        $this->expectException(CryptoUnavailable::class);

        $this->verifier($provider)->verify($token, self::now());
    }

    // ========================================================================
    // 支撑
    // ========================================================================

    private function assertRejectedAsInvalid(string $token, ?StaticSigningKeyProvider $provider = null): void
    {
        try {
            $this->verifier($provider ?? self::provider())->verify($token, self::now());
            self::fail('The token must be rejected.');
        } catch (DomainException $e) {
            self::assertSame(ErrorCode::TokenInvalid, $e->errorCode());
            // 全部拒绝情形共用一句文案 —— 可辨认的差异等于给攻击者一个预言机。
            self::assertSame(AccessTokenVerifierInterface::REJECTED, $e->getMessage());
        }
    }

    private function verifier(StaticSigningKeyProvider $provider): Ed25519AccessTokenVerifier
    {
        return new Ed25519AccessTokenVerifier($provider);
    }

    private static function provider(): StaticSigningKeyProvider
    {
        return StaticSigningKeyProvider::fromSeed(kid: self::KID);
    }

    private static function sign(AccessTokenClaims $claims, StaticSigningKeyProvider $provider): string
    {
        return (new Ed25519AccessTokenSigner($provider))->sign($claims)->token;
    }

    /**
     * 用真密钥签一个**任意** payload —— 用于把「claim 集不对」与「签名不对」
     * 这两件事分开测。
     *
     * @param array<string, mixed> $payload
     */
    private static function signPayload(array $payload, StaticSigningKeyProvider $provider): string
    {
        $header = self::encode(['alg' => 'EdDSA', 'typ' => 'JWT', 'kid' => $provider->currentKey()->kid]);
        $signingInput = $header.'.'.self::encode($payload);

        $keypair = sodium_crypto_sign_seed_keypair($provider->currentKey()->seed);

        return $signingInput.'.'.self::base64UrlEncode(
            sodium_crypto_sign_detached($signingInput, sodium_crypto_sign_secretkey($keypair)),
        );
    }

    /**
     * @param array<string, mixed> $header
     */
    private static function forgeWithHeader(array $header): string
    {
        return self::assemble($header, self::claims()->toPayload(), str_repeat("\x00", 64));
    }

    /**
     * @param array<string, mixed> $header
     * @param array<string, mixed> $payload
     */
    private static function assemble(array $header, array $payload, string $signature): string
    {
        return self::encode($header).'.'.self::encode($payload).'.'.self::base64UrlEncode($signature);
    }

    /**
     * @param array<string, mixed> $value
     */
    private static function encode(array $value): string
    {
        return self::base64UrlEncode(json_encode($value, \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR));
    }

    private static function base64UrlEncode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function claims(?\DateTimeImmutable $issuedAt = null): AccessTokenClaims
    {
        $at = $issuedAt ?? self::now();

        return new AccessTokenClaims(
            IdentityEntities::id(1),
            IdentityEntities::id(2),
            IdentityEntities::id(3),
            IdentityEntities::id(4),
            $at,
            $at->modify('+900 seconds'),
        );
    }

    private static function now(): \DateTimeImmutable
    {
        return IdentityEntities::now(self::NOW);
    }
}
