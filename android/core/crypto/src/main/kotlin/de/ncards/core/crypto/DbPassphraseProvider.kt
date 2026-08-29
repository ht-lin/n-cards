package de.ncards.core.crypto

/**
 * SQLCipher 数据库的 passphrase 供给（§3.4）。
 *
 * 32 字节随机值，经 Android Keystore 的 AES-GCM 密钥包裹后存 [SecretStore]。
 * 首次调用生成，之后每次解包同一份 —— 进程重启、设备重启都拿得回来，
 * 因为密钥活在 Keystore 里，而 Keystore 随应用卸载一起消失
 * （这正是「卸载重装后为全新空库」的机制来源，不需要额外代码）。
 */
interface DbPassphraseProvider {
    /**
     * 取 passphrase。**每次返回一份新的副本**，调用方可以随意持有或清零。
     *
     * 副本不是洁癖：SQLCipher 的 `SupportOpenHelperFactory` 默认会在打开数据库后
     * 把传入的 ByteArray 清零，若把内部那份直接交出去，第二次打开就会拿到一串 0。
     */
    fun passphrase(): Passphrase
}

/**
 * 一份 passphrase，外加「它是不是刚刚新生成的」。
 *
 * [isNewlyGenerated] 是给 `core:database` 看的信号，语义是
 * **「用旧 passphrase 加密的任何东西现在都解不开了」**。两种情况会为 true：
 *
 * 1. 首次安装 / 卸载重装 —— 本来就没有旧数据。
 * 2. **恢复路径**：旧 passphrase 存在但已不可解（Keystore 密钥失效），
 *    只能丢弃重来。此时磁盘上那个数据库文件已经是一堆永远打不开的字节，
 *    `core:database` 必须把它删掉再建，否则 SQLCipher 会用新 passphrase
 *    去开旧文件，然后以「file is not a database」失败 —— 应用被永久砖化。
 *
 * 代价说清楚：情况 2 会丢掉尚未同步的 outbox 条目；已同步的数据下次
 * `SyncEngine.sync()`（T-250）会重新拉回。反正解不开的数据本来也读不出来，
 * 这不是一个可选项。
 */
class Passphrase(
    val bytes: ByteArray,
    val isNewlyGenerated: Boolean,
) {
    /** 用完就调。SQLCipher 拿走的是副本，这份得自己清。 */
    fun wipe() {
        bytes.fill(0)
    }
}
