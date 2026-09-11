package de.ncards.data.auth

import de.ncards.core.network.impl.NetworkConfig
import mockwebserver3.MockResponse
import mockwebserver3.MockWebServer
import mockwebserver3.RecordedRequest
import okhttp3.OkHttpClient
import okhttp3.Request
import org.junit.jupiter.api.AfterEach
import org.junit.jupiter.api.Assertions.assertEquals
import org.junit.jupiter.api.Assertions.assertNull
import org.junit.jupiter.api.BeforeEach
import org.junit.jupiter.api.DisplayName
import org.junit.jupiter.api.Test

/**
 * `Authorization: Bearer` 拦截器。
 *
 * 用真的 [MockWebServer] 而不是伪造 `Interceptor.Chain`：这个拦截器的价值全在
 * 「发出去的报文里到底有什么」（与 `core:network:impl` 的 `InterceptorTest`
 * 同一条理由）。
 */
@DisplayName("Bearer 拦截器")
class BearerAuthInterceptorTest {
    private lateinit var server: MockWebServer
    private lateinit var client: OkHttpClient
    private lateinit var sessions: SessionStore

    @BeforeEach
    fun startServer() {
        server = MockWebServer()
        server.start()

        val config =
            NetworkConfig(
                baseUrl = server.url("/v1/"),
                clientHeader = "android/1.0.0 (1)",
            )
        sessions = SessionStore(FakeSecretStore())
        client =
            OkHttpClient
                .Builder()
                .addInterceptor(BearerAuthInterceptor(sessions, PublicEndpoints(config)))
                .build()
    }

    @AfterEach
    fun stopServer() {
        server.close()
    }

    @Test
    @DisplayName("有会话时每个受保护请求都带 Bearer")
    fun addsBearer() {
        sessions.save(AuthFixtures.session(accessToken = "a1"))

        assertEquals("Bearer a1", exchange("cards").headers["Authorization"])
    }

    @Test
    @DisplayName("没有会话时原样放行，不自己编一个错误")
    fun passesThroughWithoutSession() {
        assertNull(exchange("cards").headers["Authorization"])
    }

    @Test
    @DisplayName("五个免鉴权端点上不挂 Bearer")
    fun skipsPublicEndpoints() {
        sessions.save(AuthFixtures.session(accessToken = "a1"))

        val public = listOf(
            "auth/otp/request",
            "auth/otp/verify",
            "auth/magic/consume",
            "auth/token/refresh",
            "config",
        )

        public.forEach { path ->
            assertNull(exchange(path).headers["Authorization"], "$path 不该带 Bearer")
        }
    }

    /**
     * ⚠️⚠️ 这一条守的是 `auth/` 前缀陷阱。
     *
     * 免鉴权的五个端点里有三个在 `auth/` 下，于是「`auth/` 前缀一律不挂 Bearer」
     * 看起来完全等价 —— 但 `POST /v1/auth/logout` 也在那个前缀下，而它是 auth 组里
     * **唯一需要** Bearer 的端点。
     *
     * 写成前缀匹配的后果：logout 永远发不出去，而**没有任何迹象** ——
     * 服务端 401，本地会话照样被 `AuthRepository.logout()` 清掉，用户看到
     * 「登出成功」，实际上那条会话在服务端一直活到 90 天后过期。
     *
     * 后端 `AuthenticationListener` 的类注释对同一个坑的措辞是
     * 「任何人都能撤销任何会话，而所有测试照常绿」。两边都选了逐条列出路径。
     */
    @Test
    @DisplayName("auth/logout 必须带 Bearer —— 它在 auth/ 前缀下，但不是免鉴权端点")
    fun logoutKeepsBearer() {
        sessions.save(AuthFixtures.session(accessToken = "a1"))

        assertEquals("Bearer a1", exchange("auth/logout").headers["Authorization"])
    }

    @Test
    @DisplayName("路径是整条比的 —— 不会被 /v1/admin/config 这类子串蒙混过去")
    fun matchesWholePath() {
        sessions.save(AuthFixtures.session(accessToken = "a1"))

        assertEquals("Bearer a1", exchange("admin/config").headers["Authorization"])
    }

    @Test
    @DisplayName("调用方已经设了 Authorization 就不动它")
    fun keepsCallerSuppliedHeader() {
        sessions.save(AuthFixtures.session(accessToken = "a1"))

        val recorded =
            exchange("cards") { builder ->
                builder.header("Authorization", "Bearer explicit")
            }

        assertEquals("Bearer explicit", recorded.headers["Authorization"])
    }

    private fun exchange(
        path: String,
        customise: (Request.Builder) -> Unit = {},
    ): RecordedRequest {
        server.enqueue(
            MockResponse
                .Builder()
                .code(200)
                .body("{}")
                .build(),
        )

        val builder = Request.Builder().url(server.url("/v1/$path"))
        customise(builder)
        client.newCall(builder.build()).execute().close()

        return server.takeRequest()
    }
}
