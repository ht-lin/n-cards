package de.ncards.data.auth

import de.ncards.core.crypto.KeyMaterialUnrecoverableException
import de.ncards.core.crypto.SecretStore

/**
 * [SecretStore] 的内存替身。
 *
 * ⚠️ 这是**第二份**拷贝：`core:crypto` 那一份是 `internal` 且在它自己的 test
 * 源集里，模块外够不着。第三个模块需要它时，该把它提升到 `:core:testing`
 * 并让两边都消费那一份，而不是再抄一次。
 *
 * 存进去的字节按「加密后」对待（[stored] 里放的是 [obfuscate] 过的形态），
 * 这样测试可以断言「落盘的东西 ≠ 明文」而不必真去碰 Keystore。
 */
internal class FakeSecretStore : SecretStore {
    val stored = mutableMapOf<String, ByteArray>()

    /** 置为 true 后，[get] 会像 Keystore 密钥失效那样抛异常。 */
    var unrecoverable = false

    /**
     * ⚠️ `SessionStoreTest` 断言它**恒为 0**。
     *
     * `clear()` 会连包裹密钥一起丢弃，而 SQLCipher 的 `db_passphrase` 就在
     * 同一个门面下 —— `data:auth` 在任何路径上调它都是在毁用户的本地库。
     */
    var clearCount = 0
        private set

    override fun get(key: String): ByteArray? {
        val value = stored[key] ?: return null
        if (unrecoverable) {
            throw KeyMaterialUnrecoverableException("测试注入的不可解状态")
        }
        return obfuscate(value)
    }

    override fun put(
        key: String,
        value: ByteArray,
    ) {
        stored[key] = obfuscate(value)
    }

    override fun remove(key: String) {
        stored.remove(key)
    }

    override fun clear() {
        clearCount++
        stored.clear()
        unrecoverable = false
    }

    /** 对合的「加密」：调两次回到原值，足够让明文与落盘形态不相等。 */
    private fun obfuscate(value: ByteArray): ByteArray =
        ByteArray(value.size) { i -> (value[i].toInt() xor XOR_MASK).toByte() }

    private companion object {
        const val XOR_MASK = 0x5A
    }
}
