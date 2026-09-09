<?php

declare(strict_types=1);

namespace App\Module\Wallet\Domain\ValueObject;

/**
 * `cards.encryption_scheme`（§5.2 / §17.1，`NOT NULL DEFAULT 'server_v1'`）。
 *
 * ============================================================================
 * 为什么一期就存这一列，尽管它只有一个值
 * ============================================================================
 * 二期的 E2EE 会让 `barcode_value_encrypted` 里装的东西**换一种密文**
 * （客户端持钥，服务端解不开）。到那时库里会同时存在两代行，而
 * 「这一行该用哪把钥匙、由谁来解」必须能**逐行**判断 ——
 * 靠上线时间或 `created_at` 猜是猜不准的（用户可以一直不改一张老卡）。
 *
 * 加列是一次要停机对齐的迁移，而一期就写进去的成本是每行几个字节。
 * §5.2 因此把它列进了一期的 DDL。
 *
 * ⚠️ 所以本枚举**只有一个 case 是正常的**，不是「忘了写完」。
 * 别顺手删掉这一列或把它退化成 bool。
 *
 * ============================================================================
 * ⚠️ 它不是客户端可以设置的字段
 * ============================================================================
 * 契约的 `CardCreate` / `CardUpdate` 里都没有它 —— 加密方案由服务端决定，
 * 客户端说了不算。`CardCreatePayload` 的未知字段扫描会把它拒成
 * `400 validation_failed` + `unknown_field`。
 */
enum EncryptionScheme: string
{
    /** 服务端信封加密（§5.3：Vault Transit，`CryptoKey::Card`）。一期唯一的值。 */
    case ServerV1 = 'server_v1';

    /**
     * 新建的卡用哪一种。
     *
     * 提成方法而不是让调用点写 `EncryptionScheme::ServerV1`：二期上线时
     * 「新卡默认走哪一代」是**一个**决定，改这里一处即可，
     * 而散在 `Card::create()` 与将来的迁移脚本里就是两处。
     */
    public static function default(): self
    {
        return self::ServerV1;
    }
}
