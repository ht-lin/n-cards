package de.ncards.core.crypto

import android.security.keystore.KeyInfo
import androidx.test.ext.junit.runners.AndroidJUnit4
import org.junit.Assert.assertArrayEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertThrows
import org.junit.Before
import org.junit.Test
import org.junit.runner.RunWith
import java.security.KeyStore
import javax.crypto.SecretKey
import javax.crypto.SecretKeyFactory

/**
 * 真 Keystore 上的 [KeystoreAesGcmKeyWrapper]。
 *
 * 仪器测试而非单测：Android Keystore 是系统服务，JVM 上不存在，Robolectric 的
 * 影子也覆盖不到 GCM 那条路径。这就是为什么 [KeyWrapper] 是个接口 ——
 * 逻辑分支在 `KeystoreDbPassphraseProviderTest` 里用替身跑，
 * 密码学本身在这里用真货跑。
 */
@RunWith(AndroidJUnit4::class)
class KeystoreAesGcmKeyWrapperTest {
    private lateinit var wrapper: KeystoreAesGcmKeyWrapper

    @Before
    fun setUp() {
        wrapper = KeystoreAesGcmKeyWrapper()
        // 上一个测试可能留下了别名，从干净状态开始。
        wrapper.reset()
    }

    @Test
    fun roundTripsPlaintext() {
        val secret = ByteArray(SECRET_SIZE) { it.toByte() }

        assertArrayEquals(secret, wrapper.unwrap(wrapper.wrap(secret)))
    }

    @Test
    fun producesDifferentCiphertextEachTime() {
        val secret = ByteArray(SECRET_SIZE) { 7 }

        val first = wrapper.wrap(secret)
        val second = wrapper.wrap(secret)

        assertFalse(
            "IV 必须每次不同 —— GCM 下重用 IV 会直接泄露明文异或值",
            first.contentEquals(second),
        )
        assertArrayEquals(secret, wrapper.unwrap(first))
        assertArrayEquals(secret, wrapper.unwrap(second))
    }

    @Test
    fun ciphertextDoesNotContainPlaintext() {
        val secret = ByteArray(SECRET_SIZE) { 0x42 }

        val wrapped = wrapper.wrap(secret)

        assertFalse("明文不得原样出现在密文里", wrapped.containsSequence(secret))
    }

    /** 新的包裹密钥拿到手，旧密文就该是垃圾 —— 这正是恢复路径依赖的性质。 */
    @Test
    fun ciphertextIsUnrecoverableAfterReset() {
        val wrapped = wrapper.wrap(ByteArray(SECRET_SIZE) { 3 })

        wrapper.reset()

        assertThrows(KeyMaterialUnrecoverableException::class.java) { wrapper.unwrap(wrapped) }
    }

    @Test
    fun rejectsTamperedCiphertext() {
        val wrapped = wrapper.wrap(ByteArray(SECRET_SIZE) { 5 })
        // 动最后一个字节 = 动 GCM tag，认证必须失败。
        wrapped[wrapped.lastIndex] = (wrapped[wrapped.lastIndex] + 1).toByte()

        assertThrows(KeyMaterialUnrecoverableException::class.java) { wrapper.unwrap(wrapped) }
    }

    @Test
    fun rejectsTruncatedCiphertext() {
        assertThrows(KeyMaterialUnrecoverableException::class.java) {
            wrapper.unwrap(ByteArray(FORMAT_HEADER_SIZE))
        }
    }

    @Test
    fun rejectsUnknownFormatVersion() {
        val wrapped = wrapper.wrap(ByteArray(SECRET_SIZE) { 9 })
        wrapped[0] = UNKNOWN_FORMAT_VERSION

        assertThrows(KeyMaterialUnrecoverableException::class.java) { wrapper.unwrap(wrapped) }
    }

    /**
     * 「进程重启后还解得开」的核心机制：密钥活在 Keystore 里，
     * 而不是活在某个对象的字段上。
     */
    @Test
    fun survivesNewWrapperInstance() {
        val secret = ByteArray(SECRET_SIZE) { 0x5A }
        val wrapped = wrapper.wrap(secret)

        assertArrayEquals(secret, KeystoreAesGcmKeyWrapper().unwrap(wrapped))
    }

    /**
     * §3.4 的取舍，直接查密钥属性而不是绕着测：Widget（T-254）与 FCM 后台同步
     * （T-250）必须能在无用户交互时读写数据库。
     *
     * 守的是「有人好心把 `setUserAuthenticationRequired` 改成 true」——
     * 那会让锁屏状态下的后台同步全部失败，而且**只在真机锁屏时复现**，
     * 本地跑一遍功能测试完全看不出来。
     */
    @Test
    fun keyIsNotBoundToUserAuthentication() {
        wrapper.wrap(ByteArray(SECRET_SIZE) { 1 })

        val key = KeyStore.getInstance(ANDROID_KEYSTORE).apply { load(null) }.getKey(KEY_ALIAS, null) as SecretKey
        val keyInfo =
            SecretKeyFactory
                .getInstance(key.algorithm, ANDROID_KEYSTORE)
                .getKeySpec(key, KeyInfo::class.java) as KeyInfo

        assertFalse(
            "Keystore 密钥不得绑定用户认证（§3.4）—— 绑了就等于关掉 Widget 与后台同步",
            keyInfo.isUserAuthenticationRequired,
        )
    }

    private fun ByteArray.containsSequence(needle: ByteArray): Boolean =
        indices.any { start ->
            start + needle.size <= size &&
                needle.indices.all { offset -> this[start + offset] == needle[offset] }
        }

    private companion object {
        const val ANDROID_KEYSTORE = "AndroidKeyStore"
        const val KEY_ALIAS = "ncards_db_master_v1"
        const val SECRET_SIZE = 32
        const val FORMAT_HEADER_SIZE = 13
        const val UNKNOWN_FORMAT_VERSION: Byte = 99
    }
}
