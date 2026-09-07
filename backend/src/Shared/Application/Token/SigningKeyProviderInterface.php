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
 * ⚠️ 两个方法回答的是两个**不同**的问题，不要把它们合成一个
 * ============================================================================
 * §5.3 规定 JWT 签名密钥 6 个月轮换、**双密钥重叠期 24h**。重叠期里：
 *
 *     {@see currentKey()}       「签名用哪把」  —— 永远只有一把，最新的那把
 *     {@see verificationKeys()} 「验签接受哪些」—— 重叠期内两把，其余时候一把
 *
 * 这就是「**验**两把、**签**一把」。把两者合并成一个「返回一组密钥」的方法、
 * 再让签名方随手取第一个，会让轮换当天签出一半旧一半新的 token，
 * **而且没有任何症状** —— 直到重叠期结束，旧 kid 被移出验签集合，
 * 那批 token 才开始被拒，症状出现在轮换之后一整天。
 *
 * 所以 `currentKey()` 返回 {@see SigningKey}（含私钥 seed）、
 * `verificationKeys()` 返回一组**公钥**，两者的返回类型都不给对方留可乘之机。
 *
 * > T-105 备注：本接口原先只有 `currentKey()`，注释里把验签侧写给了 T-108。
 * > 实际的依赖顺序是反的 —— `POST /v1/auth/logout` 与三个 `/v1/me/devices`
 * > 端点都要 Bearer，而它们归 T-105。所以验签侧在 T-105 落地。
 */
interface SigningKeyProviderInterface
{
    /**
     * @throws CryptoUnavailable Vault 不可达 / 被封印 / 认证失败，或 KV 里的值不是一把
     *                           合法的 Ed25519 密钥 —— 503，可重试
     */
    public function currentKey(): SigningKey;

    /**
     * 当前**可接受**的验签公钥，按 `kid` 索引（§5.3 的 24h 重叠期）。
     *
     * 验签方用 JWS header 里的 `kid` 直接查这张表，**不要**拿所有密钥挨个试 ——
     * 那会让「轮换配错了」这种故障静默通过，而这里恰恰是最不该静默的地方。
     *
     * 稳态下只有一项（`current`）。重叠期里有两项（`current` + `previous`），
     * 而 `previous` **缺失不是错误**：首次部署到第一次轮换之间的全部时间里都没有它。
     *
     * @return array<non-empty-string, non-empty-string> kid => Ed25519 公钥（32 字节裸值，
     *                                                   不是 PEM、不是 base64）
     *
     * @throws CryptoUnavailable 同 {@see currentKey()}。⚠️ 只有 `current` 读不出来才算
     *                           不可用；`previous` 不存在时必须安静地略过它
     */
    public function verificationKeys(): array;
}
