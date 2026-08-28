<?php

declare(strict_types=1);

namespace App\Shared\Domain\Crypto;

/**
 * §5.3 密钥清单的 PHP 落地 —— Transit 引擎里那三把 key。
 *
 * ============================================================================
 * 为什么在 Shared\Domain
 * ============================================================================
 * 与 {@see \App\Shared\Domain\Error\ErrorCode} 同一套论证：deptrac 里每个模块的
 * Domain 层允许列表只有 `Shared.Domain` 一项。「这个字段该用哪把 key 加密」是
 * Domain 的判断（`Card::barcodeValue` 用 card，`User::email` 用 pii），
 * 放在别处的话 Domain 与 Application 永远无法命名一把 key。
 *
 * ============================================================================
 * 一把 key = 一个爆炸半径
 * ============================================================================
 * 分成三把不是形式主义。§5.3 给了它们不同的轮换周期，而轮换周期不同的东西
 * 绝不能共用一把 key：
 *
 *   - `ncards-card` 与 `ncards-pii` 都是 12 个月轮换，但**爆炸半径不同** ——
 *     卡号泄露与邮箱泄露在 §8 的 ROPA 里是两类处理活动，事故时要能分别处置。
 *   - `ncards-hmac` **绝不轮换**。见 {@see isRotatable()}。
 */
enum CryptoKey: string
{
    /** 卡 payload 与 note（`cards.barcode_value_encrypted` / `note_encrypted`）。 */
    case Card = 'ncards-card';

    /** PII（`users.email_encrypted`）。 */
    case Pii = 'ncards-pii';

    /** `email_hash` / `code_hash` / `fingerprint` 的 pepper。 */
    case Hmac = 'ncards-hmac';

    /**
     * Transit 里的 key 名，也就是 URL 路径里的那一段
     * （`transit/encrypt/ncards-card`）。
     *
     * 与 `infra/vault/bootstrap.sh` 建的 key 名、`infra/vault/policies/ncards-app.hcl`
     * 里的路径**必须**逐字一致 —— 对不上时 Vault 返回 403，而 403 在
     * {@see \App\Shared\Infrastructure\Vault\VaultClient} 里会被当成 token 失效
     * 去重新登录，于是真实原因（key 名打错）会被一次无谓的重登盖住。
     * `tests/Integration/Shared/Vault/AppRolePolicyTest` 是这三个名字的对表点。
     */
    public function keyName(): string
    {
        return $this->value;
    }

    /**
     * 这把 key 能不能轮换（§5.3 的密钥清单最后一列）。
     *
     * ⚠️ `ncards-hmac` 是 **false**，而且这不是「暂时不轮换」：
     * HMAC 的输出是查找键 —— `users.email_hash` 上有 UNIQUE 约束，
     * `cards.barcode_value_fingerprint` 用来判重复卡。换了 key，同一个邮箱算出的
     * hash 就变了，于是**所有既有行都找不回来**：登录查不到用户，去重失效，
     * 而且没有「用旧 key 解密再用新 key 加密」这种补救 —— HMAC 是单向的。
     * 真要换，得先有一套「双写两列 + 全量重算 + 切换查询列」的迁移流程，
     * 那是一个独立项目，不是一次运维操作。
     *
     * Vault 侧的强制点是 policy：`ncards-app` 根本没有 `rotate` 权限，
     * `ncards-ops` 有但 `docs/runbooks/key-rotation.md`（T-404）会写明此 key 除外。
     * 这个方法是第三道防线，给 T-404 的 RewrapCardSecrets 用来跳过它。
     */
    public function isRotatable(): bool
    {
        return match ($this) {
            self::Card, self::Pii => true,
            self::Hmac => false,
        };
    }
}
