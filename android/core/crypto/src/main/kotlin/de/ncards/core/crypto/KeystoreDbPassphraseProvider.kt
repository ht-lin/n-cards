package de.ncards.core.crypto

import timber.log.Timber
import java.security.SecureRandom
import javax.inject.Inject
import javax.inject.Singleton

/**
 * [DbPassphraseProvider] 的生产实现。三条分支，三条都有测试：
 *
 * 1. **首次**：[SecretStore] 里没有 → 生成 32 字节 → 存 → `isNewlyGenerated = true`
 * 2. **复用**：有且解得开 → 原样返回 → `isNewlyGenerated = false`
 * 3. **恢复**：有但解不开（[KeyMaterialUnrecoverableException]）→ 清空存储与包裹密钥
 *    → 当作首次重来 → `isNewlyGenerated = true`
 *
 * 第 3 条是「应用会不会被永久砖化」的分水岭。没有它，一次 Keystore 失效就会让
 * 每次启动都抛异常，而用户除了清应用数据别无他法 —— 卸载重装是唯一出路，
 * 那正是我们本可以自动做掉的事。
 */
@Singleton
internal class KeystoreDbPassphraseProvider
    @Inject
    constructor(
        private val secretStore: SecretStore,
    ) : DbPassphraseProvider {
        override fun passphrase(): Passphrase {
            val existing =
                try {
                    secretStore.get(KEY_DB_PASSPHRASE)
                } catch (e: KeyMaterialUnrecoverableException) {
                    // 不打印密文、不打印 key 之外的任何内容（§7.3）。
                    // release 构建里没有种 Tree，这行是空操作（见 NcardsApplication）。
                    Timber.w(e, "数据库 passphrase 已不可解，将重新生成（本地未同步数据会丢失）")
                    secretStore.clear()
                    null
                }

            if (existing != null && existing.size == PASSPHRASE_SIZE) {
                return Passphrase(existing, isNewlyGenerated = false)
            }
            if (existing != null) {
                // 长度不对 = 存的不是我们写的东西。当成不可解处理，别拿它去开库。
                Timber.w("数据库 passphrase 长度异常（${existing.size} 字节），将重新生成")
                secretStore.clear()
            }

            return Passphrase(generateAndStore(), isNewlyGenerated = true)
        }

        private fun generateAndStore(): ByteArray =
            ByteArray(PASSPHRASE_SIZE)
                .also { fresh ->
                    SecureRandom().nextBytes(fresh)
                    secretStore.put(KEY_DB_PASSPHRASE, fresh)
                }

        private companion object {
            const val KEY_DB_PASSPHRASE = "db_passphrase"

            /** §3.4：32 字节随机值。SQLCipher 会把它当原始密钥材料而非口令。 */
            const val PASSPHRASE_SIZE = 32
        }
    }
