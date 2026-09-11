package de.ncards.data.auth.di

import dagger.Binds
import dagger.Module
import dagger.hilt.InstallIn
import dagger.hilt.components.SingletonComponent
import dagger.multibindings.IntoSet
import de.ncards.core.network.impl.di.AuthInterceptors
import de.ncards.data.auth.AndroidDeviceDescriptorProvider
import de.ncards.data.auth.AuthRepository
import de.ncards.data.auth.BearerAuthInterceptor
import de.ncards.data.auth.DefaultAuthRepository
import de.ncards.data.auth.DeviceDescriptorProvider
import de.ncards.data.auth.SessionAuthenticator
import okhttp3.Authenticator
import okhttp3.Interceptor
import javax.inject.Singleton

/**
 * `data:auth` 的绑定。三条，全部 `@Binds`（与 `CryptoModule` /
 * `NetworkBindingsModule` 同一个做法：类型映射交给构造函数签名，不手写 provider）。
 *
 * 下面两条是把实现**填进** `core:network:impl` 的
 * `NetworkAuthSlotsModule` 声明的那两个插槽里 —— 那是 §12.3 规则三
 * （`core:*` 不得依赖 `data:*`）下唯一可能的方向。
 *
 * ⚠️ `:app` 不需要为此改任何东西：`app/build.gradle.kts` 早就有
 * `implementation(project(":data:auth"))`，Hilt 在 DI 根上看得见本模块。
 * 反过来说，一旦哪天有人把那一行删了，认证会**静默**消失 ——
 * 插槽是可选的，图照样建得起来，只是从此每个请求都不带 Bearer。
 *
 * ⚠️⚠️ 而且今天**没有任何编译期检查**拦得住那件事：`:app` 里还没有人注入
 * `AuthRepository` 或 `OkHttpClient`（第一个真实消费者是 T-151 的 NavHost），
 * 而 Dagger 默认只校验**可达**的绑定。T-150 落地时是这样验的：临时往 `:app`
 * 加一个 `@EntryPoint` 暴露 `OkHttpClient` + `AuthRepository`，跑
 * `:app:assembleDebug`，确认生成的 `DaggerNcardsApplication_HiltComponents_SingletonC`
 * 里 `provideOkHttpClient(…, Optional.of(authenticator), …)` 成立且无环，
 * 然后把那个 EntryPoint 删掉。T-151 接上 NavHost 之后这条路径就自动常驻了。
 */
@Module
@InstallIn(SingletonComponent::class)
internal abstract class AuthModule {
    @Binds
    @Singleton
    abstract fun bindAuthRepository(impl: DefaultAuthRepository): AuthRepository

    @Binds
    @Singleton
    abstract fun bindDeviceDescriptorProvider(impl: AndroidDeviceDescriptorProvider): DeviceDescriptorProvider

    /** OkHttp 的 `Authenticator` 只能有一个，所以插槽那侧是 `Optional` 而不是 `Set`。 */
    @Binds
    @Singleton
    abstract fun bindAuthenticator(impl: SessionAuthenticator): Authenticator

    @Binds
    @IntoSet
    @AuthInterceptors
    abstract fun bindBearerAuthInterceptor(impl: BearerAuthInterceptor): Interceptor
}
