package de.ncards.core.crypto

/**
 * 把一小段字节包起来，以及反过来。
 *
 * 唯一的生产实现是 [KeystoreAesGcmKeyWrapper]（Android Keystore + AES-GCM）。
 * 抽成接口不是为了「将来也许会换实现」，是为了让 [SecretStore] 与
 * [DbPassphraseProvider] 的分支能在 JVM 单测里跑 —— Android Keystore 在 JVM 上
 * 根本不存在，而 `core:*` 吃 Kover 的 70% 行覆盖门禁（build-logic 的 `Coverage.kt`）。
 *
 * 实现必须保证：
 * - [wrap] 每次产生不同的密文（随机 IV），即使明文相同。
 * - 密钥材料**永远不出 Keystore**；这个接口进出的只有明文与密文字节。
 */
internal interface KeyWrapper {
    /** @throws KeyMaterialUnrecoverableException 密钥不可用且无法重建时。 */
    fun wrap(plaintext: ByteArray): ByteArray

    /** @throws KeyMaterialUnrecoverableException 密钥已失效，或密文被篡改/截断时。 */
    fun unwrap(ciphertext: ByteArray): ByteArray

    /** 丢弃当前密钥。下一次 [wrap] 会用一把新的 —— 旧密文从此不可解。 */
    fun reset()
}
