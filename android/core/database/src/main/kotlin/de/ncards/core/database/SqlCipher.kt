package de.ncards.core.database

import androidx.sqlite.db.SupportSQLiteOpenHelper
import net.zetetic.database.sqlcipher.SupportOpenHelperFactory

/**
 * Room 与 SQLCipher 之间那一层薄接线。
 *
 * ## 三件容易搞错的事
 *
 * **① 库要自己 load。** `net.zetetic:sqlcipher-android` 的 `SQLiteDatabase`
 * 静态初始化块里**没有** `System.loadLibrary` —— 不显式加载，第一次开库会以
 * `UnsatisfiedLinkError` 炸掉。这与那个同名的老 artifact
 * （`net.zetetic:android-database-sqlcipher`，用 `SQLiteDatabase.loadLibs(context)`）
 * 不通用，照着老教程写会一路顺到运行时才报错。
 *
 * **② 传进去的是原始密钥材料，不是口令。** 32 字节的 [ByteArray] 重载走的是
 * `PRAGMA key = "x'…'"` 那条路径，SQLCipher **不**再对它做 PBKDF2 ——
 * 这正是我们要的：passphrase 本来就是 `SecureRandom` 出来的 32 字节，
 * 再派生一次只是浪费启动时间。若换成 `String` 重载，语义会**静默**改变。
 *
 * **③ 给一份副本。** 4.9.0 的 `SupportOpenHelperFactory` 会把数组一直持有到
 * 每次 `create()`（已核对字节码：Java 侧不清零，但 native 侧不保证）。
 * 调用方在 `finally` 里 wipe 自己那份，所以这里必须先 `copyOf()`，
 * 否则数据库关闭后重开会拿到一串 0。
 */
internal object SqlCipher {
    private val nativeLibrary = lazy { System.loadLibrary("sqlcipher") }

    fun openHelperFactory(passphrase: ByteArray): SupportSQLiteOpenHelper.Factory {
        nativeLibrary.value // 整个进程只加载一次；重复调用是空操作。
        return SupportOpenHelperFactory(passphrase.copyOf())
    }
}
