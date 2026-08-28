<?php

declare(strict_types=1);

namespace App\Tests\Integration\Health;

use App\Shared\Infrastructure\Health\VaultHealthCheck;
use App\Shared\Infrastructure\Vault\StaticTokenProvider;
use App\Shared\Infrastructure\Vault\VaultClient;
use App\Tests\Integration\Support\RequiresVault;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\HttpClient;

/**
 * Vault 就绪检查 —— `/health/ready` 的 Vault 那一格。
 *
 * 沿用 T-003 的 DatabaseHealthCheckTest 立下的模式：连不上就 skip。
 */
#[CoversClass(VaultHealthCheck::class)]
#[CoversClass(VaultClient::class)]
final class VaultHealthCheckTest extends TestCase
{
    use RequiresVault;

    public function testPassesAgainstAnUnsealedVault(): void
    {
        $check = new VaultHealthCheck($this->vaultClient());

        $check->check();

        self::assertSame('vault', $check->name());
    }

    /**
     * 探针打的是**免认证**的 `sys/health` —— 换个乱七八糟的 token 照样通过。
     *
     * 这条把 VaultHealthCheck 类注释里那个「已知且刻意接受的缺口」变成可执行的说明：
     * 探针覆盖「Vault 挂了 / 被封印 / 未初始化」，但**覆盖不到** secret_id 失效
     * 与 policy 配错。处置办法见 docs/runbooks/vault-unseal.md 的「升级路径」。
     *
     * 不为此放宽 policy：给探针加 auth/token/lookup-self 就等于在 §17.4 那份
     * 最小权限清单上开一个口子，而这个缺口本身有 error 日志兜底。
     */
    public function testHealthProbeDoesNotAuthenticate(): void
    {
        $this->vaultClient(); // 连不上就 skip

        $client = new VaultClient(
            HttpClient::create(),
            new StaticTokenProvider('definitely-not-a-valid-token'),
            $this->vaultAddress(),
        );

        // 断言状态码而不是「没抛异常」：后者在 PHPStan level 8 下只能写成
        // assertTrue(true)，那既被 phpstan 判为恒真、又说明不了任何事。
        self::assertSame(200, $client->healthStatus(), 'sys/health 是免认证端点，无效 token 也该拿到 200。');

        (new VaultHealthCheck($client))->check();
    }

    /**
     * 连不上时必须抛 —— 那正是 `/health/ready` 翻成 503 的依据。
     *
     * 生产每次重启后 Vault 都是封印状态（Q6 / ADR-0004），此时探针失败是
     * **期望行为**：Caddy 与 §14.3 的部署健康检查据此不把流量打给这个实例。
     */
    public function testFailsWhenVaultIsUnreachable(): void
    {
        // 一个确定没人监听的端口，不需要真 Vault，所以这条用例永不 skip。
        $check = new VaultHealthCheck(new VaultClient(
            HttpClient::create(),
            new StaticTokenProvider('x'),
            'http://127.0.0.1:1',
        ));

        $this->expectException(\Throwable::class);

        $check->check();
    }
}
