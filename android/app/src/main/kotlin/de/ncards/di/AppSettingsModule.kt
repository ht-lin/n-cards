package de.ncards.di

import dagger.Binds
import dagger.Module
import dagger.hilt.InstallIn
import dagger.hilt.components.SingletonComponent
import de.ncards.core.model.settings.AppLanguageStore
import de.ncards.settings.AppCompatLanguageStore
import javax.inject.Singleton

/**
 * `core:model` 声明的 [AppLanguageStore] 端口，实现从 `:app` 绑进来（T-151）。
 *
 * ⚠️ 与 `AuthModule` 里那三条 `@Binds` 同一个形状，也同一个风险：
 * 这条绑定没有编译期以外的保护 —— 删掉它，Hilt 会在编译期报 missing binding
 * （`OnboardingViewModel` 注入了它，是**可达**的），所以这次是安全的。
 * T-150 那边不可达才需要手工验。
 */
@Module
@InstallIn(SingletonComponent::class)
internal abstract class AppSettingsModule {
    @Binds
    @Singleton
    abstract fun bindAppLanguageStore(impl: AppCompatLanguageStore): AppLanguageStore
}
