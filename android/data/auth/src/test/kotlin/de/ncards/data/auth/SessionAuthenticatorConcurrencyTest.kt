package de.ncards.data.auth

import mockwebserver3.Dispatcher
import mockwebserver3.MockResponse
import mockwebserver3.MockWebServer
import mockwebserver3.RecordedRequest
import okhttp3.Request
import org.junit.jupiter.api.AfterEach
import org.junit.jupiter.api.Assertions.assertEquals
import org.junit.jupiter.api.BeforeEach
import org.junit.jupiter.api.DisplayName
import org.junit.jupiter.api.Test
import org.junit.jupiter.api.Timeout
import java.util.concurrent.CountDownLatch
import java.util.concurrent.Executors
import java.util.concurrent.TimeUnit
import java.util.concurrent.atomic.AtomicInteger

/**
 * T-150 验收标准第一条：**并发 5 个 401 只触发一次刷新**。
 *
 * ============================================================================
 * 为什么这条不能少：多刷一次就把用户踢下线
 * ============================================================================
 * §7.1 的轮换里，一枚已被使用过的 refresh token 不是「被拒绝」那么简单 ——
 * 服务端判定为**令牌被窃**：撤销整个会话家族、写 `audit_log(reuse_detected)`、
 * 告警，并**给用户发一封安全提醒邮件**。
 *
 * 也就是说，没有 [RefreshGate] 的 Mutex + 快速通道，一次「app 恢复前台、
 * 五个屏幕同时拉数据、令牌恰好过期」就会让用户收到一封「你的令牌可能被窃」
 * 并被登出。后端 `RefreshTokenService` 的类注释点名了「客户端侧的对应约束
 * 是 T-150 的 Mutex 串行化」。
 *
 * ============================================================================
 * ⚠️⚠️ 这条用例还会复现另一个 bug：`maxRequestsPerHost` 死锁
 * ============================================================================
 * `Dispatcher` 的默认 `maxRequestsPerHost` 是 **5**，而 `Authenticator` 是在
 * 这条 call 仍占着 `runningAsyncCalls` 槽位时被调用的。
 *
 * 于是如果哪天有人「顺手」把 [AuthTestHarness] 里的两个客户端合成一个
 * （或者把 `NetworkModule.provideOkHttpClient` 里那行 `.dispatcher(Dispatcher())`
 * 删掉），这条用例的表现**不是失败，是挂起**：5 条 call 全停在 `authenticate()`
 * 里占满槽位，刷新请求排在 `readyAsyncCalls` 里永远不被提升。
 *
 * `@Timeout` 因此是这条用例的一部分，不是保险：它把那个死锁变成一条
 * 看得见的失败。别把它删了，也别把它调大。
 */
@DisplayName("并发刷新")
class SessionAuthenticatorConcurrencyTest {
    private lateinit var server: MockWebServer
    private lateinit var harness: AuthTestHarness
    private val refreshCount = AtomicInteger()

    @BeforeEach
    fun setUp() {
        server = MockWebServer()
        // 按路径分发而不是 enqueue()：并发下到达顺序不确定，FIFO 队列会让
        // 「哪个响应配给哪个请求」变成运气。
        server.dispatcher = RoutingDispatcher()
        server.start()

        harness = AuthTestHarness(server)
        harness.sessions.save(AuthFixtures.session(accessToken = STALE_TOKEN, refreshToken = "r1"))
    }

    @AfterEach
    fun tearDown() {
        server.close()
    }

    @Test
    @Timeout(value = 30, unit = TimeUnit.SECONDS)
    @DisplayName("5 个请求同时拿到 401，只发生一次刷新，且 5 个都用新令牌成功重发")
    fun concurrentUnauthorizedTriggersExactlyOneRefresh() {
        val pool = Executors.newFixedThreadPool(CONCURRENCY)
        val start = CountDownLatch(1)

        try {
            val calls = (1..CONCURRENCY).map {
                pool.submit<Int> {
                    // 尽量让 5 条请求真的同时出发 —— 否则第一条可能先刷新完，
                    // 后四条走的是「本来就没有并发」的路径，用例就空转了。
                    start.await()
                    harness.client
                        .newCall(Request.Builder().url(server.url("/v1/cards")).build())
                        .execute()
                        .use { it.code }
                }
            }

            start.countDown()

            val codes = calls.map { it.get(20, TimeUnit.SECONDS) }

            assertEquals(List(CONCURRENCY) { 200 }, codes, "5 个请求都该在刷新后成功重发")
            assertEquals(1, refreshCount.get(), "刷新发生了 ${refreshCount.get()} 次 —— 第二次就会被判定为令牌被窃")
            assertEquals(FRESH_TOKEN, harness.sessions.accessToken())
            assertEquals("r2", harness.sessions.refreshToken())
        } finally {
            pool.shutdownNow()
        }
    }

    /**
     * ⚠️ `Idempotency-Key` 在刷新端点上是**安全机制**：服务端轮换完、响应在路上
     * 丢了，客户端拿旧令牌重试会命中重放检测。带上由 refresh token 导出的 key，
     * 中间件会回放同一对新令牌，那条误报路径才被堵上
     * （`TokenRefreshController` 的类注释逐字写了这段）。
     */
    @Test
    @Timeout(value = 30, unit = TimeUnit.SECONDS)
    @DisplayName("刷新请求带 Idempotency-Key，且同一枚 refresh token 每次都得到同一个 key")
    fun refreshCarriesDerivedIdempotencyKey() {
        val first = refreshOnce()

        // 把会话退回原样，再刷一次：同一枚 refresh token 必须导出同一个 key。
        harness.sessions.save(AuthFixtures.session(accessToken = STALE_TOKEN, refreshToken = "r1"))
        val second = refreshOnce()

        assertEquals(first, second, "同一枚 refresh token 的两次刷新用了不同的 key —— 重试会撞上重放检测")
        // 契约要求它是 UUID，服务端非 UUID 直接 400。
        java.util.UUID.fromString(first)
    }

    private fun refreshOnce(): String {
        harness.client
            .newCall(Request.Builder().url(server.url("/v1/cards")).build())
            .execute()
            .close()

        return idempotencyKeys.last()
    }

    private val idempotencyKeys = mutableListOf<String>()

    private inner class RoutingDispatcher : Dispatcher() {
        override fun dispatch(request: RecordedRequest): MockResponse =
            when {
                request.url.encodedPath.endsWith("/auth/token/refresh") -> {
                    refreshCount.incrementAndGet()
                    request.headers[IDEMPOTENCY_KEY]?.let { synchronized(idempotencyKeys) { idempotencyKeys += it } }
                    // 真实服务端会花一点时间轮换。没有这个停顿，第一条 call 可能
                    // 在别的 call 还没走到 authenticate() 之前就刷完了，
                    // 于是「并发」是假的。
                    Thread.sleep(REFRESH_LATENCY_MILLIS)
                    MockResponse
                        .Builder()
                        .code(200)
                        .setHeader("Content-Type", "application/json")
                        .body(AuthFixtures.sessionJson(FRESH_TOKEN, "r2"))
                        .build()
                }

                request.headers["Authorization"] == "Bearer $FRESH_TOKEN" -> {
                    MockResponse
                        .Builder()
                        .code(200)
                        .body("{}")
                        .build()
                }

                else -> {
                    MockResponse
                        .Builder()
                        .code(401)
                        .setHeader("Content-Type", "application/problem+json")
                        .body(AuthFixtures.problemJson(401, "token_expired"))
                        .build()
                }
            }
    }

    private companion object {
        /** 验收标准原话就是 5。也恰好是 `Dispatcher.maxRequestsPerHost` 的默认值。 */
        const val CONCURRENCY = 5
        const val STALE_TOKEN = "a1"
        const val FRESH_TOKEN = "a2"
        const val REFRESH_LATENCY_MILLIS = 150L
        const val IDEMPOTENCY_KEY = "Idempotency-Key"
    }
}
