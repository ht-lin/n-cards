<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared\Vault;

use App\Shared\Domain\Crypto\CryptoKey;
use App\Shared\Domain\Crypto\CryptoUnavailable;
use App\Shared\Infrastructure\Crypto\VaultHmacHasher;
use App\Shared\Infrastructure\Crypto\VaultTransitCrypto;
use App\Shared\Infrastructure\Token\VaultKvSigningKeyProvider;
use App\Shared\Infrastructure\Vault\AppRoleTokenProvider;
use App\Shared\Infrastructure\Vault\VaultClient;
use App\Shared\Infrastructure\Vault\VaultTokenProviderInterface;
use App\Tests\Double\Time\FrozenClock;
use App\Tests\Integration\Support\RequiresVault;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\HttpClient;

/**
 * §17.4 的最小权限 policy 是**真的**最小权限吗。
 *
 * ============================================================================
 * 为什么正反两面都要测
 * ============================================================================
 * 只测「能加解密」，证明的是 policy 不太紧 —— 一个 `path "*" { capabilities = ["root"] }`
 * 也能让那些用例全绿。§17.4 那份清单的价值**全部在于它没有什么**
 * （见 infra/vault/policies/ncards-app.hcl 顶部），所以必须有用例断言
 * 那几条「显式不授予」确实被拒。
 *
 * 这是本仓库里唯一一处验证 policy 文件真实效果的地方 ——
 * 别的所有测试都用 dev 模式的 root token 跑，policy 对它们完全不起作用。
 *
 * ⚠️ 本用例走的也是**真实的 AppRole 登录路径**（AppRoleTokenProvider），
 * 而生产用的正是它。开发与 CI 平时都走 StaticTokenProvider，
 * 所以这里是 AppRole 那条路径在 CI 里唯一一次真实执行。
 */
#[CoversClass(AppRoleTokenProvider::class)]
#[CoversClass(VaultClient::class)]
#[CoversClass(VaultTransitCrypto::class)]
#[CoversClass(VaultHmacHasher::class)]
#[CoversClass(VaultKvSigningKeyProvider::class)]
final class AppRolePolicyTest extends TestCase
{
    use RequiresVault;

    private VaultClient $appClient;

    protected function setUp(): void
    {
        $rootClient = $this->vaultClient();
        $this->assertVaultBootstrapped($rootClient);

        $this->appClient = new VaultClient(
            HttpClient::create(),
            $this->appRoleTokenProvider($rootClient),
            $this->vaultAddress(),
        );
    }

    // ========================================================================
    // 正面：policy 不能太紧 —— 应用真正要用的四条路径都得通
    // ========================================================================

    public function testAppRoleCanEncryptAndDecryptCardPayloads(): void
    {
        $crypto = new VaultTransitCrypto($this->appClient);
        $ciphertext = $crypto->encrypt(CryptoKey::Card, '4012345678901');

        self::assertSame('4012345678901', $crypto->decrypt(CryptoKey::Card, $ciphertext));
    }

    public function testAppRoleCanEncryptAndDecryptPii(): void
    {
        $crypto = new VaultTransitCrypto($this->appClient);
        $ciphertext = $crypto->encrypt(CryptoKey::Pii, 'anna@example.de');

        self::assertSame('anna@example.de', $crypto->decrypt(CryptoKey::Pii, $ciphertext));
    }

    public function testAppRoleCanComputeHmacs(): void
    {
        self::assertSame(32, \strlen((new VaultHmacHasher($this->appClient))->hash('anna@example.de')));
    }

    /**
     * ⚠️ T-104 给 policy 新开的**唯一**一条可读路径，也是这份清单里
     * 唯一一条让密钥材料离开 Vault 的路径（理由与代价写在 ncards-app.hcl 里）。
     *
     * 没有它，`VaultKvSigningKeyProvider` 在生产上会 503，
     * 而本地与 CI 全绿 —— 那两处走的是 StaticTokenProvider + root token，
     * policy 对它们完全不起作用。这个类是**唯一**能发现那个差别的地方。
     */
    public function testAppRoleCanReadTheJwtSigningKey(): void
    {
        $key = (new VaultKvSigningKeyProvider(
            $this->appClient,
            new FrozenClock((new \DateTimeImmutable('2026-09-06T12:00:00+00:00'))->getTimestamp() * 1000),
            'ncards/jwt/current',
            cacheTtlSeconds: 300,
        ))->currentKey();

        self::assertSame(32, \strlen($key->seed));
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $key->kid);
    }

    /**
     * `auth/token/renew-self` 是 §17.4 里唯一一条非 transit 路径。
     * 没有它，token 每小时到期后只能重新走 AppRole login。
     */
    public function testAppRoleCanRenewItsOwnToken(): void
    {
        $data = $this->appClient->write('auth/token/renew-self', []);

        // renew-self 的响应把内容放在 auth 而不是 data 里，所以 write() 返回空数组；
        // 没抛异常就说明这条路径是通的 —— 那正是我们要断言的。
        self::assertSame([], $data);
    }

    // ========================================================================
    // 反面：policy 不能太松 —— 「显式不授予」的那几条必须被拒
    // ========================================================================

    /**
     * 每一条都对应 ncards-app.hcl 顶部注释里的一条论证。
     *
     * 断言的是 {@see CryptoUnavailable}：VaultClient 对重登之后仍然 403 的请求
     * 归 503（应用侧无 bug，是 Vault 配置要修）。
     *
     * @param array<string, mixed> $payload
     */
    #[DataProvider('forbiddenPaths')]
    public function testAppRoleIsDeniedPrivilegedPaths(string $path, array $payload, string $why): void
    {
        $this->expectException(CryptoUnavailable::class);
        $this->expectExceptionMessageMatches('/denied|policy/i');

        $this->appClient->write($path, $payload);
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>, string}>
     */
    public static function forbiddenPaths(): iterable
    {
        yield '轮换 ncards-card' => [
            'transit/keys/ncards-card/rotate',
            [],
            '轮换是运维动作，归 ncards-ops。一次误操作或入侵就能给 key 转版本。',
        ];

        yield '轮换 ncards-hmac' => [
            'transit/keys/ncards-hmac/rotate',
            [],
            '⚠️ 最危险的一条：轮换它会让全部 email_hash 查找失效，且 HMAC 不可逆无从补救（§5.3）。',
        ];

        yield 'rewrap ncards-card' => [
            'transit/rewrap/ncards-card',
            ['ciphertext' => 'vault:v1:x'],
            'rewrap = 重加密全库的能力，归 ncards-ops。T-404 用那个身份跑。',
        ];

        yield '导出密钥材料' => [
            'transit/export/encryption-key/ncards-card',
            [],
            '最坏的一条：导出了密钥材料，攻击者就能离线解密全部备份 —— §5.3「密钥永不出 Vault」不成立。',
        ];

        yield '改 key 配置' => [
            'transit/keys/ncards-card/config',
            ['min_decryption_version' => 2],
            '提升 min_decryption_version 会让旧版本密文立刻不可读。',
        ];

        yield '封印 Vault' => [
            'sys/seal',
            [],
            '给应用一个「一条命令让全站挂掉」的按钮没有任何道理。',
        ];

        yield '写 policy' => [
            'sys/policies/acl/ncards-app',
            ['policy' => 'path "*" { capabilities = ["root"] }'],
            '能改自己的 policy 就等于没有 policy —— 提权到 root 只差一次请求。',
        ];

        yield '用他人的 key 加密' => [
            'transit/encrypt/some-other-key',
            ['plaintext' => ''],
            'policy 是按 key 名逐条授予的，不是给整个 transit/ 挂通配符。',
        ];

        yield '覆盖 JWT 签名密钥' => [
            'secret/data/ncards/jwt/current',
            ['data' => ['private_key' => 'attacker', 'kid' => 'x', 'algorithm' => 'EdDSA']],
            '⚠️ 只给 read。能写就能把签名密钥换成自己的，然后为任意用户签 token —— '
            .'那比读走它更糟：受害者的会话不会失效，没有任何症状。',
        ];
    }

    /**
     * KV 那条授权是**逐条路径**给的，不是 `secret/data/ncards/jwt/*`，
     * 更不是 `secret/data/*`。
     *
     * ⚠️ `secret/metadata/*` 尤其不给：那是版本列表与历史版本的读取能力，
     * 也就是把「拿到当前密钥」升级成「拿到全部曾经用过的密钥」——
     * 而 §5.3 的 6 个月轮换正是靠「旧密钥退役」来限制一次泄露的时间窗的。
     *
     * @param string $path 一条**不该**可读的 KV 路径
     */
    #[DataProvider('forbiddenKvPaths')]
    public function testAppRoleCannotReachNeighbouringKvPaths(string $path, string $why): void
    {
        $this->expectException(CryptoUnavailable::class);
        $this->expectExceptionMessageMatches('/denied|policy/i');

        $this->appClient->read($path);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function forbiddenKvPaths(): iterable
    {
        yield '密钥的历史版本' => [
            'secret/metadata/ncards/jwt/current',
            '版本列表 + 历史版本 = 全部曾经用过的密钥，那让 §5.3 的轮换失去意义。',
        ];

        yield 'JWT 命名空间下的别的路径' => [
            'secret/data/ncards/jwt/previous',
            '重叠期的旧密钥将来可能出现在这里 —— 到那天要显式加一行，'
            .'并顺便问一次「验签方真的需要读私钥吗」（不需要，它只要公钥）。',
        ];

        yield '别的 secret' => [
            'secret/data/ncards/anything-else',
            'policy 没有 secret/data/* 通配 —— 将来任何一条新 secret 都要单独授权。',
        ];
    }

    /**
     * 读 key 元数据（`transit/keys/<name>`）也不给 —— 应用没有任何需要知道
     * key 版本的场景，而那是 ncards-ops 判断 rewrap 进度用的。
     *
     * 单独一条而不放进上面的 provider：它是 GET，走 {@see VaultClient::read()}。
     */
    public function testAppRoleCannotReadKeyMetadata(): void
    {
        $this->expectException(CryptoUnavailable::class);
        $this->expectExceptionMessageMatches('/denied|policy/i');

        $this->appClient->read('transit/keys/ncards-card');
    }

    // ========================================================================
    // 夹具：用 root token 现场生成一个 secret_id，再以 ncards-app 身份登录
    // ========================================================================

    private function appRoleTokenProvider(VaultClient $rootClient): VaultTokenProviderInterface
    {
        // role-id 是 GET（读元数据）；secret-id 是 POST（生成一个新的）。
        $roleIdData = $rootClient->read('auth/approle/role/ncards-app/role-id');
        $roleId = $roleIdData['role_id'] ?? null;

        if (!\is_string($roleId)) {
            self::fail('拿不到 ncards-app 的 role_id —— bootstrap.sh 的 AppRole 那一步没跑成功？');
        }

        // ⚠️ secret_id 是凭据。这里是测试环境的一次性值，用完即弃，不落盘、不打印。
        $secretIdData = $rootClient->write('auth/approle/role/ncards-app/secret-id', []);
        $secretId = $secretIdData['secret_id'] ?? null;

        if (!\is_string($secretId)) {
            self::fail('生成不出 secret_id —— bootstrap.sh 的 AppRole 那一步没跑成功？');
        }

        return new AppRoleTokenProvider(
            HttpClient::create(),
            new FrozenClock(1_787_000_000_000),
            $this->vaultAddress(),
            $roleId,
            $secretId,
        );
    }
}
