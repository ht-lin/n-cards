<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Vault;

use App\Shared\Domain\Crypto\CryptoUnavailable;

/**
 * 「这次请求该带哪个 `X-Vault-Token`？」.
 *
 * 两个实现，按环境二选一（见 {@see VaultTokenProviderFactory}）：
 *   - {@see StaticTokenProvider}   dev / test：compose 的 dev 模式 root token
 *   - {@see AppRoleTokenProvider}  staging / prod：AppRole 登录 + 自动续期（§3.3 / §7.4）
 *
 * 拆成接口不是为了「将来可能换实现」这种空头理由 —— 是因为这两个实现**现在就同时存在**，
 * 且行为差异很大（一个是常量，一个有生命周期与重登逻辑）。
 */
interface VaultTokenProviderInterface
{
    /**
     * @throws CryptoUnavailable 登录失败 / 续期失败 —— 认证不上等于加解密能力没了，
     *                           与 Vault 连不上是同一类故障，走同一个异常
     */
    public function token(): string;

    /**
     * 丢弃当前缓存的 token，下次 {@see token()} 重新获取。
     *
     * {@see VaultClient} 在收到 403 时调用它并重试一次 —— token 可能已经过期或被吊销，
     * 而我们无法从响应里区分「token 过期」与「policy 不允许」。
     * 对静态 token 实现这是空操作（重登也拿不到新的），于是重试会再次 403 并如实抛出。
     */
    public function forget(): void;
}
