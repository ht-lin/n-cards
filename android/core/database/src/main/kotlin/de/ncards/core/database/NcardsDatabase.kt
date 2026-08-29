package de.ncards.core.database

import androidx.room.Database
import androidx.room.RoomDatabase
import de.ncards.core.database.dao.CardDao
import de.ncards.core.database.dao.CardMemberDao
import de.ncards.core.database.dao.SyncMetaDao
import de.ncards.core.database.dao.SyncOutboxDao
import de.ncards.core.database.entity.CardEntity
import de.ncards.core.database.entity.CardMemberEntity
import de.ncards.core.database.entity.SyncMetaEntity
import de.ncards.core.database.entity.SyncOutboxEntity

/**
 * 本地库（§4.3：**UI 的唯一真相源**）。全库经 SQLCipher 加密，见 [SqlCipher]。
 *
 * ## 改 schema 的规矩（§13.5）
 *
 * `exportSchema = true`，导出的 JSON 在模块内 `schemas/` 且**必须入库** ——
 * `MigrationTestHelper` 拿它当基准，没有它，往后每一次 schema 变更都只能靠人眼评审。
 *
 * **禁止 `fallbackToDestructiveMigration()`。** 离线优先意味着本地可能攒着
 * 还没推上去的写入（`sync_outbox`），一句 destructive 会把它们连同用户手输的
 * 码值一起抹掉，而且是静默的。每次改 schema：版本 +1、写 `Migration`、
 * 写往返测试，一条都不能省。
 *
 * 表只有四张是**刻意**的（T-009）。`friendships` / `share_invitations` 是 M3，
 * `users` 要等 T-107 的 username —— 现在建等于凭空猜列，而 expand–contract
 * 规范意味着猜错要付迁移代价。
 */
@Database(
    entities = [
        CardEntity::class,
        CardMemberEntity::class,
        SyncOutboxEntity::class,
        SyncMetaEntity::class,
    ],
    version = NcardsDatabase.VERSION,
    exportSchema = true,
)
abstract class NcardsDatabase : RoomDatabase() {
    abstract fun cardDao(): CardDao

    abstract fun cardMemberDao(): CardMemberDao

    abstract fun syncOutboxDao(): SyncOutboxDao

    abstract fun syncMetaDao(): SyncMetaDao

    companion object {
        const val VERSION = 1

        /** 落在 `/data/data/<pkg>/databases/` 下。WAL 与 SHM 是同名加后缀。 */
        const val FILE_NAME = "ncards.db"
    }
}
