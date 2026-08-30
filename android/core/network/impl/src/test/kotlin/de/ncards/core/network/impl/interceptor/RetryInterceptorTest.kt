package de.ncards.core.network.impl.interceptor

import mockwebserver3.MockResponse
import mockwebserver3.MockWebServer
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import org.junit.jupiter.api.AfterEach
import org.junit.jupiter.api.Assertions.assertEquals
import org.junit.jupiter.api.Assertions.assertThrows
import org.junit.jupiter.api.Assertions.assertTrue
import org.junit.jupiter.api.BeforeEach
import org.junit.jupiter.api.DisplayName
import org.junit.jupiter.api.Test
import java.io.IOException
import kotlin.time.Duration
import kotlin.time.Duration.Companion.milliseconds
import kotlin.time.Duration.Companion.seconds

/**
 * [RetryInterceptor] 的重试边界。
 *
 * 退避不真的睡：[RetryInterceptor.Sleeper] 换成一个只记账的实现，所以这些用例是
 * 毫秒级的，同时还能断言「等了多久」——而那正是 `Retry-After` 有没有被读到的证据。
 */
@DisplayName("RetryInterceptor")
class RetryInterceptorTest {
    private lateinit var server: MockWebServer
    private val slept = mutableListOf<Duration>()
    private val interceptor = RetryInterceptor { duration -> slept += duration }

    @BeforeEach
    fun startServer() {
        server = MockWebServer()
        server.start()
        slept.clear()
    }

    @AfterEach
    fun stopServer() {
        server.close()
    }

    @Test
    @DisplayName("429 按 Retry-After（秒）退避后重试，最终拿到成功响应")
    fun retriesRateLimitedHonouringRetryAfter() {
        server.enqueue(
            MockResponse
                .Builder()
                .code(429)
                .setHeader("Retry-After", "2")
                .build(),
        )
        server.enqueue(
            MockResponse
                .Builder()
                .code(200)
                .body("{}")
                .build(),
        )

        val response = execute(get())

        assertEquals(200, response.code)
        assertEquals(2, server.requestCount)
        assertEquals(listOf(2.seconds), slept)
    }

    @Test
    @DisplayName("503 也重试 —— 它是服务端故障（维护中，或限流器 fail-closed，ADR-0005）")
    fun retriesServiceUnavailable() {
        server.enqueue(MockResponse.Builder().code(503).build())
        server.enqueue(
            MockResponse
                .Builder()
                .code(200)
                .body("{}")
                .build(),
        )

        assertEquals(200, execute(get()).code)
        assertEquals(2, server.requestCount)
    }

    @Test
    @DisplayName("没有 Retry-After 时用指数退避 + 抖动，不是不等")
    fun fallsBackToExponentialBackoff() {
        server.enqueue(MockResponse.Builder().code(503).build())
        server.enqueue(
            MockResponse
                .Builder()
                .code(200)
                .body("{}")
                .build(),
        )

        execute(get())

        assertEquals(1, slept.size)
        assertTrue(slept.single() >= 500.milliseconds) { "退避太短：${slept.single()}" }
    }

    @Test
    @DisplayName("服务端要求等很久时封顶，不在拦截器里静默挂起五分钟")
    fun capsServerRequestedDelay() {
        server.enqueue(
            MockResponse
                .Builder()
                .code(503)
                .setHeader("Retry-After", "300")
                .build(),
        )
        server.enqueue(
            MockResponse
                .Builder()
                .code(200)
                .body("{}")
                .build(),
        )

        execute(get())

        assertEquals(listOf(10.seconds), slept)
    }

    @Test
    @DisplayName("最多发 3 次，之后把最后那个响应交给调用方")
    fun givesUpAfterThreeAttempts() {
        repeat(4) { server.enqueue(MockResponse.Builder().code(503).build()) }

        val response = execute(get())

        assertEquals(503, response.code)
        assertEquals(3, server.requestCount)
    }

    @Test
    @DisplayName("500 不重试 —— §6.1 的应对是「提示稍后重试 + 上报 Sentry」，不是自动重试")
    fun doesNotRetryInternalError() {
        server.enqueue(MockResponse.Builder().code(500).build())

        assertEquals(500, execute(get()).code)
        assertEquals(1, server.requestCount)
        assertTrue(slept.isEmpty())
    }

    @Test
    @DisplayName("409 idempotency_in_progress 不在这里重试 —— 那归 §5.4.3 的 outbox")
    fun doesNotRetryIdempotencyInProgress() {
        server.enqueue(
            MockResponse
                .Builder()
                .code(409)
                .setHeader("Retry-After", "1")
                .build(),
        )

        assertEquals(409, execute(get()).code)
        assertEquals(1, server.requestCount)
    }

    @Test
    @DisplayName("不带 Idempotency-Key 的 POST **绝不**重试：可能已经在服务端执行过了")
    fun neverRetriesNonIdempotentPost() {
        server.enqueue(MockResponse.Builder().code(503).build())

        val response = execute(post(idempotencyKey = null))

        assertEquals(503, response.code)
        assertEquals(1, server.requestCount)
        assertTrue(slept.isEmpty())
    }

    @Test
    @DisplayName("带 Idempotency-Key 的 POST 可以重试（ADR-0003：同 key 同体 → 回放）")
    fun retriesIdempotentPost() {
        server.enqueue(MockResponse.Builder().code(503).build())
        server.enqueue(
            MockResponse
                .Builder()
                .code(201)
                .body("{}")
                .build(),
        )

        assertEquals(201, execute(post(idempotencyKey = "0192f3a1-b2c3-7d4e-8f01-23456789abcd")).code)
        assertEquals(2, server.requestCount)
    }

    @Test
    @DisplayName("连不上时重试；重试耗尽后原样把 IOException 抛出去")
    fun retriesTransportFailuresThenRethrows() {
        // 服务器关掉 —— 每一次尝试都会是一个连接失败。
        server.close()

        assertThrows(IOException::class.java) { execute(get()) }
        // 3 次尝试 = 2 次退避。
        assertEquals(2, slept.size)
    }

    private fun get(): Request = Request.Builder().url(server.url("/v1/cards")).build()

    private fun post(idempotencyKey: String?): Request =
        Request
            .Builder()
            .url(server.url("/v1/cards"))
            .post("{}".toRequestBody())
            .apply { idempotencyKey?.let { key -> header("Idempotency-Key", key) } }
            .build()

    private fun execute(request: Request): okhttp3.Response {
        val client = OkHttpClient
            .Builder()
            .addInterceptor(interceptor)
            // 关掉 OkHttp 自己的连接重试，否则「发了几次」数不清楚。
            .retryOnConnectionFailure(false)
            .build()
        return client.newCall(request).execute().also { response -> response.close() }
    }
}
