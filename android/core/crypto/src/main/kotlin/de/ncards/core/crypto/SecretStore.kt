package de.ncards.core.crypto

/**
 * 少量敏感字节的持久化存储，落盘的一律是密文。
 *
 * 这是本项目里 `EncryptedSharedPreferences` 的**替代品**（ADR-0007）：
 * §3.4 真正要求的是「经 Android Keystore 的 AES-GCM 密钥包裹」，
 * 而 androidx.security 那个库只是包裹之外的第二层容器，且已被 Google 停止维护。
 *
 * 用途（**只**这两类，别把它当通用缓存）：
 * - 数据库 passphrase（T-009，见 [DbPassphraseProvider]）
 * - access / refresh token（T-150）—— §7.3：令牌**绝不**明文写 SharedPreferences 或 Room
 *
 * 单条 value 应当很小（几十到几百字节）。这里每次读写都要过一次 Keystore，
 * 大对象或高频访问都会难看。
 */
interface SecretStore {
    /**
     * 读出并解密。从未存过返回 `null`。
     *
     * @throws KeyMaterialUnrecoverableException 存过、但已经解不开了。
     *   调用方**必须**处理这条分支：它意味着这条 value 保护的东西
     *   （数据库、会话）也已经不可用，正确反应是清理重来而不是重试。
     */
    fun get(key: String): ByteArray?

    /** 加密后写入，覆盖同 key 的旧值。 */
    fun put(
        key: String,
        value: ByteArray,
    )

    /** 删掉一条。key 不存在时静默返回。 */
    fun remove(key: String)

    /**
     * 清空全部条目**并**丢弃包裹密钥。
     *
     * 调用之后，此前写入的任何密文都不可能再解开 —— 这正是恢复路径要的效果。
     */
    fun clear()
}
