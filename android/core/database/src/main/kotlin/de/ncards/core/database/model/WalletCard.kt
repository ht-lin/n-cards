package de.ncards.core.database.model

import androidx.room.ColumnInfo
import androidx.room.Embedded
import de.ncards.core.database.entity.CardEntity

/**
 * `CardDao.observeWallet` 的一行：卡本体 + 当前用户**自己的**摆放 + 派生的同步状态。
 *
 * 这是一个**查询投影**，不是领域模型。往 `feature:*` 送的 `Card` 住在 `core:model`，
 * 由 `data:card` 做映射（§12.3）—— 别让 Compose 直接吃这个类型，
 * 否则 Room 的列名会一路渗到 UI 层。
 */
data class WalletCard(
    @Embedded
    val card: CardEntity,
    /** 来自 `card_members`，**每成员私有**（§5.2）。 */
    @ColumnInfo(name = "sort_order")
    val sortOrder: Int,
    @ColumnInfo(name = "is_pinned")
    val isPinned: Boolean,
    /**
     * 当前用户在这张卡上的角色：`owner` / `viewer`。
     *
     * UI 靠它决定**是否渲染编辑与删除入口** —— §7.3 要求 viewer 在 UI 层
     * 就没有入口，而不是「点了才报错」。服务端仍会独立强制（§3.5）。
     */
    @ColumnInfo(name = "role")
    val role: String,
    /** `SYNCED` / `PENDING` / `FAILED`。从 `sync_outbox` 派生，见 `CardDao.observeWallet`。 */
    @ColumnInfo(name = "sync_state")
    val syncState: String,
)
