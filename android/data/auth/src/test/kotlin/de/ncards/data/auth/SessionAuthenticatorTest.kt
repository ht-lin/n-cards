package de.ncards.data.auth

import mockwebserver3.MockResponse
import mockwebserver3.MockWebServer
import okhttp3.Protocol
import okhttp3.Request
import okhttp3.Response
import okhttp3.ResponseBody.Companion.toResponseBody
import org.junit.jupiter.api.AfterEach
import org.junit.jupiter.api.Assertions.assertEquals
import org.junit.jupiter.api.Assertions.assertNotNull
import org.junit.jupiter.api.Assertions.assertNull
import org.junit.jupiter.api.BeforeEach
import org.junit.jupiter.api.DisplayName
import org.junit.jupiter.api.Test

/**
 * 401 的处置：什么时候刷新、什么时候清会话、什么时候放手。
 *
 * 这里直接调 `authenticate()` 而不是发真请求 —— 每条分支的输入（一个 401 响应）
 * 都能精确构造，而「真的发出去」那一面由
 * [SessionAuthenticatorConcurrencyTest] 覆盖。
 */
@DisplayName("401 静默刷新")
class SessionAuthenticatorTest {
    private lateinit var server: MockWebServer
    private lateinit var harness: AuthTestHarness

    @BeforeEach
    fun setUp() {
        server = MockWebServer()
        server.start()
        harness = AuthTestHarness(server)
        harness.sessions.save(AuthFixtures.session(accessToken = "a1", refreshToken = "r1"))
    }

    @AfterEach
    fun tearDown() {
        server.close()
    }

    @Test
    @DisplayName("token_expired：刷新成功后用新令牌重发，旧 header 被覆盖而不是追加")
    fun refreshesOnTokenExpired() {
        enqueueRefresh(accessToken = "a2", refreshToken = "r2")

        val retry = harness.authenticator.authenticate(null, unauthorized("token_expired"))

        assertNotNull(retry)
        assertEquals(listOf("Bearer a2"), retry!!.headers.values("Authorization"))
        assertEquals("a2", harness.sessions.accessToken())
        assertEquals("r2", harness.sessions.refreshToken())
        assertEquals(SessionState.SignedIn, harness.sessions.state.value)
    }

    /**
     * §6.1：`token_invalid` 的应对是「清空本地会话，跳登录，**不要重试**」。
     *
     * ⚠️ 这里连刷新都不试。拿一枚属于已撤销会话的 refresh token 去换，
     * 服务端只会再回一个 `token_invalid`，白白消耗一次 60/h 的配额。
     * 断言 `server.requestCount == 0` 守的就是这一点。
     *
     * 这也是 T-150 验收标准的第二条。
     */
    @Test
    @DisplayName("token_invalid：清空本地会话，且根本不去刷新")
    fun clearsSessionOnTokenInvalid() {
        val retry = harness.authenticator.authenticate(null, unauthorized("token_invalid"))

        assertNull(retry)
        assertNull(harness.sessions.refreshToken())
        assertEquals(SessionState.SignedOut(SignedOutReason.SessionRevoked), harness.sessions.state.value)
        assertEquals(0, server.requestCount, "token_invalid 不该触发刷新")
        assertEquals(0, harness.secrets.clearCount, "清会话必须逐条 remove，见 SessionStoreTest")
    }

    /**
     * §6.1 逐字：「静默刷新后重试**一次**」。
     *
     * 没有这一条，一个持续返回 401 的服务端会让客户端一路刷新到 OkHttp 的
     * `MAX_FOLLOW_UPS`（20）—— 而每一次都是一轮令牌轮换，第二轮就会踩重放检测。
     */
    @Test
    @DisplayName("只重试一次：已经重发过的请求再 401 就放手")
    fun retriesOnlyOnce() {
        val first = unauthorized("token_expired")
        val second = unauthorized("token_expired", prior = first)

        assertNull(harness.authenticator.authenticate(null, second))
        assertEquals(0, server.requestCount)
    }

    @Test
    @DisplayName("刷新端点自己的 401 不会递归触发刷新")
    fun ignoresRefreshEndpointItself() {
        val response = unauthorized("token_invalid", path = "auth/token/refresh")

        assertNull(harness.authenticator.authenticate(null, response))
        // 递归发生的话，会话早就被清了。
        assertEquals(SessionState.SignedIn, harness.sessions.state.value)
    }

    @Test
    @DisplayName("请求本来就没带 Bearer 时不接管 —— 刷新解决不了「还没登录」")
    fun ignoresRequestsWithoutBearer() {
        val response = unauthorized("token_expired", bearer = null)

        assertNull(harness.authenticator.authenticate(null, response))
        assertEquals(0, server.requestCount)
    }

    /**
     * ⚠️ 这四条合起来是本文件的重点：**只有 `token_invalid` 清会话**。
     *
     * 把 503 或网络错误压成「登出」，后果是一次维护窗口把全体用户踢回登录页，
     * 而登录本身在那个窗口里也是坏的。后端 `AuthenticationListener` 的类注释
     * 对同一件事的措辞是「把『Vault 挂了』压成 401 会让全体客户端在一次故障里
     * 清空会话、退回登录页」。
     */
    @Test
    @DisplayName("刷新遇到 429 / 503 / 426 / 网络故障时保留会话，只是这次不重发")
    fun keepsSessionOnTransientRefreshFailures() {
        listOf(
            429 to "rate_limited",
            503 to "service_unavailable",
            426 to "client_too_old",
            500 to "internal_error",
        ).forEach { (status, code) ->
            harness.sessions.save(AuthFixtures.session(accessToken = "a1", refreshToken = "r1"))
            server.enqueue(
                MockResponse
                    .Builder()
                    .code(status)
                    .setHeader("Content-Type", "application/problem+json")
                    .body(AuthFixtures.problemJson(status, code))
                    .build(),
            )

            val retry = harness.authenticator.authenticate(null, unauthorized("token_expired"))

            assertNull(retry, "$code 不该重发")
            assertEquals(SessionState.SignedIn, harness.sessions.state.value, "$code 不该把用户登出")
            assertEquals("r1", harness.sessions.refreshToken(), "$code 不该动令牌")
        }
    }

    @Test
    @DisplayName("401 但 code 不是那两个（§13.6 新增码）时保守放手：不刷新也不登出")
    fun ignoresUnknown401Code() {
        val response = unauthorized("some_future_code")

        assertNull(harness.authenticator.authenticate(null, response))
        assertEquals(SessionState.SignedIn, harness.sessions.state.value)
        assertEquals(0, server.requestCount)
    }

    private fun enqueueRefresh(
        accessToken: String,
        refreshToken: String,
    ) {
        server.enqueue(
            MockResponse
                .Builder()
                .code(200)
                .setHeader("Content-Type", "application/json")
                .body(AuthFixtures.sessionJson(accessToken, refreshToken))
                .build(),
        )
    }

    /** 手工造一个 401 响应，形状与服务端发出来的一致（§6.1 problem+json）。 */
    private fun unauthorized(
        code: String,
        path: String = "cards",
        bearer: String? = "Bearer a1",
        prior: Response? = null,
    ): Response {
        val request =
            Request
                .Builder()
                .url(server.url("/v1/$path"))
                .apply { bearer?.let { header("Authorization", it) } }
                .build()

        return Response
            .Builder()
            .request(request)
            .protocol(Protocol.HTTP_1_1)
            .code(401)
            .message("Unauthorized")
            .header("Content-Type", "application/problem+json")
            .body(AuthFixtures.problemJson(401, code).toResponseBody())
            .apply { prior?.let { priorResponse(it) } }
            .build()
    }
}
