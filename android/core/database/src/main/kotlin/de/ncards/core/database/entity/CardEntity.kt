package de.ncards.core.database.entity

import androidx.room.ColumnInfo
import androidx.room.Entity
import androidx.room.PrimaryKey

/**
 * 一张卡（§5.2 的 `cards`）。
 *
 * ## 三处与服务端表**有意**不同的地方
 *
 * 1. **`barcode_value` 存明文。** 服务端那一列是 Vault Transit 密文
 *    （`barcode_value_encrypted`），但客户端拿不到 Vault 的密钥，API 返回给已授权
 *    客户端的本来就是明文（§5.3）。整个 SQLCipher 就是为这一列存在的：
 *    没有它，服务端那套信封加密在设备上等于零（§3.4）。
 * 2. **没有 `deleted_at`。** 软删是服务端的墓碑机制；§5.4.3 明确「客户端收到墓碑即
 *    **物理删除**本地行」。留一列 `deleted_at` 只会诱使人写出「查询时记得过滤」，
 *    而那个 `WHERE` 迟早有人漏掉。
 * 3. **没有 `sort_order` / `is_pinned`。** 它们是**每成员私有**的，在
 *    [CardMemberEntity] 上 —— §5.2 点名的三条最易写错的语义之一。
 *
 * 另外没有的：`barcode_value_fingerprint`（服务端查重用）、`encryption_scheme`
 * （二期 E2EE 预留）。两者客户端都用不上，不复制。
 *
 * `sync_state` 也**不在这里**：它由 `sync_outbox` 派生（见 `CardDao.observeWallet`）。
 * 一份状态存两处，迟早会漂移成两份。
 */
@Entity(tableName = "cards")
data class CardEntity(
    /** 客户端生成的 UUIDv7（§4.3）—— 离线创建的卡从一开始就有稳定 ID。 */
    @PrimaryKey
    @ColumnInfo(name = "id")
    val id: String,
    @ColumnInfo(name = "owner_id")
    val ownerId: String,
    @ColumnInfo(name = "title")
    val title: String,
    @ColumnInfo(name = "merchant_label")
    val merchantLabel: String?,
    /** 预设调色板的枚举值，如 `blue_600`（Q7 定色值，T-152 落地）。 */
    @ColumnInfo(name = "color")
    val color: String,
    /** `EAN_13` / `CODE_128` / `QR_CODE` / …。以 TEXT 存，见文件头。 */
    @ColumnInfo(name = "barcode_format")
    val barcodeFormat: String,
    @ColumnInfo(name = "barcode_value")
    val barcodeValue: String,
    @ColumnInfo(name = "note")
    val note: String?,
    /** epoch day。`null` = 不过期（§3.13）。 */
    @ColumnInfo(name = "expires_on")
    val expiresOn: Long?,
    /** 乐观锁。上行 `PATCH` 的 `If-Match` 取它（§5.4.3）。 */
    @ColumnInfo(name = "revision")
    val revision: Long,
    /**
     * 共享给几个人。**只有 owner 会拿到值**，viewer 恒为 `null`（§5.2 / T21）：
     * 成员**数量**本身就是信息，给了 viewer 就等于告诉他还有别人在。
     * owner 侧 T-154 的删除确认对话框要用它。
     */
    @ColumnInfo(name = "member_count")
    val memberCount: Int?,
    /** epoch millis。 */
    @ColumnInfo(name = "created_at")
    val createdAt: Long,
    @ColumnInfo(name = "updated_at")
    val updatedAt: Long,
)
