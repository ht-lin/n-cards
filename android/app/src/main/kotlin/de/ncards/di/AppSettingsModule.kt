package de.ncards.di

import dagger.Binds
import dagger.Module
import dagger.hilt.InstallIn
import dagger.hilt.components.SingletonComponent
import de.ncards.core.common.settings.ScreenshotPolicy
import de.ncards.core.model.settings.AppLanguageStore
import de.ncards.settings.AllowScreenshotsPolicy
import de.ncards.settings.AppCompatLanguageStore
import javax.inject.Singleton

/**
 * 下游模块声明的设置类端口，实现从 `:app` 绑进来。
 *
 * ⚠️ 与 `AuthModule` 里那三条 `@Binds` 同一个形状，也同一个风险：
 * 这些绑定没有编译期以外的保护 —— 删掉其中一条，Hilt 会在编译期报 missing
 * binding（两个端口都有**可达**的注入点：`OnboardingViewModel` 与
 * `FullscreenBarcodeActivity`），所以它们是安全的。
 * T-150 那边不可达才需要手工验。
 */
@Module
@InstallIn(SingletonComponent::class)
internal abstract class AppSettingsModule {
    /** `core:model` 的端口（T-151）。 */
    @Binds
    @Singleton
    abstract fun bindAppLanguageStore(impl: AppCompatLanguageStore): AppLanguageStore

    /**
     * `core:common` 的端口（T-154）。今天的实现恒为「允许」——
     * 真正的开关归设置页那一卡，见 [AllowScreenshotsPolicy] 的类注释。
     */
    @Binds
    @Singleton
    abstract fun bindScreenshotPolicy(impl: AllowScreenshotsPolicy): ScreenshotPolicy
}
