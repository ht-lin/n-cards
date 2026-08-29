package de.ncards.core.crypto

import android.content.Context
import android.content.SharedPreferences
import android.util.Base64
import dagger.hilt.android.qualifiers.ApplicationContext
import javax.inject.Inject
import javax.inject.Singleton

/**
 * [SecretStore] 的生产实现：[KeyWrapper] 负责加密，**普通** SharedPreferences 负责落盘。
 *
 * 「普通」是刻意的。加密强度全部来自 Keystore 里那把出不来的 AES 密钥；
 * 容器本身是不是加密的，对威胁模型没有任何影响 —— 攻击者读到这个文件，
 * 拿到的是一串没有密钥就无意义的 base64。再叠一层 EncryptedSharedPreferences
 * 只会多一份依赖和一份要维护的失效路径。理由完整版见 ADR-0007。
 *
 * 文件名带版本后缀：将来若要换密文格式，是**新起一个文件**并留下迁移代码，
 * 而不是让新旧格式在同一个文件里混着。
 */
@Singleton
internal class KeystoreSecretStore
    @Inject
    constructor(
        @ApplicationContext private val context: Context,
        private val keyWrapper: KeyWrapper,
    ) : SecretStore {
        private val prefs: SharedPreferences by lazy {
            context.getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)
        }

        override fun get(key: String): ByteArray? {
            val encoded = prefs.getString(key, null) ?: return null
            val ciphertext =
                try {
                    Base64.decode(encoded, Base64.NO_WRAP)
                } catch (e: IllegalArgumentException) {
                    throw KeyMaterialUnrecoverableException("条目 $key 的 base64 已损坏", e)
                }
            return keyWrapper.unwrap(ciphertext)
        }

        override fun put(
            key: String,
            value: ByteArray,
        ) {
            val encoded = Base64.encodeToString(keyWrapper.wrap(value), Base64.NO_WRAP)
            // commit 而非 apply：passphrase 与令牌属于「写完必须已经在盘上」的东西。
            // 进程若在 apply 的异步落盘完成前被杀，下次启动会当成「从未存过」而
            // 重新生成 passphrase —— 那等于毫无征兆地清库。
            prefs.edit().putString(key, encoded).commit()
        }

        override fun remove(key: String) {
            prefs.edit().remove(key).commit()
        }

        override fun clear() {
            prefs.edit().clear().commit()
            keyWrapper.reset()
        }

        private companion object {
            const val PREFS_NAME = "ncards_secrets_v1"
        }
    }
