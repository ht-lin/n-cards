package de.ncards.core.network.impl.error

import de.ncards.core.network.api.model.Problem
import de.ncards.core.network.impl.HttpHeaders
import kotlinx.serialization.json.Json
import okhttp3.Headers
import java.io.IOException
import javax.inject.Inject
import javax.inject.Singleton
import kotlin.time.Duration
import kotlin.time.Duration.Companion.seconds

/**
 * HTTP 响应 / 异常 → [ApiError]。
 *
 * 除了 body 里的 `code`，它还必须读四个响应头（`docs/api/README.md` 原文：
 * 「T-010 的拦截器必须读它们 —— 否则客户端无从区分『真的执行了』与『拿到了回放』，
 * 也无从知道该等多久重试」）：
 *
 * | Header | 用途 |
 * |---|---|
 * | `X-Request-Id` | 上报 Sentry 时关联服务端日志；body 里取不到时的兜底 |
 * | `Retry-After` | **秒数**，不是 HTTP-date（§6.1）。429 / 503 / 409 in-progress |
 * | `X-RateLimit-Remaining` | 429 时恒为 0；多窗口取最小值（§7.5） |
 * | `Idempotency-Replayed` | 在**成功**路径上读，见 `ApiResult.Success` |
 */
interface ApiErrorMapper {
    /** 错误响应（非 2xx）。[body] 是 `application/problem+json` 的原文，可能为空。 */
    fun map(
        status: Int,
        headers: Headers,
        body: String?,
    ): ApiError

    /** 请求根本没走完：连不上、超时、TLS 失败、或者解析炸了。 */
    fun map(cause: Throwable): ApiError
}

@Singleton
internal class ProblemDetailsApiErrorMapper
    @Inject
    constructor(
        private val json: Json,
    ) : ApiErrorMapper {
        // ReturnCount：三个 return 是三种「拿不到 Problem」的形态（空体 / 不是
        // problem+json / 正常解出来），每一种的处理都不同。合并只会得到嵌套的 if。
        @Suppress("ReturnCount")
        override fun map(
            status: Int,
            headers: Headers,
            body: String?,
        ): ApiError {
            val requestId = headers[HttpHeaders.X_REQUEST_ID]
            val retryAfter = headers.retryAfter()
            val rateLimitRemaining = headers[HttpHeaders.X_RATE_LIMIT_REMAINING]?.toIntOrNull()

            if (body.isNullOrBlank()) {
                // 没有响应体的 4xx/5xx。契约要求每个错误响应都是 problem+json，所以走到
                // 这里意味着请求根本没到应用层（反代 502、网关超时页……）。
                return ApiError.Unexpected(status, rawCode = null, requestId = requestId)
            }

            val problem = runCatching { json.decodeFromString<Problem>(body) }.getOrNull()
                // 不是 problem+json，或者 problem 的必填字段缺了。前者是基础设施插进来的
                // HTML 错误页，后者是服务端回归 —— 两种都不该让调用方拿到一个假的 code。
                ?: return ApiError.Unexpected(status, rawCode = body.peekCode(), requestId = requestId)

            return problem.toApiError(retryAfter = retryAfter, rateLimitRemaining = rateLimitRemaining)
        }

        override fun map(cause: Throwable): ApiError =
            when (cause) {
                // IOException 覆盖了 OkHttp 的全部传输失败：连不上、超时、TLS 握手失败、
                // 以及 RetryInterceptor 重试耗尽后重新抛出的那个。
                is IOException -> ApiError.Network(cause)

                else -> ApiError.Serialization(cause, requestId = null)
            }

        /**
         * `Retry-After` 是**秒数**（§6.1 / `docs/api/README.md`），不是 HTTP-date。
         *
         * 解析不出来时返回 null 而不是猜一个默认值：调用方看到 null 会用自己的退避策略，
         * 而一个编出来的 5 秒会让人以为那是服务端的意思。
         */
        private fun Headers.retryAfter(): Duration? =
            this[HttpHeaders.RETRY_AFTER]
                ?.trim()
                ?.toLongOrNull()
                ?.takeIf { it >= 0 }
                ?.seconds

        /**
         * 解析失败时从原文里捞一眼 `code`，只为让 [ApiError.Unexpected.rawCode] 在
         * Sentry 里有点信息量。**不是**解析 —— 拿到什么都不参与分支。
         */
        private fun String.peekCode(): String? = RAW_CODE.find(this)?.groupValues?.getOrNull(1)

        private companion object {
            val RAW_CODE = Regex(""""code"\s*:\s*"([^"]{1,64})"""")
        }
    }
