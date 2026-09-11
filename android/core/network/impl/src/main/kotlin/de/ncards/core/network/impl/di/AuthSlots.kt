package de.ncards.core.network.impl.di

import dagger.BindsOptionalOf
import dagger.Module
import dagger.hilt.InstallIn
import dagger.hilt.components.SingletonComponent
import dagger.multibindings.Multibinds
import okhttp3.Authenticator
import okhttp3.Interceptor
import javax.inject.Qualifier

/**
 * **不带**认证的那一个 `OkHttpClient` / `Retrofit` / `AuthApi`。
 *
 * 它有两个用途，两个都是结构性的、不是省事：
 *
 * 1. **刷新令牌只能走它。** `POST /v1/auth/token/refresh` 在契约里是 `security: []`，
 *    带 Bearer 没有意义；更要紧的是它**不能**装 [Authenticator] ——
 *    否则刷新自己拿到 401 时会递归调用刷新。
 * 2. **它是带认证那一个的基底**（`newBuilder()`），于是拦截器顺序、三个超时、
 *    日志开关只有一处真相源。
 *
 * ⚠️ 它与带认证的客户端**共享连接池、但不共享 `Dispatcher`** ——
 * 理由见 [NetworkModule.provideOkHttpClient] 的 KDoc「为什么要换一个 Dispatcher」。
 */
@Qualifier
@Retention(AnnotationRetention.BINARY)
annotation class Unauthenticated

/**
 * `Authorization: Bearer` 拦截器的插槽（T-150 的 `BearerAuthInterceptor` 绑进来）。
 *
 * 做成 `Set` 而不是 `Optional`：这一组是「认证相关、要装在基底拦截器链最内层的」
 * 拦截器，将来完全可能多出第二个（比如设备指纹）。而 [Authenticator] 在 OkHttp 里
 * 本来就**只能有一个**，所以那一侧用 `Optional` 才是诚实的类型。
 */
@Qualifier
@Retention(AnnotationRetention.BINARY)
annotation class AuthInterceptors

/**
 * T-010 在 `build.gradle.kts` 的文件头承诺过的那两个「可选注入点」（T-150 填上）。
 * 这里**只有声明，没有任何实现** —— 那是 `data:auth` 的事。
 *
 * ============================================================================
 * 为什么插槽在这里，实现在 `data:auth`
 * ============================================================================
 * §12.3 的规则三：`core:*` **不得**依赖 `data:*`，而 `NcardsModuleGraph` 在
 * **配置期**强制它（`./gradlew help` 就红，不是等 lint）。于是 `OkHttpClient`
 * 的组装方与认证的实现方天然分居两个模块，中间只能是一个反转的端口：
 * 类型在这里声明，实现由 `data:auth` 用 `@Binds` 绑进来。
 *
 * 后端的同一形状是 ADR-0018 的 `Shared\Application\Onboarding\OnboardingStatusInterface`
 * ——「Shared 的第一个反转端口」。两边是同一条依赖倒置，不是巧合。
 *
 * ============================================================================
 * ⚠️ 为什么是**可选**，不是必填
 * ============================================================================
 * `:core:network:impl` 的单测（`InterceptorTest` / `NetworkAuthSlotsTest`）跑在一个
 * 没有 `data:auth` 的构型里，`:app` 之外的模块也都可能单独消费网络层。
 * 写成必填绑定，那些构型会在编译期报 missing binding，而它们本来与认证无关。
 *
 * 所以：没有 `data:auth` 时 `Optional` 为空、`Set` 为空，
 * [NetworkModule.provideOkHttpClient] 照常建出一个不带认证的客户端。
 *
 * ⚠️ 这个模块与两个 qualifier 都是 `public`，而 [NetworkModule] 是 `internal object`。
 * 差别是有意的：那个是本模块的内部装配，这三个类型是本模块**对外的契约**。
 */
@Module
@InstallIn(SingletonComponent::class)
abstract class NetworkAuthSlotsModule {
    /**
     * 401 静默刷新。由 `data:auth` 的 `SessionAuthenticator` 填。
     *
     * ⚠️ `Authenticator` **不是**拦截器：它在 `RetryAndFollowUpInterceptor` 里被调用，
     * 也就是**全部应用拦截器之内**。后果是它重发的那个请求**不会**再跑一遍
     * `ClientHeader` / `RequestId` / `Retry` / Bearer 四个拦截器 ——
     * 新的 `Authorization` 必须由它自己写在返回的 `Request` 上。
     */
    @BindsOptionalOf
    abstract fun optionalAuthenticator(): Authenticator

    /**
     * 见 [AuthInterceptors]。没有 `data:auth` 时是空集合。
     *
     * `@Multibinds` 那一行不能省：没有它，`data:auth` 不在图里时
     * `@AuthInterceptors Set<Interceptor>` 是一个 missing binding 而不是空集合。
     */
    @Multibinds
    @AuthInterceptors
    abstract fun authInterceptors(): Set<Interceptor>
}
