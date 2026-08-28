<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Vault;

use App\Shared\Domain\Crypto\CryptoUnavailable;

/**
 * 固定 token —— **只用于 dev 与 test**。
 *
 * compose 的本地栈把 Vault 跑在 dev 模式（内存后端、自动 unseal、固定 root token，
 * 见 `infra/compose/docker-compose.base.yml`），CI 的 service 容器同理。
 * 那个场景下走 AppRole 只是给每次本地起栈平添一步登录，换不到任何安全性 ——
 * root token 本来就写在 compose 文件里。
 *
 * ⚠️ staging 与 prod **绝不**用这个实现。{@see VaultTokenProviderFactory} 的选择逻辑
 * 是「配了 `VAULT_ROLE_ID` 就走 AppRole」，而 §7.4 要求生产用 AppRole + 最小权限 policy。
 * root token 没有 policy 限制，等于把 §17.4 那份「显式不授予 keys/*、rotate、rewrap」
 * 的清单整个作废。
 */
final class StaticTokenProvider implements VaultTokenProviderInterface
{
    public function __construct(private readonly string $token)
    {
    }

    public function token(): string
    {
        if ('' === $this->token) {
            // 空 token 打过去会得到一个 403，那个错误信息指向「权限不足」，
            // 而真实原因是「压根没配」。在这里就说清楚，省掉一轮排查。
            throw new CryptoUnavailable('VAULT_TOKEN is not configured.');
        }

        return $this->token;
    }

    /**
     * 空操作：静态 token 没有「重新获取」可言，忘掉它也拿不到新的。
     *
     * 于是 {@see VaultClient} 的 403 重试在 dev 下会原样再失败一次并如实抛出 ——
     * 这正是我们要的：dev 下的 403 是配置错了（token 写错、key 名打错），
     * 不该被一次假装成功的重登掩盖。
     */
    public function forget(): void
    {
    }
}
