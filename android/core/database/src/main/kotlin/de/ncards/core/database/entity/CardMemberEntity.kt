package de.ncards.core.database.entity

import androidx.room.ColumnInfo
import androidx.room.Entity
import androidx.room.ForeignKey
import androidx.room.Index

/**
 * 谁能看见这张卡，以及**他自己**把它摆在钱包的哪个位置（§5.2 的 `card_members`）。
 *
 * ## `sort_order` / `is_pinned` 为什么在这张表上
 *
 * 因为它们是**每成员私有**的：同一张家庭卡，Anna 置顶了，Bob 可以不置顶。
 * 把它们放 `cards` 上就等于让一个人的排序覆盖所有人 —— §5.2 把这条列为
 * 全项目三条最易写错的语义之一。它们也**不参与 revision**（§5.4.3）：
 * viewer 改自己的摆放不是对卡的写入，不触发冲突协议。
 *
 * ## 可以写成断言的一条不变量（§5.2 / 威胁模型 T21）
 *
 * **viewer 的本地库里，任何一张共享卡都恰好有 2 行：owner 一行、自己一行。**
 *
 * 这不是巧合，是同步层保证的：`card_member` 变更的 `audience` 只含
 * `[owner, 当事人]`，别的 viewer 的行根本不会下发到这台设备。Anna 把卡分别
 * 共享给伴侣 Bob 和同事 Carol，而 Bob 与 Carol 可能互不认识 —— 若他们的成员行
 * 躺进了对方的 Room，UI 过滤得再干净也没用，**本地数据库已经泄露了**。
 * T-250 会把这条写成集成测试的断言点。
 *
 * 没有 `left_at`：与 [CardEntity] 同理，墓碑即物理删。
 */
@Entity(
    tableName = "card_members",
    primaryKeys = ["card_id", "user_id"],
    foreignKeys = [
        ForeignKey(
            entity = CardEntity::class,
            parentColumns = ["id"],
            childColumns = ["card_id"],
            // 卡没了，成员行留着毫无意义，而且会挡住同 id 的卡重新入库。
            onDelete = ForeignKey.CASCADE,
        ),
    ],
    // 外键的子列必须有索引，否则父表每次删改都要全表扫 —— Room 会直接警告。
    indices = [Index("card_id"), Index("user_id")],
)
data class CardMemberEntity(
    @ColumnInfo(name = "card_id")
    val cardId: String,
    @ColumnInfo(name = "user_id")
    val userId: String,
    /** `owner` / `viewer` 两种（v1.1 已移除 `editor`）。owner 行不可转让、不可降级。 */
    @ColumnInfo(name = "role")
    val role: String,
    @ColumnInfo(name = "sort_order")
    val sortOrder: Int,
    @ColumnInfo(name = "is_pinned")
    val isPinned: Boolean,
    @ColumnInfo(name = "added_by")
    val addedBy: String?,
    /** epoch millis。 */
    @ColumnInfo(name = "joined_at")
    val joinedAt: Long,
)
