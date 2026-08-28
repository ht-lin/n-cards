<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Health;

use App\Shared\Application\Health\HealthCheckInterface;
use App\Shared\Infrastructure\Vault\VaultClient;

/**
 * Vault 就绪检查 —— `HealthCheckInterface` 类注释里那条「待接入：Vault（T-005）」的兑现。
 *
 * **零配置**：`config/services.yaml` 的 `_instanceof` 自动打上 `app.health_check` 标签，
 * `ReadinessProbe` 的 `#[AutowireIterator]` 自动收集，控制器一行不改。
 *
 * ============================================================================
 * 这个检查是 fail-closed 的**配套**，不是可选项
 * ============================================================================
 * 生产每次重启后 Vault 都是**封印**状态（Q6 / ADR-0004：auto-unseal 关闭）。
 * 此期间应用解不了密，全部涉密端点返回 503（见 `CryptoUnavailable`）。
 * 那么这个状态就必须对**运维**可见 —— 这里把它翻成 `/health/ready` 的 503，
 * Caddy、Ansible 与 §14.3 的部署健康检查都能看到，滚动重启不会把流量打给一个
 * 还没 unseal 的实例。
 *
 * `infra/vault/vault.hcl` 的注释里承诺的「此期间 app 的 /health/ready 会失败，
 * 这是期望行为」，兑现点就是本类。
 *
 * ============================================================================
 * 为什么只打免认证的 sys/health
 * ============================================================================
 * §17.4 的 `ncards-app` policy 里**没有** `auth/token/lookup-self`，所以探针没法
 * 验证「我们的 token 还好使」。这是一个已知的、刻意接受的缺口：
 *
 *   - 覆盖到的：Vault 挂了、被封印、未初始化 —— 也就是运维真正会遇到的那几种。
 *   - 覆盖不到的：secret_id 失效、policy 配错。这类故障会在第一个涉密请求上
 *     以 503 + error 日志暴露，而不是被就绪探针提前拦住。
 *
 * 不为此放宽 policy：给探针加一条权限，换来的是把「最小权限」这条 §7.4 的要求
 * 撕开一个口子，而缺口本身有日志兜底。处置办法写在
 * `docs/runbooks/vault-unseal.md` 的「升级路径」一节。
 *
 * 顺带一提，用 encrypt 探活也不行 —— 那会给每次探针（§14.3 的部署流程里每几秒一次）
 * 都写一条 Vault 审计日志，纯属噪声。
 */
final readonly class VaultHealthCheck implements HealthCheckInterface
{
    public function __construct(private VaultClient $vault)
    {
    }

    public function name(): string
    {
        return 'vault';
    }

    public function check(): void
    {
        // Vault 用状态码表达状态，不是用响应体：
        //   200 已初始化、已解封、active   ← 唯一健康的
        //   429 standby（单节点部署碰不到，standbyok=true 时也会变成 200）
        //   501 未初始化 —— runbook 第一步还没做
        //   503 已封印   —— 等人工 unseal
        $status = $this->vault->healthStatus();

        if (200 !== $status) {
            // 消息只进日志，不外泄（HealthCheckInterface::name() 的注释：
            // §6.2 要求健康端点不暴露内部细节）。带上状态码，因为 501 与 503
            // 对应的处置步骤完全不同 —— 一个是初始化，一个是 unseal。
            throw new \RuntimeException(\sprintf('Vault is not ready (sys/health returned HTTP %d).', $status));
        }
    }
}
