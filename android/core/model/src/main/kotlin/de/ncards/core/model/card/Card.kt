package de.ncards.core.model.card

import de.ncards.core.model.barcode.BarcodeFormat
import de.ncards.core.model.sync.SyncState
import java.time.LocalDate

/**
 * 一张卡，**在当前用户视角下**（§12.3 的 `core:model` 交付物）。
 *
 * ============================================================================
 * 它与 `core:database` 的 `WalletCard` 是两个不同的东西
 * ============================================================================
 * `WalletCard` 是 Room 的**查询投影**：`@Embedded` 的 entity + 三个 join 出来的
 * 裸列，字段类型是 Room 能存的那几种（`String` 的枚举、`Long` 的日期）。
 * 它的类注释明令「别让 Compose 直接吃这个类型，否则 Room 的列名会一路渗到 UI 层」。
 *
 * 本类是那句话的另一半：`data:card` 负责 `WalletCard → Card` 的映射，
 * 枚举在这一步从字符串还原，日期从 epoch day 还原。**过了这条线就没有裸字符串了。**
 *
 * ============================================================================
 * ⚠️ 「当前用户视角」不是修辞
 * ============================================================================
 * 契约的 `Card` 描述里写着：「`my_role` / `can_edit` / `sort_order` / `is_pinned` /
 * `member_count` 都随调用者而变 —— 同一张卡，owner 与 viewer 拿到的不是同一个对象」。
 * [role]、[isPinned]、[sortOrder]、[memberCount] 四个字段因此**不是卡的属性**，
 * 是「这张卡对我而言」的属性。它们来自 `card_members`，而那张表每个成员一行。
 *
 * @property id 客户端生成的 UUIDv7（§4.3）。离线创建的卡从一开始就有稳定 ID，
 *   所以不需要「临时 ID → 服务端 ID」的重映射。
 * @property colorWire 服务端给的**原样**调色板键。
 *   ⚠️ 与 [color] 并存是刻意的，见 [color]。
 * @property color [colorWire] 解析出来的枚举，**只用于渲染**。
 * @property expiresOn `null` = 不过期（§3.13）。一期仅本地展示，不做推送提醒。
 * @property revision 乐观锁版本。`PATCH` 时放进 `If-Match`（§5.4.3）。
 *   ⚠️ **placement 的修改不会递增它** —— 那是成员私有字段，不走 revision 锁。
 * @property memberCount 共享给几个人。**只有 owner 拿得到值，viewer 恒为 `null`**
 *   （§5.2 / 威胁模型 T21）：成员**数量**本身就是信息，给了 viewer 就等于告诉他
 *   还有别人在。⚠️ 它是 `null` 时**不得**推断任何默认值（契约原文）。
 * @property sortOrder **每成员私有**（§5.2 点名的三条最易写错的语义之一）。
 * @property isPinned **每成员私有**，同上。
 * @property syncState 从 `sync_outbox` 派生，不是存出来的。见 [SyncState]。
 */
data class Card(
    val id: String,
    val ownerId: String,
    val title: String,
    val merchantLabel: String?,
    val colorWire: String,
    val barcodeFormat: BarcodeFormat,
    val barcodeValue: String,
    val note: String?,
    val expiresOn: LocalDate?,
    val revision: Long,
    val memberCount: Int?,
    val createdAt: Long,
    val updatedAt: Long,
    val role: CardRole,
    val sortOrder: Int,
    val isPinned: Boolean,
    val syncState: SyncState,
) {
    /**
     * 渲染用的调色板枚举。
     *
     * ⚠️ **写回时用 [colorWire]，不要用它。** 一个装了新版本 App 的设备发来的
     * `mint_600`，在本版本里解析成 [CardColor.DEFAULT]；若编辑时把枚举写回去，
     * 那张卡的颜色就被老客户端静默改成了蓝色，而且服务端没有任何机制会发现
     * （`color` 不参与任何校验，`PATCH` 只比 revision）。
     *
     * 同一条理由在 `BarcodeFormat.UNKNOWN` 的注释里写过一遍：
     * 「`data:card` 保留服务端原样的字符串，本枚举只用于渲染与展示」。
     */
    val color: CardColor get() = CardColor.fromWire(colorWire)

    /**
     * 列表与详情页的首字母图标用的那个字。
     *
     * 取 [title] 的第一个**字位簇**而不是第一个 `Char`：德语没有代理对，但
     * emoji 有（用户完全可能把卡叫成「🛒 REWE」），而 `title.first()` 在那种情况下
     * 会切出半个代理对，渲染成一个豆腐块。
     *
     * 标题全是空白时返回空串 —— 调用方画一个纯色块即可。
     * 不在这里兜底成 `"?"`：那是一个**用户可见的字符**，§11.1 要求所有用户可见
     * 字符串必须在 `strings.xml` 里，而本模块没有 `res/`。
     */
    val initial: String
        get() =
            title
                .trim()
                .takeIf(String::isNotEmpty)
                ?.let { it.substring(0, it.offsetByCodePoints(0, 1)) }
                ?.uppercase()
                .orEmpty()

    /** [CardRole.canEdit] 的转发。UI gate 编辑入口用它（契约原文要求）。 */
    val canEdit: Boolean get() = role.canEdit
}
