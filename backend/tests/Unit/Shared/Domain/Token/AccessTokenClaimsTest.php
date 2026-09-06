<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Domain\Token;

use App\Shared\Domain\Token\AccessTokenClaims;
use App\Tests\Double\Identity\IdentityEntities;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AccessTokenClaims::class)]
final class AccessTokenClaimsTest extends TestCase
{
    /**
     * ⚠️ 键名是**契约**（`docs/api/openapi.yaml` 的 `bearerAuth` 描述逐字列了
     * `sub, sid, did, jti, iat, exp`），不是实现细节。改一个字，
     * 全部在线的 Android 客户端在 T-108 的鉴权里就对不上。
     *
     * ⚠️ 那份键集在**这里**断言不了 —— `toPayload()` 的返回类型已经是一个
     * `array{sub: string, ...}` 形状，PHPStan 会把断言常量折叠掉并报
     * `staticMethod.alreadyNarrowedType`。真正的强制点因此在两处：
     *   - 类型系统（那个 `array{...}` 本身）
     *   - `Ed25519AccessTokenSignerTest::testThePayloadCarriesExactlyTheSpecClaims()`
     *     —— 它 decode 的是签出来的 JSON，PHPStan 折不动。
     */
    public function testEachIdLandsOnItsOwnClaim(): void
    {
        $payload = self::claims()->toPayload();

        self::assertSame(IdentityEntities::id(1)->toString(), $payload['sub']);
        self::assertSame(IdentityEntities::id(2)->toString(), $payload['sid']);
        self::assertSame(IdentityEntities::id(3)->toString(), $payload['did']);
        self::assertSame(IdentityEntities::id(4)->toString(), $payload['jti']);
    }

    /**
     * RFC 7519：NumericDate 是**秒**。毫秒会让 token 的有效期变成 3 万年，
     * 而没有任何验签方会报错 —— 它们只会认为这枚 token 还没过期。
     */
    public function testTimestampsAreSecondsSinceEpoch(): void
    {
        $payload = self::claims()->toPayload();

        self::assertSame(1788696000, $payload['iat']);
        self::assertSame(1788696000 + 900, $payload['exp']);
    }

    /**
     * `expires_in` 由 `exp - iat` 算而不是回填配置常量 —— 两者必须同源，
     * 否则「配置改了 15 分钟、响应体还说 900」这种偏差没有任何测试会红。
     */
    public function testExpiresInIsDerivedFromTheClaimWindow(): void
    {
        self::assertSame(900, self::claims()->expiresInSeconds());
    }

    private static function claims(): AccessTokenClaims
    {
        // 2026-09-06T12:00:00Z
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
}
