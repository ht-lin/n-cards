package de.ncards

import android.app.Application
import dagger.hilt.android.HiltAndroidApp
import timber.log.Timber

/**
 * DI 根（§12.3）。
 *
 * ⚠️ **日志只在 debug 构建里存在。** §7.3 的 MUST 清单原文：「禁止在 release
 * 构建输出任何日志（`Timber` 只在 debug 种植 `DebugTree`）」。
 *
 * 这不是洁癖。这个 App 处理的是条码值（`barcode_value`）与邮箱 —— 前者就是
 * 会员卡本身，后者是账号标识。release 里种任何 Tree，都等于把它们写进
 * 任何一个能读 logcat 的应用能看到的地方。T-011 的敏感日志扫描是第二道防线
 * （禁止把实体对象插值进日志），但第一道就是这里：release 里根本没有 Tree，
 * 于是 `Timber.d(card)` 在生产上是彻底的空操作。
 *
 * 推论：**不要**用 `android.util.Log` 绕过 Timber。detekt 的 potential-bugs
 * 与 T-011 的扫描都会咬。
 */
@HiltAndroidApp
class NcardsApplication : Application() {
    override fun onCreate() {
        super.onCreate()

        if (BuildConfig.DEBUG) {
            Timber.plant(Timber.DebugTree())
        }
    }
}
