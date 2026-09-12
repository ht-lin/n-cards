package de.ncards.data.card

import de.ncards.core.model.card.Card
import kotlinx.coroutines.flow.Flow

/**
 * `data:card` 对外的全部面（§12.3：feature 层只经 Repository 拿数据）。
 *
 * ============================================================================
 * ⚠️ 不变量：Room 的类型**不出本模块**
 * ============================================================================
 * `CardEntity` / `WalletCard` / 各个 DAO 都是 `core:database` 的东西，
 * 而 `WalletCard` 的类注释明令「别让 Compose 直接吃这个类型，否则 Room 的列名
 * 会一路渗到 UI 层」。本接口是那条禁令的落地点：进来的是查询词与 id，
 * 出去的是 `core:model` 的 [Card]。
 *
 * 形状与 `data:auth` 的 `AuthRepository` 同构（`Session` 不出那个模块）。
 *
 * ============================================================================
 * ⚠️ 离线优先：本接口**没有**任何「刷新」「拉取」方法
 * ============================================================================
 * §4.3 铁律一：「UI **永远**从 Room 的 `Flow` 读取，**绝不**直接消费网络响应渲染」。
 * 所以这里没有 `suspend fun refresh()` —— 网络的唯一去向是写进 Room，
 * UI 再从 Room 流出来。断网时 UI 不需要任何特殊分支，因为它压根不知道网络存在。
 *
 * 往 Room 写下行数据是 SyncEngine 的事（T-250），不在本接口上。
 */
interface CardRepository {
    /**
     * 钱包列表。卡 + 我自己的摆放 + 从 outbox 派生的同步状态。
     *
     * 排序在 SQL 里已经定了（§5.2）：置顶优先，然后是**每成员私有**的 `sort_order`，
     * 同序时新卡在前。调用方**不要**再排一次 —— 拖拽排序的整个意义就是
     * 用户说了算，UI 再插一手会让拖完之后位置自己跳回去。
     *
     * ============================================================================
     * ⚠️ 这里**没有** `query` 参数，搜索由调用方在这个流之上过滤
     * ============================================================================
     * 把搜索词做成参数，意味着每敲一个字母都要重新订阅一次 Room 的 `Flow`
     * （`flatMapLatest`），也就是重跑一次带两个 JOIN 和一个派生子查询的 SQL，
     * 而且中间会经过一帧空列表 —— 用户看到的是搜索时列表在闪。
     *
     * 正确的形状是：本流订阅**一次**，搜索在内存里过滤
     * （`Card.matches(query)`，那是一个纯函数，住在 `core:model`）。
     * §7.5 的每用户上限是 500 张卡而规格注明「P99 < 30」，
     * 500 个字符串的 `contains` 不值得为它重跑一次 SQL。
     *
     * 顺带解决的一件事：SQLite 的 `LIKE` 只做 ASCII 大小写折叠，
     * `Ä` 匹配不到 `ä`，而本库没有任何 `COLLATE NOCASE`。
     * 在 Kotlin 侧过滤走的是 JVM 的 Unicode 折叠，对德语直接是对的。
     *
     * ⚠️ 当前用户未知时（冷启动第一帧、或未登录）发出的是**空列表**，
     * 而不是抛异常或者挂住。调用方靠 [observeCurrentUserId] 区分
     * 「真的没有卡」与「还不知道你是谁」—— 那是两种完全不同的界面。
     */
    fun observeWallet(): Flow<List<Card>>

    /**
     * 「我是谁」。转发 `core:common` 的端口，让 `feature:wallet` 不必再依赖它一次。
     *
     * `null` = 还不知道（见 `CurrentUserIdStore.userId` 的注释）。
     */
    fun observeCurrentUserId(): Flow<String?>

    /**
     * 置顶 / 取消置顶。
     *
     * ⚠️ **不动 `sort_order`。** 查询是 `ORDER BY is_pinned DESC, sort_order ASC`，
     * 两个维度正交 —— 把置顶实现成「把 sort_order 改到最小」会让取消置顶之后
     * 原来的位置永久丢失。
     *
     * 这是 viewer **唯一**能做的上行写入（§3.5）：placement 是成员私有字段，
     * 不动卡本身，因此不参与 revision，也不触发冲突协议。
     */
    suspend fun setPinned(
        cardId: String,
        pinned: Boolean,
    )

    /**
     * 拖拽落下：把 [cardId] 移到当前可见顺序里的第 [toIndex] 位。
     *
     * [toIndex] 是**当前用户看到的那个列表**里的下标，不是 `sort_order` 的值 ——
     * 调用方是 UI，它知道的只有「我把它拖到了第三个位置」。
     *
     * ⚠️ 置顶与非置顶是两个独立的区段（查询先按 `is_pinned DESC` 排）。
     * 本方法只在 [cardId] 所属的那个区段内重排，跨区段的拖拽由调用方先
     * [setPinned] 再排。
     */
    suspend fun reorder(
        cardId: String,
        toIndex: Int,
    )
}
