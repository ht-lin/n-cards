<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared\Token;

use App\Shared\Domain\Crypto\CryptoUnavailable;
use App\Shared\Domain\Token\AccessTokenClaims;
use App\Shared\Infrastructure\Token\Ed25519AccessTokenSigner;
use App\Shared\Infrastructure\Token\VaultKvSigningKeyProvider;
use App\Tests\Double\Identity\IdentityEntities;
use App\Tests\Double\Time\FrozenClock;
use App\Tests\Integration\Support\RequiresVault;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * 真 Vault KV 上的往返：**bootstrap.sh 写下的那把密钥，我们读得出来、也签得动**。
 *
 * ============================================================================
 * 这条用例盯的是一处**跨仓库的接线**
 * ============================================================================
 * `infra/vault/bootstrap.sh` 用 `openssl genpkey` 生成密钥、用 `jq` 组 JSON、
 * 写进 KV v2；PHP 侧再把它读回来、切出 32 字节 seed。中间有四个约定：
 *
 *   1. **路径**：`ncards/jwt/current`（脚本的 `JWT_KV_PATH`
 *      ↔ `config/packages/ncards_jwt.yaml` 的 `ncards.jwt.kv_path`）
 *   2. **字段名**：`private_key` / `public_key` / `kid` / `algorithm`
 *   3. **编码**：PKCS#8 PEM（`openssl genpkey` 的默认输出）
 *   4. **KV v2 的 `data/` 段**与响应里那层多出来的 `data` 包装
 *
 * 四个里任何一个对不上，单测都发现不了（那边用的是本文件里的固定夹具），
 * 症状是「刚建的栈登录不了」，而 Vault 只回一个 404。
 *
 * ⚠️ 用 root token 跑。**AppRole 那份 policy 到底给没给这条读权限**是另一个问题，
 * 由 `tests/Integration/Shared/Vault/AppRolePolicyTest` 单独盯 —— 那是本仓库
 * 唯一以 ncards-app 身份跑的地方。
 */
#[CoversClass(VaultKvSigningKeyProvider::class)]
#[CoversClass(Ed25519AccessTokenSigner::class)]
final class VaultKvSigningKeyProviderTest extends TestCase
{
    use RequiresVault;

    /** 与 infra/vault/bootstrap.sh 的 JWT_KV_PATH 逐字相同。 */
    private const KV_PATH = 'ncards/jwt/current';

    public function testReadsTheKeyBootstrapWrote(): void
    {
        $key = $this->provider()->currentKey();

        self::assertSame(32, \strlen($key->seed));
        // bootstrap.sh 从公钥的 SHA-256 里取前 16 个 hex 字符。
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $key->kid);
    }

    /**
     * ⚠️ 本文件最重要的一条：**用这把私钥签出来的 token，能被 KV 里那份公钥验过**。
     *
     * 切错位置仍然会得到 32 字节、`sodium` 也照样接受，只是推出来的公钥不同 ——
     * 于是线上签出的每一枚 token 谁也验不过，而所有单测仍然全绿
     * （那边是自己签自己验）。这条把那种错误变成 CI 里的一次失败。
     */
    public function testATokenSignedWithTheStoredKeyVerifiesAgainstTheStoredPublicKey(): void
    {
        $client = $this->vaultClient();
        $this->assertVaultBootstrapped($client);

        /** @var array{data?: array{public_key?: string}} $envelope */
        $envelope = $client->read('secret/data/'.self::KV_PATH);
        $publicPem = $envelope['data']['public_key'] ?? null;

        self::assertIsString($publicPem, 'bootstrap.sh 应该把 public_key 一并写进 KV。');

        $now = IdentityEntities::now();
        $token = (new Ed25519AccessTokenSigner($this->provider()))->sign(new AccessTokenClaims(
            IdentityEntities::id(1),
            IdentityEntities::id(2),
            IdentityEntities::id(3),
            IdentityEntities::id(4),
            $now,
            $now->modify('+900 seconds'),
        ))->token;

        [$header, $payload, $signature] = explode('.', $token);

        self::assertTrue(
            sodium_crypto_sign_verify_detached(
                self::nonEmpty((string) base64_decode(strtr($signature, '-_', '+/'), true)),
                $header.'.'.$payload,
                self::nonEmpty(VaultKvSigningKeyProvider::publicKeyFromSpkiPem($publicPem)),
            ),
            'The token must verify against the public key Vault stores next to the private one.',
        );
    }

    /**
     * KV v2 的读路径要插一段 `data/`。少了那一段会打到 KV v1 的形状上，
     * Vault 回 404 —— 而 404 与「密钥不存在」无法区分。
     *
     * 这条用一个**不存在**的路径证明「读不到就是 503，不是一个空密钥」：
     * 静默回落到某个默认值会让全站签出无法验证的 token。
     */
    public function testAMissingKeyIsAServiceFailureNotASilentDefault(): void
    {
        $provider = $this->provider('ncards/jwt/does-not-exist');

        $this->expectException(CryptoUnavailable::class);

        $provider->currentKey();
    }

    /**
     * 缓存带 TTL：§5.3 的双密钥重叠期只有 24h，永久缓存会让轮换在**一天后**
     * 才以「这个 worker 签的 token 全被拒」的形式显形。
     *
     * 时钟是注入的，所以这条不用真的等 —— 推 TTL + 1 秒就够。
     */
    public function testTheCachedKeyExpiresSoThatRotationTakesEffect(): void
    {
        $clock = new FrozenClock((new \DateTimeImmutable('2026-09-06T12:00:00+00:00'))->getTimestamp() * 1000);
        $client = $this->vaultClient();
        $this->assertVaultBootstrapped($client);

        $provider = new VaultKvSigningKeyProvider($client, $clock, self::KV_PATH, cacheTtlSeconds: 300);

        $first = $provider->currentKey();

        // TTL 之内：同一个对象，一次 Vault 往返都没多花。
        $clock->advance(299_000);
        self::assertSame($first, $provider->currentKey());

        // 越过 TTL：重新读一次。值相同（密钥没换），但**不是**同一个实例。
        $clock->advance(2_000);
        $refetched = $provider->currentKey();

        self::assertNotSame($first, $refetched, 'The provider must re-read Vault once the TTL lapses.');
        self::assertSame($first->kid, $refetched->kid);
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

    private function provider(string $kvPath = self::KV_PATH): VaultKvSigningKeyProvider
    {
        $client = $this->vaultClient();
        $this->assertVaultBootstrapped($client);

        return new VaultKvSigningKeyProvider(
            $client,
            new FrozenClock((new \DateTimeImmutable('2026-09-06T12:00:00+00:00'))->getTimestamp() * 1000),
            $kvPath,
            cacheTtlSeconds: 300,
        );
    }
}
