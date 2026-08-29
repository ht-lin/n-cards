package de.ncards.core.crypto

import android.content.Context
import androidx.test.core.app.ApplicationProvider
import androidx.test.ext.junit.runners.AndroidJUnit4
import org.junit.After
import org.junit.Assert.assertArrayEquals
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test
import org.junit.runner.RunWith

/**
 * T-009 验收标准的第二、三条，跑在真 Keystore + 真 SharedPreferences 上：
 *
 * - 「进程重启后能用 Keystore 解出 passphrase」
 * - 「卸载重装后为全新空库」
 *
 * ⚠️ **这里模拟的是进程重启，不是真的重启进程。** 做法是把 provider、
 * SecretStore、KeyWrapper、`KeyStore` 实例、SharedPreferences 句柄全部重新构造 ——
 * 这正是进程重启后应用会做的事，因为除了 Keystore 与那个 xml 文件，
 * 内存里什么都不会留下。真·重启与真·卸载重装走 `android/README.md` 里的
 * 手工 adb 步骤，那是仪器测试原理上做不到的（测试自己也活在被杀的进程里）。
 */
@RunWith(AndroidJUnit4::class)
class DbPassphrasePersistenceTest {
    private val context: Context get() = ApplicationProvider.getApplicationContext()

    @Before
    fun setUp() = wipeEverything()

    @After
    fun tearDown() = wipeEverything()

    @Test
    fun generatesThirtyTwoBytesOnFirstUse() {
        val passphrase = newProvider().passphrase()

        assertEquals("§3.4 要求 32 字节", PASSPHRASE_SIZE, passphrase.bytes.size)
        assertTrue(passphrase.isNewlyGenerated)
    }

    /** 验收标准：进程重启后能用 Keystore 解出 passphrase。 */
    @Test
    fun survivesSimulatedProcessRestart() {
        val original = newProvider().passphrase()

        // 全新的一套对象 —— 与冷启动时应用拿到的东西完全一致。
        val afterRestart = newProvider().passphrase()

        assertArrayEquals(original.bytes, afterRestart.bytes)
        assertFalse(
            "重启后必须是复用，报新生成会让 core:database 把库删掉",
            afterRestart.isNewlyGenerated,
        )
    }

    @Test
    fun storedBlobIsNotThePassphrase() {
        val passphrase = newProvider().passphrase()

        val stored = context.getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE).getString(PREFS_KEY, null)

        assertNotNull("passphrase 必须落盘，否则重启就是一个新库", stored)
        assertFalse(
            "落盘的必须是密文 —— 明文躺在 SharedPreferences 里等于这个模块白写",
            stored!!.toByteArray().containsSequence(passphrase.bytes),
        )
    }

    /**
     * 验收标准：卸载重装后为全新空库。
     *
     * 卸载会同时带走 Keystore 别名与 SharedPreferences（两者都属于应用的 UID），
     * 所以「重装」等价于这里的清空 —— 拿到的是一份全新 passphrase，
     * 旧库文件用它打不开，`DatabaseModule` 会据此把旧文件删掉。
     */
    @Test
    fun freshInstallYieldsANewPassphrase() {
        val before = newProvider().passphrase().bytes.copyOf()

        wipeEverything()
        val after = newProvider().passphrase()

        assertTrue(after.isNewlyGenerated)
        assertFalse("重装后必须是一份新的随机值", after.bytes.contentEquals(before))
    }

    /**
     * Keystore 密钥失效（系统还原、ROM 行为）后的恢复路径。
     *
     * 这里只清掉密钥、**留着**那条密文 —— 于是 SecretStore 会读到一条解不开的
     * 记录。没有恢复路径的话，这就是应用每次启动都崩的地方。
     */
    @Test
    fun recoversWhenKeystoreKeyIsGoneButBlobRemains() {
        val before = newProvider().passphrase().bytes.copyOf()

        KeystoreAesGcmKeyWrapper().reset()
        val recovered = newProvider().passphrase()

        assertTrue("必须报新生成，core:database 靠它去删掉打不开的库", recovered.isNewlyGenerated)
        assertEquals(PASSPHRASE_SIZE, recovered.bytes.size)
        assertFalse(recovered.bytes.contentEquals(before))
        // 恢复之后要稳定下来，否则库会被每次启动删空一遍。
        assertArrayEquals(recovered.bytes, newProvider().passphrase().bytes)
    }

    private fun newProvider(): DbPassphraseProvider =
        KeystoreDbPassphraseProvider(KeystoreSecretStore(context, KeystoreAesGcmKeyWrapper()))

    private fun wipeEverything() {
        context
            .getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)
            .edit()
            .clear()
            .commit()
        KeystoreAesGcmKeyWrapper().reset()
    }

    private fun ByteArray.containsSequence(needle: ByteArray): Boolean =
        indices.any { start ->
            start + needle.size <= size &&
                needle.indices.all { offset -> this[start + offset] == needle[offset] }
        }

    private companion object {
        const val PREFS_NAME = "ncards_secrets_v1"
        const val PREFS_KEY = "db_passphrase"
        const val PASSPHRASE_SIZE = 32
    }
}
