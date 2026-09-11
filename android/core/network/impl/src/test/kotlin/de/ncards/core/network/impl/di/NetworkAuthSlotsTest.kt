package de.ncards.core.network.impl.di

import de.ncards.core.network.impl.NetworkConfig
import de.ncards.core.network.impl.interceptor.ClientHeaderInterceptor
import de.ncards.core.network.impl.interceptor.RequestIdInterceptor
import de.ncards.core.network.impl.interceptor.RetryInterceptor
import okhttp3.Authenticator
import okhttp3.HttpUrl.Companion.toHttpUrl
import okhttp3.Interceptor
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.Response
import okhttp3.Route
import org.junit.jupiter.api.Assertions.assertEquals
import org.junit.jupiter.api.Assertions.assertNotSame
import org.junit.jupiter.api.Assertions.assertSame
import org.junit.jupiter.api.DisplayName
import org.junit.jupiter.api.Test
import java.util.Optional

/**
 * T-150 给 `NetworkModule` 加的两个认证插槽。
 *
 * 这里**不**测认证行为本身（那在 `:data:auth`），只测装配：插槽空着时图不塌、
 * 填上时东西真的装进去了，以及那条最容易被「顺手优化」掉的 `Dispatcher` 隔离。
 */
@DisplayName("网络层的认证插槽")
class NetworkAuthSlotsTest {
    @Test
    @DisplayName("没有 data:auth 时照样建得出客户端 —— 插槽是可选的")
    fun buildsWithoutAuth() {
        val client = authenticatedClient(authenticator = Optional.empty(), interceptors = emptySet())

        // OkHttp 的「没有 authenticator」就是这个哨兵值，不是 null。
        assertSame(Authenticator.NONE, client.authenticator)
        // 只有基底那三个（日志没装，enableHttpLogging 默认 false）。
        assertEquals(BASE_INTERCEPTOR_COUNT, client.interceptors.size)
    }

    @Test
    @DisplayName("插槽填上时 Authenticator 与拦截器真的装进去了")
    fun installsAuthSlots() {
        val authenticator = NoopAuthenticator()
        val marker = Interceptor { chain -> chain.proceed(chain.request()) }

        val client = authenticatedClient(Optional.of<Authenticator>(authenticator), setOf(marker))

        assertSame(authenticator, client.authenticator)
        // 认证拦截器在**最内层** —— 每一次真实往返都重新读一次令牌存储。
        assertSame(marker, client.interceptors.last())
        assertEquals(BASE_INTERCEPTOR_COUNT + 1, client.interceptors.size)
    }

    /**
     * ⚠️⚠️ 这条守的是一个**死锁**，不是整洁。
     *
     * `Dispatcher` 的默认 `maxRequestsPerHost` 是 5，而 `Authenticator` 是在
     * 这条 call 仍占着 dispatcher 槽位时被调用的。两个客户端共用一个 `Dispatcher`
     * 时，同一个 host 上并发 5 个 401 会让刷新请求永远排不进去 ——
     * 现象是集体卡到读超时，不是一个看得出原因的错误。
     *
     * `OkHttpClient.newBuilder()` 默认**共享** dispatcher 与连接池。
     * 连接池共享是想要的，dispatcher 不是 —— 所以 `provideOkHttpClient` 里
     * 那一行 `.dispatcher(Dispatcher())` 删不得。删了这条用例会红，
     * 而 `:data:auth` 的 `SessionAuthenticatorConcurrencyTest` 会挂到超时。
     */
    @Test
    @DisplayName("带认证的客户端与 @Unauthenticated 的那个不共享 Dispatcher（否则并发 401 会死锁）")
    fun doesNotShareDispatcher() {
        val base = unauthenticatedClient()
        val client = NetworkModule.provideOkHttpClient(base, Optional.empty(), emptySet())

        assertNotSame(base.dispatcher, client.dispatcher)
        // 连接池反过来**应当**共享：分开只会多一份空闲连接，没有任何好处。
        assertSame(base.connectionPool, client.connectionPool)
    }

    private fun authenticatedClient(
        authenticator: Optional<Authenticator>,
        interceptors: Set<Interceptor>,
    ): OkHttpClient = NetworkModule.provideOkHttpClient(unauthenticatedClient(), authenticator, interceptors)

    private fun unauthenticatedClient(): OkHttpClient =
        NetworkModule.provideUnauthenticatedOkHttpClient(
            config = CONFIG,
            clientHeader = ClientHeaderInterceptor(CONFIG),
            requestId = RequestIdInterceptor(),
            // 退避不会真的发生（这些用例一个请求都不发），所以 Sleeper 是个空实现。
            retry = RetryInterceptor { },
        )

    private class NoopAuthenticator : Authenticator {
        override fun authenticate(
            route: Route?,
            response: Response,
        ): Request? = null
    }

    private companion object {
        val CONFIG =
            NetworkConfig(
                baseUrl = "https://example.invalid/v1/".toHttpUrl(),
                clientHeader = "android/1.0.0 (1)",
            )

        /** `ClientHeader → RequestId → Retry`。日志那一个只在 debug 构型里装。 */
        const val BASE_INTERCEPTOR_COUNT = 3
    }
}
