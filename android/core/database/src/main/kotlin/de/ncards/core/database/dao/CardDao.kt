package de.ncards.core.database.dao

import androidx.room.Dao
import androidx.room.Insert
import androidx.room.OnConflictStrategy
import androidx.room.Query
import androidx.room.Upsert
import de.ncards.core.database.entity.CardEntity
import de.ncards.core.database.model.WalletCard
import kotlinx.coroutines.flow.Flow

/**
 * `cards` 的读写。
 *
 * §4.3 的离线优先铁律：**UI 永远从这里的 `Flow` 读**，绝不直接消费网络响应渲染。
 * 网络的唯一去向是写进 Room，UI 再从 Room 流出来 —— 这样断网时 UI 不需要
 * 任何特殊分支，因为它压根不知道网络存在。
 */
@Dao
interface CardDao {
    /**
     * 钱包列表（T-153）：卡 + 我自己的摆放 + 从 outbox 派生的同步状态。
     *
     * `sync_state` 是**算出来的**，不是存出来的：
     * - outbox 里有这张卡的记录且已到重试时间 → `PENDING`
     * - 有记录但 `attempt_count` 已到上限 → `FAILED`
     * - 没有记录 → `SYNCED`
     *
     * 上限与退避曲线归 T-251，所以阈值从参数传进来而不是写死在 SQL 里。
     * 这么做的理由是「一份状态别存两处」：若在 `cards` 上再加一列 `sync_state`，
     * 它与 outbox 的真实内容迟早会对不上，而对不上时没人知道该信哪个。
     *
     * 排序按 §5.2：置顶优先，然后是**每成员私有**的 `sort_order`。
     */
    @Query(
        """
        SELECT c.*, m.sort_order AS sort_order, m.is_pinned AS is_pinned, m.role AS role,
               CASE
                   WHEN o.attempt_count IS NULL THEN 'SYNCED'
                   WHEN o.attempt_count >= :maxAttempts THEN 'FAILED'
                   ELSE 'PENDING'
               END AS sync_state
        FROM cards c
        INNER JOIN card_members m ON m.card_id = c.id AND m.user_id = :userId
        LEFT JOIN (
            SELECT entity_id, MAX(attempt_count) AS attempt_count
            FROM sync_outbox
            WHERE entity_type = 'card'
            GROUP BY entity_id
        ) o ON o.entity_id = c.id
        ORDER BY m.is_pinned DESC, m.sort_order ASC, c.created_at DESC
        """,
    )
    fun observeWallet(
        userId: String,
        maxAttempts: Int,
    ): Flow<List<WalletCard>>

    /**
     * [observeWallet] 的一次性快照（T-153 的拖拽重排用）。
     *
     * ⚠️ **查询字符串与 [observeWallet] 逐字相同，这是刻意的重复。**
     *
     * Room 不允许一个 `@Query` 同时产出 `Flow` 与 `suspend` 两种形态，而重排
     * 必须在**事务里**读到当前顺序 —— 从 `Flow` 上 `first()` 拿到的是事务外的
     * 一个快照，两次读之间完全可能有一次下行同步插进来，于是算出的
     * `sort_order` 是对着一份已经过期的顺序算的。
     *
     * 三种出路里选了复制：抽成 `@RawQuery`（丢掉编译期的列名校验，而那正是
     * Room 在这里最大的价值）、把两个方法都改成读全表再在 Kotlin 里排序
     * （那就把 §5.2 的排序语义从一处变成两处），或者复制这一段 SQL。
     *
     * ⚠️ 改上面那段 SQL 的人**必须同时改这一段**。两者漂了的症状是：
     * 列表显示的顺序与拖拽算出来的顺序不一致 —— 用户把卡拖到第二位，
     * 松手之后它跳到第五位。
     */
    @Query(
        """
        SELECT c.*, m.sort_order AS sort_order, m.is_pinned AS is_pinned, m.role AS role,
               CASE
                   WHEN o.attempt_count IS NULL THEN 'SYNCED'
                   WHEN o.attempt_count >= :maxAttempts THEN 'FAILED'
                   ELSE 'PENDING'
               END AS sync_state
        FROM cards c
        INNER JOIN card_members m ON m.card_id = c.id AND m.user_id = :userId
        LEFT JOIN (
            SELECT entity_id, MAX(attempt_count) AS attempt_count
            FROM sync_outbox
            WHERE entity_type = 'card'
            GROUP BY entity_id
        ) o ON o.entity_id = c.id
        ORDER BY m.is_pinned DESC, m.sort_order ASC, c.created_at DESC
        """,
    )
    suspend fun walletSnapshot(
        userId: String,
        maxAttempts: Int,
    ): List<WalletCard>

    @Query("SELECT * FROM cards WHERE id = :cardId")
    fun observeCard(cardId: String): Flow<CardEntity?>

    @Query("SELECT * FROM cards WHERE id = :cardId")
    suspend fun findCard(cardId: String): CardEntity?

    /**
     * 下行同步用（T-250）。幂等：同一批被重放时结果一致 ——
     * 游标只在整批写成功后才推进，所以重放是常态而非异常。
     */
    @Upsert
    suspend fun upsert(cards: List<CardEntity>)

    @Insert(onConflict = OnConflictStrategy.ABORT)
    suspend fun insert(card: CardEntity)

    /**
     * 收到墓碑时调用（§5.4.3）。`card_members` 由外键 CASCADE 一起清掉。
     *
     * 是物理删除，不是标记删除 —— 见 [CardEntity] 的类注释。
     */
    @Query("DELETE FROM cards WHERE id IN (:cardIds)")
    suspend fun deleteByIds(cardIds: List<String>)

    @Query("SELECT COUNT(*) FROM cards")
    suspend fun count(): Int
}
