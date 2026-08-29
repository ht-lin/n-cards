package de.ncards.core.database.dao

import androidx.room.Dao
import androidx.room.Insert
import androidx.room.Query
import androidx.room.Update
import de.ncards.core.database.entity.SyncOutboxEntity
import kotlinx.coroutines.flow.Flow

/**
 * `sync_outbox` 的读写。
 *
 * **只有表和最基础的读写。** 合并、退避、4xx 不重试、先 card 后 card_member 的
 * 推送顺序 —— 全部归 T-251（§5.4.3）。这里刻意不放任何策略，
 * 免得 T-251 到时候要先把这里的半成品拆掉。
 */
@Dao
interface SyncOutboxDao {
    /** flush 时取到点的记录。顺序按 `id`，即入队顺序（T-251 会在此之上排 card 先于 card_member）。 */
    @Query(
        """
        SELECT * FROM sync_outbox
        WHERE next_attempt_at <= :now AND attempt_count < :maxAttempts
        ORDER BY id ASC
        LIMIT :limit
        """,
    )
    suspend fun findDue(
        now: Long,
        maxAttempts: Int,
        limit: Int,
    ): List<SyncOutboxEntity>

    /** 同一实体已有的 pending 记录。T-251 的合并逻辑从这里取。 */
    @Query("SELECT * FROM sync_outbox WHERE entity_type = :entityType AND entity_id = :entityId ORDER BY id ASC")
    suspend fun findFor(
        entityType: String,
        entityId: String,
    ): List<SyncOutboxEntity>

    /** UI 的失败提示（§5.4.3：10 次后标 FAILED 并在 UI 提示）。 */
    @Query("SELECT COUNT(*) FROM sync_outbox WHERE attempt_count >= :maxAttempts")
    fun observeFailedCount(maxAttempts: Int): Flow<Int>

    @Insert
    suspend fun insert(entry: SyncOutboxEntity): Long

    @Update
    suspend fun update(entry: SyncOutboxEntity)

    @Query("DELETE FROM sync_outbox WHERE id = :id")
    suspend fun deleteById(id: Long)

    @Query("DELETE FROM sync_outbox WHERE entity_type = :entityType AND entity_id = :entityId")
    suspend fun deleteFor(
        entityType: String,
        entityId: String,
    )

    @Query("SELECT COUNT(*) FROM sync_outbox")
    suspend fun count(): Int
}
