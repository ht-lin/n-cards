<?php

declare(strict_types=1);

namespace App\Shared\Application\Token;

use App\Shared\Domain\Crypto\CryptoUnavailable;
use App\Shared\Domain\Token\SigningKey;

/**
 * 「当前该用哪把密钥签名」。
 *
 * 接口在 Application、实现在 Infrastructure，理由与
 * {@see \App\Shared\Application\Crypto\CryptoServiceInterface} 逐字相同
 * （deptrac 里没有任何模块能看见 `Shared.Infrastructure`）。
 *
 * ============================================================================
 * 只有「当前」，没有「全部」
 * ============================================================================
 * §5.3 规定 JWT 签名密钥 6 个月轮换、**双密钥重叠期 24h**。重叠期里
 * 「签名用哪把」与「验签接受哪些把」是两个不同的问题，本接口只回答前者 ——
 * 签名永远只用最新的那把。
 *
 * 后者（一个 JWK Set）今天**没有调用方**：refresh token 是不透明的、
 * 不需要验签，而 `Authorization: Bearer` 的鉴权器归 T-108。
 * 按仓库「方法集刻意窄」的规矩，那个方法由 T-108 自己加 ——
 * 现在替它猜一个形状，猜错了就是一段没人用、却要为覆盖率门禁补测试的死代码。
 *
 * ⚠️ T-108 加验签时要记住：重叠期的语义是「**验**两把、**签**一把」。
 * 把这个方法改成返回一组密钥、再让签名方随手取第一个，会让轮换当天
 * 签出一半旧一半新的 token，而且没有任何症状。
 */
interface SigningKeyProviderInterface
{
    /**
     * @throws CryptoUnavailable Vault 不可达 / 被封印 / 认证失败，或 KV 里的值不是一把
     *                           合法的 Ed25519 密钥 —— 503，可重试
     */
    public function currentKey(): SigningKey;
}
