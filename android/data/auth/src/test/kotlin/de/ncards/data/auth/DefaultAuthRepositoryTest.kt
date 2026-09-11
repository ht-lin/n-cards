package de.ncards.data.auth

import de.ncards.core.network.api.AuthApi
import de.ncards.core.network.api.MeApi
import de.ncards.core.network.api.model.OtpRequest
import de.ncards.core.network.impl.ApiResult
import de.ncards.core.network.impl.error.ApiError
import kotlinx.coroutines.test.runTest
import mockwebserver3.MockResponse
import mockwebserver3.MockWebServer
import org.junit.jupiter.api.AfterEach
import org.junit.jupiter.api.Assertions.assertEquals
import org.junit.jupiter.api.Assertions.assertInstanceOf
import org.junit.jupiter.api.Assertions.assertNotEquals
import org.junit.jupiter.api.Assertions.assertNotNull
import org.junit.jupiter.api.Assertions.assertNull
import org.junit.jupiter.api.Assertions.assertTrue
import org.junit.jupiter.api.BeforeEach
import org.junit.jupiter.api.DisplayName
import org.junit.jupiter.api.Test
import java.util.UUID

@DisplayName("AuthRepository")
class DefaultAuthRepositoryTest {
    private lateinit var server: MockWebServer
    private lateinit var harness: AuthTestHarness
    private lateinit var devices: FakeDeviceDescriptorProvider
    private lateinit var repository: DefaultAuthRepository

    @BeforeEach
    fun setUp() {
        server = MockWebServer()
        server.start()
        harness = AuthTestHarness(server)
        devices = FakeDeviceDescriptorProvider()

        val retrofit = harness.retrofit(harness.client)
        repository =
            DefaultAuthRepository(
                authApi = retrofit.create(AuthApi::class.java),
                meApi = retrofit.create(MeApi::class.java),
                sessions = harness.sessions,
                devices = devices,
                mapper = harness.mapper,
                config = harness.config,
            )
    }

    @AfterEach
    fun tearDown() {
        server.close()
    }

    /**
     * ⚠️ 没有这一步，`sessionState` 会一直停在 [SessionState.Unknown] ——
     * 令牌是懒加载的，在第一个网络请求发生之前没人会去读存储，
     * 而 NavHost 会一直显示 splash。`:app` 启动时必须调它一次。
     */
    @Test
    @DisplayName("restoreSession 把状态从 Unknown 推到终态")
    fun restoreSessionResolvesState() =
        runTest {
            assertEquals(SessionState.Unknown, repository.sessionState.value)

            repository.restoreSession()

            assertEquals(SessionState.SignedOut(SignedOutReason.NeverSignedIn), repository.sessionState.value)
        }

    @Test
    @DisplayName("restoreSession 读出已存在的会话")
    fun restoreSessionFindsStoredSession() =
        runTest {
            SessionStore(harness.secrets).save(AuthFixtures.session())

            val fresh = AuthTestHarness(server, harness.secrets)
            assertEquals(SessionState.Unknown, fresh.sessions.state.value)

            fresh.sessions.refreshToken()

            assertEquals(SessionState.SignedIn, fresh.sessions.state.value)
        }

    /**
     * ⚠️ §7.3 的不变量：令牌**在类型层面**就到不了 UI。
     *
     * `verifyOtp` 返回 `User` 而不是 `Session`，所以 T-151 的 ViewModel
     * 拿不到令牌，也就不可能把它写进 `SavedStateHandle` 或者打进日志。
     */
    @Test
    @DisplayName("verifyOtp 成功：令牌落进存储，交出去的只有 User")
    fun verifyOtpPersistsTokensAndReturnsUser() =
        runTest {
            enqueueSession()

            val result = repository.verifyOtp(UUID.randomUUID(), "123456")

            val user = result.successValue()
            assertEquals(AuthFixtures.USER_ID, user.id)
            assertEquals("a1", harness.sessions.accessToken())
            assertEquals("r1", harness.sessions.refreshToken())
            assertEquals(SessionState.SignedIn, repository.sessionState.value)
        }

    /**
     * ⚠️ 设备 id 必须活过一次登出。每次换一个新 id，等于每次重新登录都给用户
     * 所有其他设备发一条「有新设备登录」推送 + 一封提醒信（§7.1）——
     * 而 §7.2 的 T02 把那封信列为「邮箱被接管」唯一能被用户察觉的信号。
     */
    @Test
    @DisplayName("请求体带 device，且 device id 跨一次登出保持不变")
    fun sendsStableDeviceId() =
        runTest {
            enqueueSession()
            repository.verifyOtp(UUID.randomUUID(), "123456")
            val first = server
                .takeRequest()
                .body
                ?.utf8()
                .orEmpty()
                .deviceId()

            harness.sessions.clear(SignedOutReason.UserAction)

            enqueueSession()
            repository.verifyOtp(UUID.randomUUID(), "123456")
            val second = server
                .takeRequest()
                .body
                ?.utf8()
                .orEmpty()
                .deviceId()

            assertEquals(first, second)
            assertTrue(first.isNotBlank(), "请求体里没有 device.id")
        }

    @Test
    @DisplayName("consumeMagicLink 与 verifyOtp 同形：一样落令牌、一样只交出 User")
    fun consumeMagicLinkPersistsTokens() =
        runTest {
            enqueueSession()

            val result = repository.consumeMagicLink("magic-token")

            assertInstanceOf(ApiResult.Success::class.java, result)
            assertEquals("r1", harness.sessions.refreshToken())
        }

    @Test
    @DisplayName("失败时不碰存储 —— 一次输错验证码不该把已有会话弄丢")
    fun failedVerifyLeavesStoreAlone() =
        runTest {
            harness.sessions.save(AuthFixtures.session(accessToken = "old", refreshToken = "oldr"))
            enqueueProblem(422, "validation_failed")

            val result = repository.verifyOtp(UUID.randomUUID(), "000000")

            assertInstanceOf(ApiResult.Failure::class.java, result)
            assertEquals("oldr", harness.sessions.refreshToken())
        }

    @Test
    @DisplayName("requestOtp 直接把 202 的 challenge 交出去")
    fun requestOtpReturnsChallenge() =
        runTest {
            server.enqueue(
                json(
                    202,
                    """
                    {
                      "challenge_id": "0192f3a1-b2c3-7d4e-8f01-23456789abcd",
                      "expires_at": "2026-08-29T10:40:12Z",
                      "resend_after_seconds": 60
                    }
                    """.trimIndent(),
                ),
            )

            val result = repository.requestOtp("anna@example.de", OtpRequest.Locale.de)

            assertEquals(60, result.successValue().resendAfterSeconds)
        }

    @Test
    @DisplayName("fetchMe 拆掉 envelope，只交出 User")
    fun fetchMeUnwrapsEnvelope() =
        runTest {
            harness.sessions.save(AuthFixtures.session())
            server.enqueue(json(200, AuthFixtures.userEnvelopeJson()))

            val result = repository.fetchMe()

            assertEquals(AuthFixtures.USER_ID, result.successValue().id)
        }

    /**
     * ⚠️ 服务端不可达不该让用户登不出。
     *
     * 用户在地铁里点登出，他想做的事（把这台设备上的卡藏起来）完全是本地的；
     * 给他一句「失败，请重试」是把一个网络问题变成一个产品问题。
     * 服务端那条会话最坏活到 90 天后过期，而他可以从别的设备撤销它。
     */
    @Test
    @DisplayName("logout 在服务端 500 时仍然清空本机会话")
    fun logoutClearsLocallyEvenWhenServerFails() =
        runTest {
            harness.sessions.save(AuthFixtures.session())
            enqueueProblem(500, "internal_error")

            val result = repository.logout()

            val error = assertInstanceOf(ApiResult.Failure::class.java, result).error
            assertTrue(error is ApiError.InternalError)
            assertNull(harness.sessions.refreshToken())
            assertEquals(SessionState.SignedOut(SignedOutReason.UserAction), repository.sessionState.value)
            assertEquals(0, harness.secrets.clearCount, "清会话必须逐条 remove，见 SessionStoreTest")
        }

    @Test
    @DisplayName("setUsername 拆掉 envelope，只交出 User")
    fun setUsernameUnwrapsEnvelope() =
        runTest {
            harness.sessions.save(AuthFixtures.session())
            server.enqueue(json(200, AuthFixtures.userEnvelopeJson()))

            val result = repository.setUsername("anna_b")

            assertEquals(AuthFixtures.USER_ID, result.successValue().id)
        }

    /**
     * §3.8：「客户端与服务端**均**对输入 `trim` + `toLowerCase`」。
     *
     * 客户端这一遍不是为了省掉服务端那一遍，是为了让用户在输入框里看到的
     * 与真正提交的是同一个字符串 —— 这个操作不可逆（§16 R13）。
     */
    @Test
    @DisplayName("发出去的 username 已经归一化")
    fun setUsernameNormalizesBeforeSending() =
        runTest {
            harness.sessions.save(AuthFixtures.session())
            server.enqueue(json(200, AuthFixtures.userEnvelopeJson()))

            repository.setUsername("  Anna_B  ")

            val body = server
                .takeRequest()
                .body
                ?.utf8()
                .orEmpty()
            assertTrue(body.contains("\"username\":\"anna_b\""), "请求体没有归一化：$body")
        }

    /**
     * ⚠️ 这条守的是**用户的账号**，不是一个便利功能。
     *
     * §7.5 给这个端点的配额是按 user 10 次总计的**生命周期**计数，用尽即
     * 永远完成不了 onboarding（ADR-0017）。「服务端记上了、响应丢了、用户再按一次」
     * 这条路径不带 key 就白烧一次；导出式的 key 让它命中回放。
     *
     * 随机 key 在这里等于没带 —— 所以要断言的是「同名同 key」。
     */
    @Test
    @DisplayName("Idempotency-Key 由 username 导出：同名相同，异名不同")
    fun setUsernameDerivesIdempotencyKeyFromUsername() =
        runTest {
            harness.sessions.save(AuthFixtures.session())
            repeat(3) { server.enqueue(json(200, AuthFixtures.userEnvelopeJson())) }

            repository.setUsername("anna_b")
            val first = server.takeRequest().headers["Idempotency-Key"]

            // 大小写与首尾空格只是同一个名字的不同写法 —— 归一化之后 key 必须一样。
            repository.setUsername(" ANNA_B ")
            val sameName = server.takeRequest().headers["Idempotency-Key"]

            repository.setUsername("bob_k")
            val otherName = server.takeRequest().headers["Idempotency-Key"]

            assertNotNull(first, "没带 Idempotency-Key")
            assertEquals(first, sameName)
            assertNotEquals(first, otherName, "异名共用一个 key 会撞 idempotency_key_reused")
        }

    /**
     * 契约 `/me/username` 的说明表：四种失败要分开处理。
     *
     * 尤其 `limit_exceeded` —— ADR-0017 决策二点名了 Android：对 429 与 422 的处置
     * 分别是「自动退避重试」与「终局错误，展示给用户」，选错的后果是用户
     * 看着一个永远转圈的按钮。
     */
    @Test
    @DisplayName("四种失败各自到达调用方，不被合并")
    fun setUsernameSurfacesEachFailureSeparately() =
        runTest {
            harness.sessions.save(AuthFixtures.session())

            enqueueProblem(409, "username_taken")
            assertInstanceOf(ApiError.UsernameTaken::class.java, repository.setUsername("anna_b").failure())

            enqueueProblem(422, "username_invalid")
            assertInstanceOf(ApiError.UsernameInvalid::class.java, repository.setUsername("anna_b").failure())

            enqueueProblem(409, "username_immutable")
            assertInstanceOf(ApiError.UsernameImmutable::class.java, repository.setUsername("anna_b").failure())

            enqueueProblem(422, "limit_exceeded")
            assertInstanceOf(ApiError.LimitExceeded::class.java, repository.setUsername("anna_b").failure())
        }

    private fun enqueueSession() = server.enqueue(json(200, AuthFixtures.sessionJson("a1", "r1")))

    private fun enqueueProblem(
        status: Int,
        code: String,
    ) = server.enqueue(
        MockResponse
            .Builder()
            .code(status)
            .setHeader("Content-Type", "application/problem+json")
            .body(AuthFixtures.problemJson(status, code))
            .build(),
    )

    private fun json(
        status: Int,
        body: String,
    ): MockResponse =
        MockResponse
            .Builder()
            .code(status)
            .setHeader("Content-Type", "application/json")
            .body(body)
            .build()

    /**
     * `assertInstanceOf` 返回的是擦除了泛型的原始类型（它吃的是 `Class<*>`），
     * 于是 `.value` 拿不到具体类型。这个小助手把断言与取值合在一起。
     */
    private fun <T> ApiResult<T>.successValue(): T {
        assertInstanceOf(ApiResult.Success::class.java, this)
        @Suppress("UNCHECKED_CAST")
        return (this as ApiResult.Success<T>).value
    }

    /** 对称的另一半：断言失败并取出 [ApiError]。 */
    private fun <T> ApiResult<T>.failure(): ApiError = assertInstanceOf(ApiResult.Failure::class.java, this).error

    /** 从请求体 JSON 里抠出 `device.id`，不为一条断言引一个 JSON 断言库。 */
    private fun String.deviceId(): String =
        substringAfter("\"device\"")
            .substringAfter("\"id\":\"")
            .substringBefore('"')
}
