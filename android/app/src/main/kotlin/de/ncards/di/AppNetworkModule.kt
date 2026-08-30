package de.ncards.di

import dagger.Module
import dagger.Provides
import dagger.hilt.InstallIn
import dagger.hilt.components.SingletonComponent
import de.ncards.BuildConfig
import de.ncards.core.network.impl.NetworkConfig
import okhttp3.HttpUrl.Companion.toHttpUrl
import javax.inject.Singleton

/**
 * `core:network:impl` 唯一一个自己给不出的绑定（T-010）。
 *
 * 它落在 `:app` 而不是网络模块里，是因为这三个值全都只有 application 模块知道：
 * `BuildConfig.API_BASE_URL` 由 buildType 决定（debug → staging，其余 → 生产），
 * `VERSION_NAME` / `VERSION_CODE` 是 app 的版本，`DEBUG` 决定装不装日志拦截器
 * （§7.3：release 构建不得输出任何日志）。
 *
 * `ncards.android.library` 里 `buildFeatures.buildConfig = false` 是写死的，
 * 所以这条路是唯一的 —— 详见 `NetworkConfig` 的类注释。
 */
@Module
@InstallIn(SingletonComponent::class)
internal object AppNetworkModule {
    @Provides
    @Singleton
    fun provideNetworkConfig(): NetworkConfig =
        NetworkConfig(
            baseUrl = BuildConfig.API_BASE_URL.toHttpUrl(),
            // §6.1 的形态：`android/1.4.0 (26)`。拼出来而不是硬编码，
            // 这样发版改 versionName 不需要记得同步改这里。
            clientHeader = "android/${BuildConfig.VERSION_NAME} (${BuildConfig.VERSION_CODE})",
            enableHttpLogging = BuildConfig.DEBUG,
        )
}
