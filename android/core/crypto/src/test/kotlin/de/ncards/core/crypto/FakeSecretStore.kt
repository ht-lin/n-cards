package de.ncards.core.crypto

/**
 * [SecretStore] 的内存替身。
 *
 * 存进去的字节按「加密后」对待（[stored] 里放的是 [obfuscate] 过的形态），
 * 这样测试可以断言「落盘的东西 ≠ 明文」而不必真去碰 Keystore。
 * 真正的加密由 `KeystoreAesGcmKeyWrapperTest`（仪器测试）覆盖。
 */
internal class FakeSecretStore : SecretStore {
    val stored = mutableMapOf<String, ByteArray>()

    /** 置为 true 后，[get] 会像 Keystore 密钥失效那样抛异常。 */
    var unrecoverable = false

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
