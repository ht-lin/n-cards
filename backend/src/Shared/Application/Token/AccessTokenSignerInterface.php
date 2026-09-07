<?php

declare(strict_types=1);

namespace App\Shared\Application\Token;

use App\Shared\Domain\Crypto\CryptoUnavailable;
use App\Shared\Domain\Token\AccessTokenClaims;
use App\Shared\Domain\Token\IssuedAccessToken;

/**
 * 签发 access token（§7.1：JWT，EdDSA / Ed25519，15 分钟）。
 *
 * ============================================================================
 * 为什么在 `Shared` 而不是 `Identity`
 * ============================================================================
 * 令牌是**跨模块的横切概念**：签发方是 Identity（T-104 登录、T-105 刷新），
 * 但验签方将来是所有 `/v1` 端点（T-108 的鉴权器）。放进 Identity 的话，
 * deptrac 会逼着每个模块经 `Identity.Port` 拿它 —— 而 Port 是给「业务协作」用的
 * （§4.2），把「解析 Authorization 头」变成一次跨模块调用是错误的分层。
 *
 * 另有一条硬约束：签名要读 Vault KV，也就是要用 `VaultClient`，
 * 而 `Identity.Application` 的 deptrac 允许列表里**没有** `Framework.HttpClient`
 * 也没有 `Shared.Infrastructure`。接口只能落在这里。
 *
 * 形状与 {@see \App\Shared\Application\Crypto\CryptoServiceInterface} 对齐：
 * 接口在 `Shared\Application`、值对象在 `Shared\Domain`、实现在 `Shared\Infrastructure`。
 *
 * ============================================================================
 * ⚠️ 只签不验 —— 验签是**另一个接口**
 * ============================================================================
 * T-104 交付本接口时刻意没加 `verify()`：那时没有生产调用方，而它的形状
 * （返回什么？过期怎么表达？重叠期怎么试第二把密钥？）只有在真有鉴权器时才定得下来。
 *
 * ✅ **T-105 定了**：{@see AccessTokenVerifierInterface}，一个**独立**的接口，
 * 而不是往这里加一个方法。分开的理由与 `SigningKeyProviderInterface` 的两个方法
 * 分开是同一条（§5.3「验两把、签一把」）：签发方永远只用一把密钥，
 * 验签方在轮换重叠期里要认两把。合成一个接口就迟早会有人让它们共用一条取密钥的路。
 *
 * refresh token 仍然与 JWT 无关：它是 32 字节不透明随机（§7.1），
 * 走 `sessions.refresh_token_hash` 查表。
 */
interface AccessTokenSignerInterface
{
    /**
     * @throws CryptoUnavailable 取不到签名密钥（Vault 不可达 / 封印 / KV 里的值损坏）——
     *                           503，可重试
     */
    public function sign(AccessTokenClaims $claims): IssuedAccessToken;
}
