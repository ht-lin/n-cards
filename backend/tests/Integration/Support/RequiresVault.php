<?php

declare(strict_types=1);

namespace App\Tests\Integration\Support;

use App\Shared\Infrastructure\Vault\StaticTokenProvider;
use App\Shared\Infrastructure\Vault\VaultClient;
use Symfony\Component\HttpClient\HttpClient;

/**
 * 真实 Vault 上的集成用例共用的连接与跳过逻辑。
 *
 * ============================================================================
 * 「连不上就 skip，连得上但没初始化就 fail」
 * ============================================================================
 * 沿用 T-003 的 `DatabaseHealthCheckTest` 与 T-004 的 `RedisIdempotencyStoreTest`
 * 立下的规矩：**裸机 `composer test` 必须保持全绿** —— Vault 没有宿主机端口映射
 * （§7.4），所以开发机上不起栈时这些用例一律 skip。
 *
 * 但「没初始化」是另一回事，刻意**不** skip：
 * CI 里 bootstrap.sh 是必跑的一步，那里缺 transit key 说明脚本坏了或步骤顺序被人改了。
 * 那种情况静默 skip 的话，「加解密往返一致」这条验收标准就在 CI 里没人验证 ——
 * 而这正是 T-004 给 CI 补 redis service 时写下的同一个理由。
 *
 * ============================================================================
 * 怎么连上
 * ============================================================================
 *   裸机   `VAULT_ADDR=http://127.0.0.1:8200` 连不上 → 全组 skip
 *   容器内 compose 注入的真实环境变量 `VAULT_ADDR=http://vault:8200` 会赢过
 *          .env.test 里的默认值（Symfony 的 Dotenv 不覆盖真实环境变量），于是
 *          `docker compose exec app vendor/bin/phpunit` 能连上
 *   CI     service 容器映射了 8200，`127.0.0.1:8200` 直接可达
 */
trait RequiresVault
{
    private function vaultAddress(): string
    {
        $address = $_ENV['VAULT_ADDR'] ?? $_SERVER['VAULT_ADDR'] ?? null;

        if (!\is_string($address) || '' === $address) {
            self::markTestSkipped('VAULT_ADDR 未配置。');
        }

        return $address;
    }

    private function vaultToken(): string
    {
        $token = $_ENV['VAULT_TOKEN'] ?? $_SERVER['VAULT_TOKEN'] ?? null;

        if (!\is_string($token) || '' === $token) {
            self::markTestSkipped('VAULT_TOKEN 未配置。');
        }

        return $token;
    }

    /**
     * 拿一个连上真实 Vault 的客户端；连不上就 skip。
     *
     * @param string|null $token 覆盖默认 token —— AppRolePolicyTest 用它以
     *                           ncards-app 的身份跑，验的正是那份 policy 本身
     */
    private function vaultClient(?string $token = null): VaultClient
    {
        $address = $this->vaultAddress();
        $client = new VaultClient(
            HttpClient::create(),
            new StaticTokenProvider($token ?? $this->vaultToken()),
            $address,
        );

        try {
            $status = $client->healthStatus();
        } catch (\Throwable $e) {
            self::markTestSkipped(\sprintf(
                'Vault 不可达（%s）。起 compose 栈后在容器里跑：docker compose -f infra/compose/docker-compose.base.yml exec app vendor/bin/phpunit',
                $e->getMessage(),
            ));
        }

        if (200 !== $status) {
            self::markTestSkipped(\sprintf('Vault 未就绪（sys/health 返回 %d：501=未初始化，503=封印）。', $status));
        }

        return $client;
    }

    /**
     * 断言 bootstrap.sh 已经跑过。
     *
     * ⚠️ 这里是 fail 而不是 skip —— 理由见上方类注释。
     */
    private function assertVaultBootstrapped(VaultClient $client): void
    {
        try {
            // encrypt 一个空串是最便宜的存在性探测：不需要读 key 元数据的权限
            // （ncards-app 也没有那个权限），只要 key 在就会成功。
            $client->write('transit/encrypt/ncards-card', ['plaintext' => '']);
        } catch (\Throwable $e) {
            self::fail(\sprintf(
                "Vault 可达但 transit key `ncards-card` 用不了：%s\n".
                "请先跑初始化：docker compose -f infra/compose/docker-compose.base.yml up vault-init\n".
                '（CI 里这一步在 phpunit 之前，见 .github/workflows/backend.yml）',
                $e->getMessage(),
            ));
        }
    }
}
