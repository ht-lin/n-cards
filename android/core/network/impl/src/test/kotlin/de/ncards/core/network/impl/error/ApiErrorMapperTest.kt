package de.ncards.core.network.impl.error

import de.ncards.core.network.api.infrastructure.Serializer
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.jsonPrimitive
import okhttp3.Headers
import org.junit.jupiter.api.Assertions.assertEquals
import org.junit.jupiter.api.Assertions.assertInstanceOf
import org.junit.jupiter.api.Assertions.assertNull
import org.junit.jupiter.api.Assertions.assertTrue
import org.junit.jupiter.api.DisplayName
import org.junit.jupiter.api.Test
import java.io.IOException
import java.net.SocketTimeoutException
import kotlin.time.Duration.Companion.seconds

/**
 * `application/problem+json` + 响应头 → [ApiError]（§6.1）。
 *
 * 夹具全部照抄 `docs/api/openapi.yaml` 里各个错误响应的 `example` —— 那是
 * `.spectral.yaml` 强制每个响应都要有的东西，后端的 `OpenApiContractHarnessTest`
 * 也拿它当夹具。两端用同一份样本，「契约里写的」和「两端各自以为的」才对得上。
 */
@DisplayName("ProblemDetailsApiErrorMapper")
class ApiErrorMapperTest {
    private val json = Json {
        ignoreUnknownKeys = true
        explicitNulls = false
        serializersModule = Serializer.kotlinxSerializationAdapters
    }
    private val mapper = ProblemDetailsApiErrorMapper(json)

    @Test
    @DisplayName("token_expired 认得出来，request_id 从 body 带出")
    fun mapsTokenExpired() {
        val error = mapper.map(
            status = 401,
            headers = Headers.headersOf("X-Request-Id", REQUEST_ID),
            body = problem(status = 401, code = "token_expired"),
        )

        val expired = assertInstanceOf(ApiError.TokenExpired::class.java, error)
        assertEquals(REQUEST_ID, expired.requestId)
    }

    @Test
    @DisplayName("429 带 Retry-After（秒）与 X-RateLimit-Remaining，两个都要读到（§7.5）")
    fun mapsRateLimited() {
        val error = mapper.map(
            status = 429,
            headers = Headers.headersOf(
                "X-Request-Id",
                REQUEST_ID,
                "Retry-After",
                "30",
                "X-RateLimit-Remaining",
                "0",
            ),
            body = problem(status = 429, code = "rate_limited"),
        )

        val limited = assertInstanceOf(ApiError.RateLimited::class.java, error)
        assertEquals(30.seconds, limited.retryAfter)
        assertEquals(0, limited.remaining)
    }

    @Test
    @DisplayName("Retry-After 是 HTTP-date 而不是秒数时给 null，不猜一个默认值")
    fun ignoresHttpDateRetryAfter() {
        val error = mapper.map(
            status = 503,
            headers = Headers.headersOf("Retry-After", "Wed, 21 Oct 2026 07:28:00 GMT"),
            body = problem(status = 503, code = "service_unavailable"),
        )

        val unavailable = assertInstanceOf(ApiError.ServiceUnavailable::class.java, error)
        assertNull(unavailable.retryAfter)
    }

    @Test
    @DisplayName("validation_failed 的 errors[] 逐项映射；为空时不出现也不炸")
    fun mapsFieldErrors() {
        val body =
            """
            {
              "type": "https://api.n-cards.de/problems/validation-failed",
              "title": "Validation failed",
              "status": 400,
              "code": "validation_failed",
              "detail": "The request body failed validation.",
              "instance": "/v1/cards",
              "request_id": "$REQUEST_ID",
              "errors": [
                { "field": "title", "code": "too_long", "message": "Must be at most 60 characters." },
                { "field": "color", "code": "invalid_format", "message": "Must match ^#[0-9a-f]{6}$." }
              ]
            }
            """.trimIndent()

        val error = mapper.map(400, Headers.headersOf(), body)

        val failed = assertInstanceOf(ApiError.ValidationFailed::class.java, error)
        assertEquals(
            listOf(FieldError.Code.TOO_LONG, FieldError.Code.INVALID_FORMAT),
            failed.fieldErrors.map(FieldError::code),
        )
        assertEquals(listOf("title", "color"), failed.fieldErrors.map(FieldError::field))
    }

    @Test
    @DisplayName("errors 缺席时给空列表 —— 契约说它为空时**不出现**，不是发成 []")
    fun absentErrorsBecomeEmptyList() {
        val error = mapper.map(400, Headers.headersOf(), problem(400, "validation_failed"))

        val failed = assertInstanceOf(ApiError.ValidationFailed::class.java, error)
        assertTrue(failed.fieldErrors.isEmpty())
    }

    @Test
    @DisplayName("revision_conflict 把 current 原样带出来，供 §5.4.3 的三路合并用")
    fun keepsConflictSnapshot() {
        val body =
            """
            {
              "type": "https://api.n-cards.de/problems/revision-conflict",
              "title": "Revision conflict",
              "status": 409,
              "code": "revision_conflict",
              "detail": "The card was modified by another member.",
              "instance": "/v1/cards/0192f3a1-0000-7000-8000-000000000001",
              "request_id": "$REQUEST_ID",
              "current": { "revision": 7, "title": "PAYBACK" }
            }
            """.trimIndent()

        val error = mapper.map(409, Headers.headersOf(), body)

        val conflict = assertInstanceOf(ApiError.RevisionConflict::class.java, error)
        assertEquals(
            "7",
            conflict.current
                ?.get("revision")
                ?.jsonPrimitive
                ?.content,
        )
        assertEquals(
            "PAYBACK",
            conflict.current
                ?.get("title")
                ?.jsonPrimitive
                ?.content,
        )
    }

    @Test
    @DisplayName("服务端新增的 code 落到 Unexpected 而不是抛异常（§13.6 前向兼容）")
    fun unknownCodeBecomesUnexpected() {
        val error = mapper.map(
            status = 418,
            headers = Headers.headersOf("X-Request-Id", REQUEST_ID),
            body = problem(status = 418, code = "teapot_overheated"),
        )

        val unexpected = assertInstanceOf(ApiError.Unexpected::class.java, error)
        assertEquals(418, unexpected.status)
        assertEquals(REQUEST_ID, unexpected.requestId)
    }

    @Test
    @DisplayName("problem body 里多出未知字段不影响解析（ignoreUnknownKeys，§3.10）")
    fun toleratesUnknownFields() {
        val body = problem(403, "not_a_member").dropLast(1) +
            ""","some_future_field":{"nested":true},"another":42}"""

        val error = mapper.map(403, Headers.headersOf(), body)

        assertInstanceOf(ApiError.NotAMember::class.java, error)
    }

    @Test
    @DisplayName("不是 problem+json 的错误体（反代的 HTML 页）→ Unexpected，不是解析异常")
    fun nonProblemBodyBecomesUnexpected() {
        val error = mapper.map(502, Headers.headersOf(), "<html><body>502 Bad Gateway</body></html>")

        val unexpected = assertInstanceOf(ApiError.Unexpected::class.java, error)
        assertEquals(502, unexpected.status)
        assertNull(unexpected.rawCode)
    }

    @Test
    @DisplayName("空响应体 → Unexpected")
    fun emptyBodyBecomesUnexpected() {
        val error = mapper.map(504, Headers.headersOf("X-Request-Id", REQUEST_ID), body = null)

        val unexpected = assertInstanceOf(ApiError.Unexpected::class.java, error)
        assertEquals(504, unexpected.status)
        assertEquals(REQUEST_ID, unexpected.requestId)
    }

    @Test
    @DisplayName("IOException → Network；其余 Throwable → Serialization")
    fun mapsTransportFailures() {
        assertInstanceOf(ApiError.Network::class.java, mapper.map(SocketTimeoutException("timeout")))
        assertInstanceOf(ApiError.Network::class.java, mapper.map(IOException("boom")))
        assertInstanceOf(ApiError.Serialization::class.java, mapper.map(IllegalStateException("boom")))
    }

    private fun problem(
        status: Int,
        code: String,
    ) = """
        {"type":"https://api.n-cards.de/problems/x","title":"T","status":$status,
         "code":"$code","detail":"d","instance":"/v1/cards","request_id":"$REQUEST_ID"}
        """.trimIndent()

    private companion object {
        const val REQUEST_ID = "0192f3a1-b2c3-7d4e-8f01-23456789abcd"
    }
}
