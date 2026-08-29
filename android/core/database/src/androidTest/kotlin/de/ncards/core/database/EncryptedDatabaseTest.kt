package de.ncards.core.database

import android.content.Context
import android.database.sqlite.SQLiteException
import androidx.room.Room
import androidx.test.core.app.ApplicationProvider
import androidx.test.ext.junit.runners.AndroidJUnit4
import de.ncards.core.database.entity.CardEntity
import kotlinx.coroutines.runBlocking
import org.junit.After
import org.junit.Assert.assertArrayEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertThrows
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test
import org.junit.runner.RunWith
import java.io.File
import android.database.sqlite.SQLiteDatabase as FrameworkSQLiteDatabase

/**
 * T-009 验收标准的第一条：**写入后用外部工具打开 db 文件无法读出明文**（§3.4）。
 *
 * 「外部工具」在这里由两件事共同代表：
 * 1. 系统自带的 `android.database.sqlite.SQLiteDatabase` —— 它就是 `sqlite3`
 *    背后的同一个引擎，打不开就是打不开。
 * 2. 直接读文件的字节，在里面找哨兵串。这一条比第 1 条更狠：即使某天有人
 *    把加密配错成「只加密了部分页」，第 1 条可能仍然报错，而这一条会抓到明文。
 *
 * 三个文件都要查。Room 默认开 WAL，**新写入的行往往还只在 `-wal` 里** ——
 * 只查主库文件的测试会在一个明文 WAL 上给出绿灯。
 */
@RunWith(AndroidJUnit4::class)
class EncryptedDatabaseTest {
    private val context: Context get() = ApplicationProvider.getApplicationContext()

    private lateinit var database: NcardsDatabase

    @Before
    fun setUp() {
        deleteDatabaseFiles()
        database = openDatabase()
    }

    @After
    fun tearDown() {
        if (::database.isInitialized) {
            database.close()
        }
        deleteDatabaseFiles()
    }

    @Test
    fun frameworkSqliteCannotOpenTheFile() {
        writeSentinelCard()
        database.close()

        val failure =
            assertThrows(SQLiteException::class.java) {
                FrameworkSQLiteDatabase
                    .openDatabase(
                        mainFile().path,
                        null,
                        FrameworkSQLiteDatabase.OPEN_READONLY,
                    ).use { db -> db.rawQuery("SELECT * FROM cards", null).use { it.count } }
            }

        // 「不是一个数据库」正是我们要的形状：文件头都不认识，遑论读表。
        assertTrue(
            "期望是「打不开」类的错误，实际：${failure.message}",
            failure.message.orEmpty().contains("not a database", ignoreCase = true) ||
                failure.message.orEmpty().contains("encrypted", ignoreCase = true),
        )
    }

    @Test
    fun fileDoesNotStartWithSqliteHeader() {
        writeSentinelCard()
        database.close()

        val header = mainFile().inputStream().use { input -> ByteArray(SQLITE_HEADER.size).also(input::read) }

        assertFalse(
            "文件头是明文 SQLite 魔数 —— 数据库根本没被加密",
            header.contentEquals(SQLITE_HEADER),
        )
    }

    @Test
    fun noPlaintextInAnyDatabaseFile() {
        writeSentinelCard()
        database.close()

        val files = databaseFiles().filter(File::exists)
        assertTrue("至少要有主库文件可查", files.isNotEmpty())

        files.forEach { file ->
            val bytes = file.readBytes()
            SENTINELS.forEach { sentinel ->
                assertFalse(
                    "明文 “$sentinel” 出现在 ${file.name} 里 —— 这正是 §3.4 要防的事",
                    bytes.containsSequence(sentinel.toByteArray()),
                )
            }
        }
    }

    /**
     * 关闭后用同一份 passphrase 重开，数据还在。
     *
     * 这条守的是一个具体的坑：SQLCipher 的 `SupportOpenHelperFactory` 会一直
     * 持有传进去的那个 ByteArray。若把 provider 内部那份直接交出去而不是副本，
     * 调用方 wipe 之后第二次开库拿到的就是一串 0 —— 而首次安装那一次是好的，
     * 所以这个 bug 只会在用户第二次打开应用时出现。见 `SqlCipher` 的注释。
     */
    @Test
    fun reopensWithTheSamePassphrase() {
        writeSentinelCard()
        database.close()

        database = openDatabase()

        val card = runBlocking { database.cardDao().findCard(CARD_ID) }
        assertTrue("重开后应当还能读到那一行", card != null)
        assertArrayEquals(
            arrayOf(SENTINEL_TITLE, SENTINEL_BARCODE),
            arrayOf(card!!.title, card.barcodeValue),
        )
    }

    private fun openDatabase(): NcardsDatabase =
        Room
            .databaseBuilder(context, NcardsDatabase::class.java, NcardsDatabase.FILE_NAME)
            .openHelperFactory(SqlCipher.openHelperFactory(TEST_PASSPHRASE.copyOf()))
            .build()

    private fun writeSentinelCard() =
        runBlocking {
            database.cardDao().insert(
                CardEntity(
                    id = CARD_ID,
                    ownerId = "01900000-0000-7000-8000-0000000000ff",
                    title = SENTINEL_TITLE,
                    merchantLabel = null,
                    color = "blue_600",
                    barcodeFormat = "EAN_13",
                    barcodeValue = SENTINEL_BARCODE,
                    note = SENTINEL_NOTE,
                    expiresOn = null,
                    revision = 1,
                    memberCount = 1,
                    createdAt = 0,
                    updatedAt = 0,
                ),
            )
        }

    private fun mainFile(): File = context.getDatabasePath(NcardsDatabase.FILE_NAME)

    private fun databaseFiles(): List<File> =
        mainFile().let { main -> listOf(main, File("${main.path}-wal"), File("${main.path}-shm")) }

    private fun deleteDatabaseFiles() = databaseFiles().forEach(File::delete)

    private fun ByteArray.containsSequence(needle: ByteArray): Boolean =
        indices.any { start ->
            start + needle.size <= size &&
                needle.indices.all { offset -> this[start + offset] == needle[offset] }
        }

    private companion object {
        const val CARD_ID = "01900000-0000-7000-8000-000000000001"

        /** 挑成不会被误当成别的东西的串，好在文件字节里找。 */
        const val SENTINEL_TITLE = "SENTINEL-TITLE-Kundenkarte"
        const val SENTINEL_BARCODE = "SENTINEL-BARCODE-4001234567890"
        const val SENTINEL_NOTE = "SENTINEL-NOTE-Notiz"

        val SENTINELS = listOf(SENTINEL_TITLE, SENTINEL_BARCODE, SENTINEL_NOTE)

        /** 未加密的 SQLite 库以这 16 字节开头："SQLite format 3" 后跟一个 NUL。 */
        val SQLITE_HEADER = "SQLite format 3".toByteArray() + 0

        val TEST_PASSPHRASE = ByteArray(32) { index -> (index * 7 + 1).toByte() }
    }
}
