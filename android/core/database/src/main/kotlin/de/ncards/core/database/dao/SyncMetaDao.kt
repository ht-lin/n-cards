package de.ncards.core.database.dao

import androidx.room.Dao
import androidx.room.Query
import androidx.room.Upsert
import de.ncards.core.database.entity.SyncMetaEntity

/**
 * `sync_meta` 的读写：三路合并的 base 快照（§5.4.3）。
 *
 * 写入时机是**下行同步成功之后**（T-250）：刚写进 Room 的那份服务端态，
 * 同时就是下一次冲突解决的 base。合并算法本身归 T-252。
 */
@Dao
interface SyncMetaDao {
    @Query("SELECT * FROM sync_meta WHERE entity_type = :entityType AND entity_id = :entityId")
    suspend fun find(
        entityType: String,
        entityId: String,
    ): SyncMetaEntity?

    @Query("SELECT revision FROM sync_meta WHERE entity_type = :entityType AND entity_id = :entityId")
    suspend fun findRevision(
        entityType: String,
        entityId: String,
    ): Long?

    @Upsert
    suspend fun upsert(entries: List<SyncMetaEntity>)

    /** 卡的墓碑到了，base 也跟着走 —— 留着只会让下次同 id 的卡拿到一份陈旧的 base。 */
    @Query("DELETE FROM sync_meta WHERE entity_type = :entityType AND entity_id IN (:entityIds)")
    suspend fun deleteFor(
        entityType: String,
        entityIds: List<String>,
    )

    @Query("SELECT COUNT(*) FROM sync_meta")
    suspend fun count(): Int
}
