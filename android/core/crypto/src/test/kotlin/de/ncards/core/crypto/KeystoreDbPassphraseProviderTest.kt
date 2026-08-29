package de.ncards.core.crypto

import org.junit.jupiter.api.Assertions.assertArrayEquals
import org.junit.jupiter.api.Assertions.assertEquals
import org.junit.jupiter.api.Assertions.assertFalse
import org.junit.jupiter.api.Assertions.assertNotNull
import org.junit.jupiter.api.Assertions.assertTrue
import org.junit.jupiter.api.DisplayName
import org.junit.jupiter.api.Test

/**
 * [KeystoreDbPassphraseProvider] 的三条分支（首次 / 复用 / 恢复）。
 *
 * Keystore 在 JVM 上不存在，所以这里换掉的是 [SecretStore] 那一层 ——
 * 这正是把 `KeyWrapper` / `SecretStore` 拆成接口的理由（见 `KeyWrapper` 的 KDoc）。
 * 真加密走仪器测试。
 */
class KeystoreDbPassphraseProviderTest {
    private val secretStore = FakeSecretStore()
    private val provider = KeystoreDbPassphraseProvider(secretStore)

    @Test
    @DisplayName("首次调用生成 32 字节并落盘，标记为新生成")
    fun firstCallGenerates() {
        val passphrase = provider.passphrase()

        assertEquals(PASSPHRASE_SIZE, passphrase.bytes.size, "§3.4 要求 32 字节")
        assertTrue(passphrase.isNewlyGenerated)
        assertNotNull(secretStore.stored[KEY], "必须落盘，否则重启后就是一个新库")
    }

    @Test
    @DisplayName("落盘的字节不是 passphrase 明文")
    fun storedValueIsNotPlaintext() {
        val passphrase = provider.passphrase()

        assertFalse(
            secretStore.stored.getValue(KEY).contentEquals(passphrase.bytes),
            "SecretStore 存进去的必须是密文 —— 明文落盘等于这整个模块白写",
        )
    }

    @Test
    @DisplayName("再次调用复用同一份，不重新生成")
    fun secondCallReuses() {
        val first = provider.passphrase()
        val second = provider.passphrase()

        assertArrayEquals(first.bytes, second.bytes)
        assertFalse(second.isNewlyGenerated, "复用时必须是 false，否则 core:database 会去删库")
    }

    @Test
    @DisplayName("每次返回独立的副本 —— SQLCipher 会把传入的数组清零")
    fun returnsDefensiveCopies() {
        val first = provider.passphrase()
        val snapshot = first.bytes.copyOf()
        first.wipe()

        assertArrayEquals(snapshot, provider.passphrase().bytes, "上一份被清零不该影响下一次取值")
    }

    @Test
    @DisplayName("解不开时清空存储并重新生成，标记为新生成")
    fun recoversFromUnrecoverableKey() {
        val original = provider.passphrase().bytes.copyOf()
        secretStore.unrecoverable = true

        val recovered = provider.passphrase()

        assertTrue(recovered.isNewlyGenerated, "core:database 靠这个信号去删掉打不开的库文件")
        assertEquals(PASSPHRASE_SIZE, recovered.bytes.size)
        assertFalse(recovered.bytes.contentEquals(original), "必须是一份新的随机值")
        assertEquals(1, secretStore.clearCount, "旧条目与包裹密钥都要清掉")
    }

    @Test
    @DisplayName("存着的值长度不对时同样重新生成，绝不拿它去开库")
    fun rejectsWrongLengthPassphrase() {
        secretStore.put(KEY, ByteArray(WRONG_SIZE) { 1 })

        val passphrase = provider.passphrase()

        assertEquals(PASSPHRASE_SIZE, passphrase.bytes.size)
        assertTrue(passphrase.isNewlyGenerated)
        assertEquals(1, secretStore.clearCount)
    }

    @Test
    @DisplayName("恢复之后能稳定复用新的 passphrase")
    fun recoveryIsNotAnInfiniteLoop() {
        provider.passphrase()
        secretStore.unrecoverable = true
        val recovered = provider.passphrase()

        val next = provider.passphrase()

        assertArrayEquals(recovered.bytes, next.bytes)
        assertFalse(next.isNewlyGenerated, "恢复后若每次都报新生成，库会被反复删空")
    }

    private companion object {
        const val KEY = "db_passphrase"
        const val PASSPHRASE_SIZE = 32
        const val WRONG_SIZE = 16
    }
}
