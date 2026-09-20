<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared\Vault;

use App\Shared\Domain\Crypto\CryptoUnavailable;
use App\Shared\Infrastructure\Vault\AppRoleTokenProvider;
use App\Shared\Infrastructure\Vault\VaultClient;
use App\Shared\Infrastructure\Vault\VaultTokenProviderInterface;
use App\Tests\Double\Time\FrozenClock;
use App\Tests\Integration\Support\RequiresVault;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\HttpClient;

/**
 * `ncards-policy` 这个身份真的只能读写 policy 吗（T-114 / §17.4）。
 *
 * ============================================================================
 * 这个类守的是什么
 * ============================================================================
 * T-114 给部署流水线加了一个能**改写 Vault policy** 的身份
 * （`infra/vault/policy-sync.sh`，每次部署自动跑）。那是本项目里授权最敏感的
 * 一次扩张，所以它单独一份 policy、单独一个 AppRole，而不是挂在 `ncards-ops`
 * 上 —— 后者持有 `transit/keys/+/rotate`，通配符覆盖到 `ncards-hmac`，
 * 而轮换那把 key 会让全部 `email_hash` 查找失效且**不可补救**
 * （见 {@see \App\Shared\Domain\Crypto\CryptoKey::isRotatable()}）。
 *
 * **那次分家没有任何代码强制它。** 往
 * `infra/vault/policies/ncards-policy.hcl` 里粘一条 transit 路径，或者哪天
 * 图省事把 bootstrap.sh 里的 `token_policies` 改回 `["ncards-ops"]`，
 * 都不会让任何东西变红 —— 部署照跑，直到某次事故才发现部署凭据一直握着
 * 「一条命令毁掉全部账号查找」。这个类就是那道红线。
 *
 * 与 {@see AppRolePolicyTest} 是同一个形状、同一个理由（那边守 `ncards-app`），
 * 两边都**正反面都测**：只测「能读 policy」的话，一个
 * `path "*" { capabilities = ["sudo"] }` 也能全绿。
 *
 * ⚠️ 与那边一样，这里走的是**真实的 AppRole 登录路径**。开发与 CI 平时都用
 * StaticTokenProvider + root token，policy 对它们完全不起作用。
 *
 * `CoversNothing`：本类验的是 `.hcl` 文件与 Vault 的实际授权，不是某个 PHP 类
 * 的行为 —— 被覆盖的"生产代码"在 infra/ 下。
 */
#[CoversNothing]
final class PolicyAppRoleTest extends TestCase
{
    use RequiresVault;

    private VaultClient $policyClient;

    protected function setUp(): void
    {
        $rootClient = $this->vaultClient();
        $this->assertVaultBootstrapped($rootClient);

        $this->policyClient = new VaultClient(
            HttpClient::create(),
            $this->policyRoleTokenProvider($rootClient),
            $this->vaultAddress(),
        );
    }

    // ========================================================================
    // 正面：它得真的能干活，否则每次部署都红
    // ========================================================================

    /**
     * 能读的那三份 —— 对账（`policy-sync.sh check`）靠的就是这个。
     */
    #[DataProvider('readablePolicies')]
    public function testCanReadEveryPolicyItReconciles(string $name): void
    {
        $data = $this->policyClient->read("sys/policies/acl/{$name}");

        self::assertIsString($data['policy'] ?? null, "读不到 {$name} 的内容，对账无从谈起。");
        self::assertNotSame('', $data['policy']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function readablePolicies(): iterable
    {
        yield 'ncards-app' => ['ncards-app'];
        yield 'ncards-ops' => ['ncards-ops'];
        yield 'ncards-policy（自己）' => ['ncards-policy'];
    }

    /**
     * 能**写**的那两份 —— 下发（`policy-sync.sh push`）靠的是这个。
     *
     * ⚠️ 写回去的就是刚读出来的同一份内容，所以这条用例不改变任何状态。
     * 拿一个构造出来的 policy 文本去写会让后续用例（以及同一个 CI run 里的
     * {@see AppRolePolicyTest}）跑在一份被改过的 policy 上 —— 那种跨用例的
     * 耦合排查起来极其费时，不值得为了「写得更真」去冒。
     */
    #[DataProvider('writablePolicies')]
    public function testCanRewriteThePoliciesItDelivers(string $name): void
    {
        $current = $this->policyClient->read("sys/policies/acl/{$name}");
        $policy = $current['policy'];
        self::assertIsString($policy);

        $this->policyClient->write("sys/policies/acl/{$name}", ['policy' => $policy]);

        $after = $this->policyClient->read("sys/policies/acl/{$name}");
        self::assertSame($policy, $after['policy'] ?? null, '原样写回之后内容变了？');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function writablePolicies(): iterable
    {
        yield 'ncards-app' => ['ncards-app'];
        yield 'ncards-ops' => ['ncards-ops'];
    }

    // ========================================================================
    // 反面之一：**一条 transit 都不能有**，这是分家的全部意义
    // ========================================================================

    /**
     * 每一条都对应 `ncards-policy.hcl` 抬头那段「为什么不复用 ncards-ops」。
     *
     * ⚠️ 这些路径**在 `ncards-ops` 上是允许的**。也就是说，哪天有人把
     * bootstrap.sh 里的 `token_policies` 改回 `["ncards-ops"]`，
     * 或者把两条 `sys/policies/acl/…` 搬回 ncards-ops.hcl，
     * 本组用例会立刻红 —— 那正是它存在的理由。
     *
     * @param array<string, mixed> $payload
     */
    #[DataProvider('forbiddenTransitPaths')]
    public function testPolicyIdentityHasNoTransitCapabilityAtAll(
        string $path,
        array $payload,
        string $why,
    ): void {
        $this->expectException(CryptoUnavailable::class);
        $this->expectExceptionMessageMatches('/denied|policy/i');

        $this->policyClient->write($path, $payload);
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>, string}>
     */
    public static function forbiddenTransitPaths(): iterable
    {
        yield '轮换 ncards-hmac' => [
            'transit/keys/ncards-hmac/rotate',
            [],
            '⚠️⚠️ 分家要挡的就是这一条：轮换它会让全部 email_hash 查找失效，'
            .'HMAC 单向不可补救（§5.3）。它在 ncards-ops 上是被允许的。',
        ];

        yield '轮换 ncards-card' => [
            'transit/keys/ncards-card/rotate',
            [],
            '轮换是运维动作，归 ncards-ops 与 T-404，不该在每次部署都用的凭据上。',
        ];

        yield 'rewrap' => [
            'transit/rewrap/ncards-card',
            ['ciphertext' => 'vault:v1:x'],
            '重加密全库的能力。同样归 ncards-ops。',
        ];

        yield '改 key 配置' => [
            'transit/keys/ncards-card/config',
            ['min_decryption_version' => 2],
            '提升 min_decryption_version 会让旧版本密文立刻不可读。',
        ];

        yield '加密' => [
            'transit/encrypt/ncards-card',
            ['plaintext' => ''],
            '下发 policy 不需要任何数据面能力 —— 连 encrypt 都不给。',
        ];

        yield '导出密钥材料' => [
            'transit/export/encryption-key/ncards-card',
            [],
            '最坏的一条：导出了就能离线解密全部备份。',
        ];

        yield '封印 Vault' => [
            'sys/seal',
            [],
            '「一条命令让全站挂掉」的按钮，谁都不该有。',
        ];
    }

    // ========================================================================
    // 反面之二：不能改自己那份 policy —— 否则上面所有收窄都是摆设
    // ========================================================================

    /**
     * ⚠️⚠️ **这一条是整份 `ncards-policy.hcl` 的支点。**.
     *
     * 有 `update` 就能把自己改写成 `path "*" { capabilities = [..., "sudo"] }`，
     * 于是上面每一条 403 都能被**一次请求**抹掉 ——
     * 能改自己的 policy 就等于没有 policy。
     *
     * 代价是真实的、也是刻意的：改 `ncards-policy.hcl` 本身要一次
     * `vault operator generate-root`（3 位 unseal key 持有人到场）。
     * `policy-sync.sh` 对这个 403 的处置是「这一份不写，但照样对账」——
     * 把它当失败的话每次部署都会红，见那里的注释。
     */
    public function testCannotRewriteItsOwnPolicy(): void
    {
        $this->expectException(CryptoUnavailable::class);
        $this->expectExceptionMessageMatches('/denied|policy/i');

        $this->policyClient->write('sys/policies/acl/ncards-policy', [
            'policy' => 'path "*" { capabilities = ["create","read","update","delete","list","sudo"] }',
        ]);
    }

    /**
     * 授权是**逐条**给的，不是 `sys/policies/acl/*` 通配。
     *
     * 将来新增一份 policy 时要显式往 ncards-policy.hcl 里加一行 ——
     * 漏加的症状是部署时那一份报 403 并降级成只对账（看得见），
     * 而不是「悄悄地对了个空账」。
     */
    public function testHasNoWildcardOverPolicyPaths(): void
    {
        $this->expectException(CryptoUnavailable::class);
        $this->expectExceptionMessageMatches('/denied|policy/i');

        $this->policyClient->read('sys/policies/acl/some-future-policy');
    }

    /**
     * 没有 `list` —— 它拿不到「Vault 里都有哪些 policy」这张表。
     *
     * 对账的清单来自**仓库里的** `policies/*.hcl`，不来自 Vault。方向是刻意的：
     * 仓库是真相源，Vault 里多出来的东西应该由人去解释，而不是被自动化默认接受。
     */
    public function testCannotListPolicies(): void
    {
        $this->expectException(CryptoUnavailable::class);
        $this->expectExceptionMessageMatches('/denied|policy/i');

        $this->policyClient->read('sys/policies/acl');
    }

    // ========================================================================
    // 夹具：用 root token 现场生成一个 secret_id，再以 ncards-policy 身份登录
    // ========================================================================

    private function policyRoleTokenProvider(VaultClient $rootClient): VaultTokenProviderInterface
    {
        $roleIdData = $rootClient->read('auth/approle/role/ncards-policy/role-id');
        $roleId = $roleIdData['role_id'] ?? null;

        if (!\is_string($roleId)) {
            self::fail(
                '拿不到 ncards-policy 的 role_id —— bootstrap.sh 第 5 节的第二个 AppRole 没跑成功？'
                .'（这个 AppRole 是 T-114 加的，老的 Vault 数据卷上可能还没有它。）'
            );
        }

        // ⚠️ secret_id 是凭据。这里是测试环境的一次性值，用完即弃，不落盘、不打印。
        $secretIdData = $rootClient->write('auth/approle/role/ncards-policy/secret-id', []);
        $secretId = $secretIdData['secret_id'] ?? null;

        if (!\is_string($secretId)) {
            self::fail('生成不出 ncards-policy 的 secret_id —— bootstrap.sh 的 AppRole 那一步没跑成功？');
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
