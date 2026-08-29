package de.ncards.core.database.dao

import androidx.room.Dao
import androidx.room.Query
import androidx.room.Upsert
import de.ncards.core.database.entity.CardMemberEntity
import kotlinx.coroutines.flow.Flow

/**
 * `card_members` 的读写：成员关系 + **每成员私有**的摆放（§5.2）。
 *
 * ⚠️ 成员列表的可见性裁剪**不在这里做**。同步层下发给这台设备的行本来就已经
 * 裁剪过了（`card_member` 的 `audience` 只含 `[owner, 当事人]`），所以
 * 「viewer 看不到别的 viewer」是靠**本地库里根本没有那些行**保证的，
 * 不是靠查询加 `WHERE`。若哪天这里需要写一个「过滤掉其他 viewer」的条件，
 * 说明同步层漏了（威胁模型 T21），该修的是那边。
 */
@Dao
interface CardMemberDao {
    @Query("SELECT * FROM card_members WHERE card_id = :cardId ORDER BY role DESC, joined_at ASC")
    fun observeMembers(cardId: String): Flow<List<CardMemberEntity>>

    @Query("SELECT * FROM card_members WHERE card_id = :cardId AND user_id = :userId")
    suspend fun findMember(
        cardId: String,
        userId: String,
    ): CardMemberEntity?

    @Upsert
    suspend fun upsert(members: List<CardMemberEntity>)

    /**
     * 改自己的排序 / 置顶（§5.2）。
     *
     * 这是 viewer **唯一**的上行写入（§3.5）：它是成员私有字段，
     * 不动卡本身，因此**不参与 revision**，也不触发冲突协议。
     */
    @Query(
        """
        UPDATE card_members SET sort_order = :sortOrder, is_pinned = :isPinned
        WHERE card_id = :cardId AND user_id = :userId
        """,
    )
    suspend fun updatePlacement(
        cardId: String,
        userId: String,
        sortOrder: Int,
        isPinned: Boolean,
    )

    /** 收到成员墓碑时调用：只删这一行，卡本身不动（§5.4.3 的「退出共享」）。 */
    @Query("DELETE FROM card_members WHERE card_id = :cardId AND user_id = :userId")
    suspend fun deleteMember(
        cardId: String,
        userId: String,
    )

    @Query("SELECT COUNT(*) FROM card_members WHERE card_id = :cardId")
    suspend fun memberCount(cardId: String): Int
}
