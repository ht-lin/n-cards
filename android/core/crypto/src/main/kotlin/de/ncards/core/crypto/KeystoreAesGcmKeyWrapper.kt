package de.ncards.core.crypto

import android.security.keystore.KeyGenParameterSpec
import android.security.keystore.KeyPermanentlyInvalidatedException
import android.security.keystore.KeyProperties
import timber.log.Timber
import java.io.IOException
import java.security.GeneralSecurityException
import java.security.KeyStore
import java.security.KeyStoreException
import java.security.UnrecoverableKeyException
import javax.crypto.AEADBadTagException
import javax.crypto.Cipher
import javax.crypto.KeyGenerator
import javax.crypto.SecretKey
import javax.crypto.spec.GCMParameterSpec
import javax.inject.Inject
import javax.inject.Singleton

/**
 * [KeyWrapper] 的生产实现：Android Keystore 里的一把 AES-256 密钥，GCM 模式。
 *
 * 密文布局是 `[1 字节版本][12 字节 IV][密文 || 16 字节 GCM tag]`。
 * 版本字节现在恒为 [FORMAT_VERSION]；留着是因为换算法／换 IV 长度时，
 * 没有它就只能靠长度猜，而猜错的表现是「解密失败」——
 * 与「密钥真的失效了」不可区分，会把用户的数据库白白清掉。
 *
 * ## 为什么密钥不绑定用户认证（§3.4 的有意取舍）
 *
 * [KeyGenParameterSpec.Builder.setUserAuthenticationRequired] **保持 false**。
 * Widget（T-254）与 FCM 后台同步（T-250）必须在无用户交互时读写数据库，
 * 绑定用户认证会让它们在锁屏状态下直接失败。
 *
 * 防护目标因此是「设备丢失且未解锁」与「应用间越权」，**不是**取证级攻击：
 * 攻击者若能在设备解锁状态下以本应用的 UID 执行代码，就能让 Keystore 替他解密。
 * 这条边界写在 §7.2 的 T08 与 ADR-0007 里，**不得在对外文案中夸大**。
 */
@Singleton
internal class KeystoreAesGcmKeyWrapper
    @Inject
    constructor() : KeyWrapper {
        /**
         * ⚠️ **加密路径上「密钥不可用」不是死局，解密路径上才是。**
         *
         * 这里要写的是一段**新**密文，换一把密钥就行；而 [unwrap] 面对的是一段
         * 已经存在的密文，换密钥只会让它更解不开。两条路径因此对同一个异常
         * 有相反的正确反应 —— 把它们写成一样才是 bug。
         *
         * 具体场景：blob 被清掉了（或首次安装）但 Keystore 里那把旧密钥还在、
         * 且已被系统失效。不轮换的话 `Cipher.init` 会抛
         * `KeyPermanentlyInvalidatedException`，而这条调用发生在 DI 建库的路上 ——
         * 表现是应用每次启动都崩，且清应用数据也修不好（Keystore 不跟着走）。
         */
        override fun wrap(plaintext: ByteArray): ByteArray =
            try {
                encryptWith(loadOrCreateKey(), plaintext)
            } catch (e: GeneralSecurityException) {
                rotateAndEncrypt(plaintext, e)
            } catch (e: KeyMaterialUnrecoverableException) {
                rotateAndEncrypt(plaintext, e)
            }

        private fun rotateAndEncrypt(
            plaintext: ByteArray,
            cause: Exception,
        ): ByteArray {
            // 不打印明文或密钥材料（§7.3）。release 里没有种 Tree，这行是空操作。
            Timber.w(cause, "包裹密钥不可用，轮换后重试")
            reset()
            return try {
                encryptWith(generateKey(), plaintext)
            } catch (e: GeneralSecurityException) {
                throw KeyMaterialUnrecoverableException("轮换密钥后仍无法加密：${e.javaClass.simpleName}", e)
            }
        }

        private fun encryptWith(
            key: SecretKey,
            plaintext: ByteArray,
        ): ByteArray {
            val cipher = Cipher.getInstance(TRANSFORMATION)
            // IV 由 Keystore 自己生成：KeyGenParameterSpec 默认
            // setRandomizedEncryptionRequired(true)，显式传 IV 反而会被拒绝。
            cipher.init(Cipher.ENCRYPT_MODE, key)
            val ciphertext = cipher.doFinal(plaintext)

            return ByteArray(FORMAT_HEADER_SIZE + ciphertext.size).also { out ->
                out[0] = FORMAT_VERSION
                cipher.iv.copyInto(out, destinationOffset = 1)
                ciphertext.copyInto(out, destinationOffset = FORMAT_HEADER_SIZE)
            }
        }

        override fun unwrap(ciphertext: ByteArray): ByteArray {
            if (ciphertext.size <= FORMAT_HEADER_SIZE || ciphertext[0] != FORMAT_VERSION) {
                throw KeyMaterialUnrecoverableException(
                    "密文格式无法识别（长度 ${ciphertext.size}，版本字节 ${ciphertext.firstOrNull()}）",
                )
            }

            val key = existingKey()
                ?: throw KeyMaterialUnrecoverableException("Keystore 里没有别名 $KEY_ALIAS —— 密钥已消失，旧密文不可解")

            return try {
                Cipher.getInstance(TRANSFORMATION).run {
                    init(
                        Cipher.DECRYPT_MODE,
                        key,
                        GCMParameterSpec(GCM_TAG_BITS, ciphertext, 1, IV_SIZE),
                    )
                    doFinal(ciphertext, FORMAT_HEADER_SIZE, ciphertext.size - FORMAT_HEADER_SIZE)
                }
            } catch (e: KeyPermanentlyInvalidatedException) {
                throw KeyMaterialUnrecoverableException("Keystore 密钥已被系统永久失效", e)
            } catch (e: AEADBadTagException) {
                // GCM 认证失败：密文被改过，或换了一把密钥。两者都是不可解。
                throw KeyMaterialUnrecoverableException("GCM 认证失败 —— 密文与当前密钥不匹配", e)
            } catch (e: GeneralSecurityException) {
                throw KeyMaterialUnrecoverableException("解包失败：${e.javaClass.simpleName}", e)
            }
        }

        override fun reset() {
            try {
                keystore().deleteEntry(KEY_ALIAS)
            } catch (e: KeyStoreException) {
                // 删不掉也不致命：下面 loadOrCreateKey() 会用同一个别名覆盖式重建。
                // 不打印别名以外的任何东西（§7.3）。
                Timber.w(e, "删除 Keystore 别名失败，将覆盖式重建")
            }
        }

        private fun loadOrCreateKey(): SecretKey = existingKey() ?: generateKey()

        private fun existingKey(): SecretKey? =
            try {
                keystore().getKey(KEY_ALIAS, null) as? SecretKey
            } catch (e: UnrecoverableKeyException) {
                throw KeyMaterialUnrecoverableException("Keystore 条目存在但无法取出", e)
            } catch (e: GeneralSecurityException) {
                throw KeyMaterialUnrecoverableException("读取 Keystore 条目失败：${e.javaClass.simpleName}", e)
            }

        private fun generateKey(): SecretKey =
            KeyGenerator
                .getInstance(KeyProperties.KEY_ALGORITHM_AES, ANDROID_KEYSTORE)
                .apply {
                    init(
                        KeyGenParameterSpec
                            .Builder(KEY_ALIAS, KeyProperties.PURPOSE_ENCRYPT or KeyProperties.PURPOSE_DECRYPT)
                            .setBlockModes(KeyProperties.BLOCK_MODE_GCM)
                            .setEncryptionPaddings(KeyProperties.ENCRYPTION_PADDING_NONE)
                            .setKeySize(KEY_SIZE_BITS)
                            // ⚠️ 见类注释：这个 false 是 §3.4 的取舍，不是漏写。
                            // 改成 true 会让 Widget 与后台同步在锁屏时全部失败。
                            .setUserAuthenticationRequired(false)
                            .build(),
                    )
                }.generateKey()

        private fun keystore(): KeyStore =
            try {
                KeyStore.getInstance(ANDROID_KEYSTORE).apply { load(null) }
            } catch (e: IOException) {
                throw KeyMaterialUnrecoverableException("AndroidKeyStore 无法加载", e)
            } catch (e: GeneralSecurityException) {
                throw KeyMaterialUnrecoverableException("AndroidKeyStore 无法加载：${e.javaClass.simpleName}", e)
            }

        private companion object {
            const val ANDROID_KEYSTORE = "AndroidKeyStore"

            /**
             * 别名带版本后缀。将来若要换算法，是**新起一个别名**而不是原地改参数 ——
             * 原地改会让旧密文变成解不开的垃圾，而调用方只会看到「密钥失效」。
             */
            const val KEY_ALIAS = "ncards_db_master_v1"

            const val TRANSFORMATION = "AES/GCM/NoPadding"
            const val KEY_SIZE_BITS = 256
            const val GCM_TAG_BITS = 128
            const val IV_SIZE = 12
            const val FORMAT_VERSION: Byte = 1
            const val FORMAT_HEADER_SIZE = 1 + IV_SIZE
        }
    }
