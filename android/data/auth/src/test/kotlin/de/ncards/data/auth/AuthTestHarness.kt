package de.ncards.data.auth

import de.ncards.core.network.api.AuthApi
import de.ncards.core.network.api.infrastructure.Serializer
import de.ncards.core.network.impl.NetworkConfig
import kotlinx.serialization.json.Json
import mockwebserver3.MockWebServer
import okhttp3.Dispatcher
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import retrofit2.Retrofit
import retrofit2.converter.kotlinx.serialization.asConverterFactory

/**
 * 认证用例共用的接线：一台 [MockWebServer] + 两个客户端 + 一条 [AuthApi]。
 *
 * ⚠️ 这里**重建**了一遍 `NetworkModule` 的接线而不是调用它 ——
 * 那个 module 是 `core:network:impl` 的 `internal object`，模块外够不着。
 * 生产接线本身由 `core:network:impl` 的 `NetworkAuthSlotsTest` 守着；
 * 这里要的只是一个形状相同的环境。
 *
 * **两个客户端各有自己的 `Dispatcher`，这不是摆设** ——
 * 见 [refreshClient] 的注释。
 */
internal class AuthTestHarness(
    val server: MockWebServer,
    val secrets: FakeSecretStore = FakeSecretStore(),
) {
    val config =
        NetworkConfig(
            baseUrl = server.url("/v1/"),
            clientHeader = "android/1.0.0 (1)",
        )

    val sessions = SessionStore(secrets)
    val mapper = FakeApiErrorMapper()
    val publicEndpoints = PublicEndpoints(config)

    /**
     * 刷新走的那一条：没有 Bearer、没有 `Authenticator`，**自己的 `Dispatcher`**。
     *
     * 最后那一条是硬要求：`Dispatcher` 的默认 `maxRequestsPerHost` 是 5，
     * 而 `Authenticator` 是在这条 call 仍占着槽位时被调用的。共用一个 dispatcher
     * 时，5 个并发 401 会把刷新请求永远挡在 `readyAsyncCalls` 里。
     */
    val refreshClient: OkHttpClient = OkHttpClient.Builder().build()

    val refreshApi: AuthApi = retrofit(refreshClient).create(AuthApi::class.java)

    val gate = RefreshGate(refreshApi, sessions, mapper, config)

    val authenticator = SessionAuthenticator(sessions, gate, mapper, publicEndpoints)

    /** 产品请求走的那一条，形状与 `NetworkModule.provideOkHttpClient` 一致。 */
    val client: OkHttpClient =
        refreshClient
            .newBuilder()
            .dispatcher(Dispatcher())
            .authenticator(authenticator)
            .addInterceptor(BearerAuthInterceptor(sessions, publicEndpoints))
            .build()

    /**
     * 与 `NetworkModule.provideJson()` 的三条配置一致。`ignoreUnknownKeys` 那一条
     * 是契约的一部分（§3.10 / §13.6），不是测试的便利。
     */
    fun retrofit(client: OkHttpClient): Retrofit =
        Retrofit
            .Builder()
            .baseUrl(config.baseUrl)
            .client(client)
            .addConverterFactory(JSON.asConverterFactory("application/json".toMediaType()))
            .build()

    private companion object {
        val JSON =
            Json {
                ignoreUnknownKeys = true
                explicitNulls = false
                serializersModule = Serializer.kotlinxSerializationAdapters
            }
    }
}
