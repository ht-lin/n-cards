package de.ncards.data.auth

import de.ncards.core.network.impl.error.ApiError
import de.ncards.core.network.impl.error.ApiErrorMapper
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import okhttp3.Headers
import java.io.IOException

/**
 * [ApiErrorMapper] 的测试替身。
 *
 * ⚠️ 为什么不用真的那个：`ProblemDetailsApiErrorMapper` 是 `core:network:impl`
 * 的 `internal` 类，模块外构造不出来（生产代码靠 Hilt 拿到它）。
 *
 * 这**不是**在绕过覆盖：真映射器的行为由
 * `core:network:impl` 的 `ApiErrorMapperTest` 与 `ApiErrorCoverageTest`
 * 逐个错误码验过了，而本模块的用例要验的是「拿到某个 `ApiError` 之后怎么办」。
 * 两件事。
 *
 * 只认本模块真的会分支的那几个 code，其余一律 [ApiError.Unexpected] ——
 * 与真映射器对未知 code 的处置一致。
 */
internal class FakeApiErrorMapper : ApiErrorMapper {
    override fun map(
        status: Int,
        headers: Headers,
        body: String?,
    ): ApiError {
        val requestId = headers["X-Request-Id"]
        val code =
            body
                ?.takeIf { it.isNotBlank() }
                ?.let { runCatching { Json.parseToJsonElement(it) }.getOrNull() }
                ?.jsonObject
                ?.get("code")
                ?.jsonPrimitive
                ?.content

        return when (code) {
            "token_expired" -> ApiError.TokenExpired(requestId)
            "token_invalid" -> ApiError.TokenInvalid(requestId)
            "username_required" -> ApiError.UsernameRequired(requestId)
            "rate_limited" -> ApiError.RateLimited(retryAfter = null, remaining = null, requestId = requestId)
            "service_unavailable" -> ApiError.ServiceUnavailable(retryAfter = null, requestId = requestId)
            "client_too_old" -> ApiError.ClientTooOld(requestId)
            "internal_error" -> ApiError.InternalError(requestId)
            else -> ApiError.Unexpected(status, code, requestId)
        }
    }

    override fun map(cause: Throwable): ApiError =
        if (cause is IOException) {
            ApiError.Network(cause)
        } else {
            ApiError.Serialization(cause, requestId = null)
        }
}
