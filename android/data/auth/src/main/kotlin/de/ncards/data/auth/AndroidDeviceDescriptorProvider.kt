package de.ncards.data.auth

import android.content.Context
import android.content.SharedPreferences
import android.os.Build
import dagger.hilt.android.qualifiers.ApplicationContext
import de.ncards.core.network.api.model.Device
import java.util.UUID
import javax.inject.Inject
import javax.inject.Singleton

/**
 * 登录时要随请求带上的设备描述（§6.2 的 `device` 对象）。
 *
 * 服务端用它建 `devices` 行，并在**新设备登录**时给用户所有既有设备发推送 +
 * 给邮箱发提醒信（§7.1「新设备登录的防护」）。
 *
 * ============================================================================
 * ⚠️⚠️ 安装 id 必须活过一次「清会话」
 * ============================================================================
 * `Device.id` 在契约里是「客户端生成，安装级唯一」。**每次登录换一个新 id
 * 的后果不是多一行数据**，而是：用户每一次重新登录，自己的所有其他设备都会收到
 * 一条「有新设备登录了你的账号」的推送，邮箱里多一封安全提醒信。
 * §7.2 的 T02 把那封信列为「邮箱被接管」唯一能被用户察觉的信号 ——
 * 让它变成登录噪声，等于把那条防线拆了。
 *
 * 所以它存在一个**独立**的、清会话不碰的 `SharedPreferences` 文件里。
 *
 * ============================================================================
 * 为什么**不**放进 `SecretStore`
 * ============================================================================
 * 两条理由，任何一条都够：
 *
 * 1. 它不是密钥。`SecretStore` 的 KDoc 逐字写了「用途**只**这两类
 *    （db passphrase、access/refresh token），别把它当通用缓存」，
 *    而且每次读写都要过一趟 Keystore。
 * 2. 它必须活过 `KeystoreSecretStore.clear()`。那个方法会丢弃包裹密钥，
 *    而 `DbPassphraseProvider` 的恢复路径**会**调它（Keystore 密钥失效时）。
 *    设备 id 跟着一起没掉，就是上面那段说的登录噪声。
 *
 * 落盘是明文，这没问题：它不是凭据，拿到它换不到任何东西；
 * 而 `android:allowBackup="false"` + `dataExtractionRules` 已经挡住了它跨设备复制。
 */
@Singleton
internal class AndroidDeviceDescriptorProvider
    @Inject
    constructor(
        @ApplicationContext private val context: Context,
    ) : DeviceDescriptorProvider {
        private val prefs: SharedPreferences by lazy {
            context.getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)
        }

        override fun current(): Device =
            Device(
                id = installId(),
                platform = Device.Platform.android,
                model = Build.MODEL,
                osVersion = Build.VERSION.RELEASE,
                appVersion = appVersion(),
            )

        /**
         * 首次调用生成并落盘，之后原样读回。
         *
         * `commit()` 而非 `apply()`，理由与 `KeystoreSecretStore` 相同：
         * 进程若在异步落盘完成前被杀，下次启动会当成「从未存过」而生成一个新的 id ——
         * 而那正是上面整段要避免的事。
         */
        private fun installId(): UUID {
            // 存坏了（被改过 / 写了一半）就重新生成：一个畸形的 id 会让登录请求
            // 400，而那是个用户自己修不好的死局。
            val stored = prefs
                .getString(KEY_DEVICE_ID, null)
                ?.let { raw -> runCatching { UUID.fromString(raw) }.getOrNull() }
            if (stored != null) return stored

            val fresh = UUID.randomUUID()
            prefs.edit().putString(KEY_DEVICE_ID, fresh.toString()).commit()
            return fresh
        }

        /**
         * `versionName`，形如 `1.4.0`。
         *
         * 不能用 `BuildConfig`：`ncards.android.library` 里 `buildConfig = false`
         * 是写死的，而且 library 的 `BuildConfig` 里也拿不到 app 的版本
         * （`NetworkConfig` 的类注释记的是同一件事）。
         *
         * ⚠️ 与 `X-Client` 里那个版本是同一个值，但**不从 `NetworkConfig.clientHeader`
         * 里解析出来**：那是个为服务端拼的展示字符串（`android/1.4.0 (26)`），
         * 拿它当结构化数据的来源，等于给自己造一个只有在有人改格式时才会炸的耦合。
         */
        @Suppress("DEPRECATION")
        private fun appVersion(): String =
            // getPackageInfo(String, PackageInfoFlags) 要 API 33，而 minSdk 是 26。
            // 带 int flags 的这个重载在全部支持的版本上都在，只是标了 deprecated。
            context.packageManager
                .getPackageInfo(context.packageName, 0)
                .versionName
                ?: UNKNOWN_VERSION

        private companion object {
            /** 与 `ncards_secrets_v1` 刻意不是同一个文件 —— 见类注释。 */
            const val PREFS_NAME = "ncards_device_v1"
            const val KEY_DEVICE_ID = "device_id"

            /** `versionName` 在理论上可以为 null（没设 versionName 的构型）。 */
            const val UNKNOWN_VERSION = "0.0.0"
        }
    }
