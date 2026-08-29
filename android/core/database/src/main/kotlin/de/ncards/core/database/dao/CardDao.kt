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
