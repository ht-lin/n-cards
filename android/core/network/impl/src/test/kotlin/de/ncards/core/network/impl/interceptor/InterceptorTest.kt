package de.ncards.core.network.impl.interceptor

import de.ncards.core.network.impl.NetworkConfig
import mockwebserver3.MockResponse
import mockwebserver3.MockWebServer
import mockwebserver3.RecordedRequest
import okhttp3.HttpUrl.Companion.toHttpUrl
import okhttp3.Interceptor
import okhttp3.OkHttpClient
import okhttp3.Request
import org.junit.jupiter.api.AfterEach
import org.junit.jupiter.api.Assertions.assertEquals
import org.junit.jupiter.api.Assertions.assertNotNull
import org.junit.jupiter.api.Assertions.assertTrue
import org.junit.jupiter.api.BeforeEach
import org.junit.jupiter.api.DisplayName
import org.junit.jupiter.api.Test
import java.util.UUID

/**
 * `X-Client` / `X-Request-Id` 两个拦截器（§6.1 / T-010 交付物）。
 *
 * 用真的 [MockWebServer] 而不是伪造 `Interceptor.Chain`：这两个拦截器的价值全在
 * 「发出去的报文里到底有什么」，而伪造的 chain 只能证明我们调用了自己写的方法。
 */
@DisplayName("请求头拦截器")
class InterceptorTest {
    private lateinit var server: MockWebServer

    @BeforeEach
    fun startServer() {
        server = MockWebServer()
        server.start()
    }

    @AfterEach
    fun stopServer() {
        server.close()
    }

    @Test
    @DisplayName("每个请求都带上 X-Client，且值符合契约的 pattern")
    fun addsClientHeader() {
        val recorded = exchange(client(ClientHeaderInterceptor(config())))

        assertEquals(CLIENT_HEADER, recorded.headers["X-Client"])
        assertTrue(NetworkConfig.CLIENT_HEADER_PATTERN.matches(recorded.headers["X-Client"].orEmpty()))
    }

    @Test
    @DisplayName("调用方传进来的 X-Client 被覆盖，不是追加成两份")
    fun overwritesCallerSuppliedClientHeader() {
        val recorded = exchange(client(ClientHeaderInterceptor(config()))) { builder ->
            // 生成的 Retrofit 方法签名里 xClient 是必填参数（契约逐操作声明的必然结果），
            // 所以调用方一定会带一份。拦截器必须是覆盖而不是追加 ——
            // 重复的 X-Client 在服务端是未定义行为。
            builder.addHeader("X-Client", "android/0.0.1 (1)")
        }

        assertEquals(listOf(CLIENT_HEADER), recorded.headers.values("X-Client"))
    }

    @Test
    @DisplayName("没有 X-Request-Id 时生成一个 UUID")
    fun generatesRequestId() {
        val recorded = exchange(client(RequestIdInterceptor()))

        val requestId = recorded.headers["X-Request-Id"]
        assertNotNull(requestId)
        // 生成的必须是能被服务端当 id 用的东西，不是随手一个字符串。
        UUID.fromString(requestId)
    }

    @Test
    @DisplayName("调用方显式给了 X-Request-Id 就不动它")
    fun keepsCallerSuppliedRequestId() {
        val explicit = "0192f3a1-b2c3-7d4e-8f01-23456789abcd"

        val recorded = exchange(client(RequestIdInterceptor())) { builder ->
            builder.header("X-Request-Id", explicit)
        }

        assertEquals(explicit, recorded.headers["X-Request-Id"])
    }

    private fun config(baseUrl: String = "https://api.ncards.de/v1/") =
        NetworkConfig(
            baseUrl = baseUrl.toHttpUrl(),
            clientHeader = CLIENT_HEADER,
        )

    private fun client(vararg interceptors: Interceptor) =
        OkHttpClient.Builder().apply { interceptors.forEach(::addInterceptor) }.build()

    private fun exchange(
        client: OkHttpClient,
        customise: (Request.Builder) -> Request.Builder = { it },
    ): RecordedRequest {
        server.enqueue(MockResponse.Builder().code(204).build())
        val request = customise(Request.Builder().url(server.url("/v1/cards"))).build()
        client.newCall(request).execute().use { response -> response.body?.close() }
        return server.takeRequest()
    }

    private companion object {
        const val CLIENT_HEADER = "android/1.4.0 (26)"
    }
}
