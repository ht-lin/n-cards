package de.ncards.core.database.di

import android.content.Context
import androidx.room.Room
import dagger.Module
import dagger.Provides
import dagger.hilt.InstallIn
import dagger.hilt.android.qualifiers.ApplicationContext
import dagger.hilt.components.SingletonComponent
import de.ncards.core.crypto.DbPassphraseProvider
import de.ncards.core.database.NcardsDatabase
import de.ncards.core.database.SqlCipher
import de.ncards.core.database.dao.CardDao
import de.ncards.core.database.dao.CardMemberDao
import de.ncards.core.database.dao.SyncMetaDao
import de.ncards.core.database.dao.SyncOutboxDao
import timber.log.Timber
import java.io.File
import javax.inject.Singleton

/**
 * 建库（§3.4：Room 必须走 SQLCipher）。
 */
@Module
@InstallIn(SingletonComponent::class)
object DatabaseModule {
    @Provides
    @Singleton
    fun provideDatabase(
        @ApplicationContext context: Context,
        passphraseProvider: DbPassphraseProvider,
    ): NcardsDatabase {
        val passphrase = passphraseProvider.passphrase()
        return try {
            if (passphrase.isNewlyGenerated) {
                // 新 passphrase 意味着「旧文件已经永远打不开了」。不删掉它，
                // SQLCipher 会拿新密钥去开旧文件，然后以「file is not a database」
                // 失败 —— 每次启动都失败，用户除了清数据别无他法。
                // 首次安装时下面这几个文件本来就不存在，是个空操作。
                deleteStaleDatabaseFiles(context)
            }
            Room
                .databaseBuilder(context, NcardsDatabase::class.java, NcardsDatabase.FILE_NAME)
                .openHelperFactory(SqlCipher.openHelperFactory(passphrase.bytes))
                // ⚠️ 这里**永远**不加 fallbackToDestructiveMigration()。
                // 离线优先意味着本地可能攒着还没推上去的写入（sync_outbox），
                // 一句 destructive 会把它们连同用户手输的码值一起静默抹掉。
                // 改 schema 的规矩见 NcardsDatabase 的类注释与 §13.5。
                .build()
        } finally {
            // 工厂已经留了自己的副本（见 SqlCipher），这份用完即清。
            passphrase.wipe()
        }
    }

    @Provides
    fun provideCardDao(database: NcardsDatabase): CardDao = database.cardDao()

    @Provides
    fun provideCardMemberDao(database: NcardsDatabase): CardMemberDao = database.cardMemberDao()

    @Provides
    fun provideSyncOutboxDao(database: NcardsDatabase): SyncOutboxDao = database.syncOutboxDao()

    @Provides
    fun provideSyncMetaDao(database: NcardsDatabase): SyncMetaDao = database.syncMetaDao()

    /**
     * 删掉主库文件与它的两个伴生文件。
     *
     * `-wal` / `-shm` **不能漏**：Room 默认开 WAL，只删主文件的话，SQLCipher
     * 会带着上一个库的 WAL 去开新库，表现是随机的损坏错误而不是干脆的失败。
     */
    private fun deleteStaleDatabaseFiles(context: Context) {
        val main: File = context.getDatabasePath(NcardsDatabase.FILE_NAME)
        listOf(main, File("${main.path}-wal"), File("${main.path}-shm"))
            .filter(File::exists)
            .forEach { file ->
                // 只打印文件名，不打印路径以外的任何内容（§7.3）。
                // release 里没有种 Tree，这行是空操作。
                Timber.w("丢弃无法解密的数据库文件 ${file.name}")
                file.delete()
            }
    }
}
