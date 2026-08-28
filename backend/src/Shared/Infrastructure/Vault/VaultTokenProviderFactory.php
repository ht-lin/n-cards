<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Vault;

use App\Shared\Domain\Time\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * 按配置挑一个 {@see VaultTokenProviderInterface} 实现。
 *
 * ============================================================================
 * 为什么是运行时按环境变量挑，而不是 `when@prod`
 * ============================================================================
 * 用 `when@prod` 的话，判据是 `APP_ENV`。但 staging 与 production 的 `APP_ENV`
 * 都是 `prod`（§14.1），而将来某次本地排查很可能想在 dev 下用真 AppRole 打一遍 ——
 * 那时 `when@` 就拦在路上，只能改 config 再重启。
 *
 * 真正的判据是「有没有配 AppRole 凭据」，那是一个配置事实，不是环境名。
 * 于是规则简单到一句话：**配了 `VAULT_ROLE_ID` 就走 AppRole，否则走静态 token**。
 * 本地 compose 与 CI 都只配 `VAULT_TOKEN`（dev 模式的 root token），
 * staging/prod 由 sops 下发 `VAULT_ROLE_ID` + `VAULT_SECRET_ID`（T-012），
 * 且**不**下发 `VAULT_TOKEN`。
 *
 * 做成工厂而不是在 `services.yaml` 里写两条 alias + 条件，是因为容器配置表达不了
 * 「按 env 变量的值选实现」—— 表达得了的只有按环境名选。
 */
final readonly class VaultTokenProviderFactory
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private ClockInterface $clock,
        #[Autowire('%env(VAULT_ADDR)%')]
        private string $address,
        #[Autowire('%env(VAULT_TOKEN)%')]
        private string $token,
        #[Autowire('%env(VAULT_ROLE_ID)%')]
        private string $roleId,
        #[Autowire('%env(VAULT_SECRET_ID)%')]
        private string $secretId,
    ) {
    }

    public function create(): VaultTokenProviderInterface
    {
        if ('' !== $this->roleId) {
            return new AppRoleTokenProvider(
                $this->httpClient,
                $this->clock,
                $this->address,
                $this->roleId,
                $this->secretId,
            );
        }

        return new StaticTokenProvider($this->token);
    }
}
