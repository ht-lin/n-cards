package de.ncards.core.network.impl.interceptor

import de.ncards.core.network.impl.HttpHeaders
import okhttp3.Interceptor
import okhttp3.Request
import okhttp3.Response
import java.io.IOException
import javax.inject.Inject
import kotlin.random.Random
import kotlin.time.Duration
import kotlin.time.Duration.Companion.milliseconds
import kotlin.time.Duration.Companion.seconds

/**
 * T-010 交付物里的「重试策略」。
 *
 * ## 重试什么
 *
 * | 情况 | 重试？ | 依据 |
 * |---|---|---|
 * | `IOException`（连不上 / 超时 / TLS） | ✅ | 离线优先的常态 |
 * | `429 rate_limited` | ✅ 按 `Retry-After` | §6.1「退避重试」 |
 * | `503 service_unavailable` | ✅ 按 `Retry-After` | §6.1「显示维护页」+ outbox 重试 |
 * | `500 internal_error` | ❌ | §6.1 的应对是「提示稍后重试 + 上报 Sentry」，不是自动重试 |
 * | `409 idempotency_in_progress` | ❌ | §6.1 明写「outbox 本就重试 409」，归 §5.4.3 |
 * | 其余 4xx | ❌ | 重试永远不会变成功 |
 *
 * `409` 那条还有个实现层面的理由：判它要先 peek 响应体拿 `code`，而在拦截器里
 * 消费响应体就得自己负责重新构造一个 —— 为一条本就该由 outbox 调度的错误做这件事
 * 不划算。
 *
 * ## 只重试安全或幂等的请求
 *
 * `GET` / `HEAD` / `DELETE` / `PUT` 按 HTTP 语义就是幂等的；`POST` 只在带了
 * `Idempotency-Key` 时才重试（ADR-0003：同 key 同体 → 回放，同 key 异体 → 422）。
 *
 * ⚠️ 不带 key 的 `POST` **绝不**重试。看着像超时的请求可能已经在服务端执行了，
 * 重试一次就是重复创建 —— 而 §6.1 之所以给所有 `POST` 都留了 `Idempotency-Key`，
 * 正是为了让「重试」变成一个可以安全做的动作。想要重试就带 key。
 *
 * `POST /cards` 是个例外中的例外：`id` 由客户端生成，天然幂等（重复提交返回同一张卡）。
 * 但那是**端点**的性质，不是 HTTP 方法的性质，拦截器这一层看不出来 ——
 * 让它带上 `Idempotency-Key` 即可，代价几乎为零。
 */
internal class RetryInterceptor
    @Inject
    constructor(
        private val sleeper: Sleeper,
    ) : Interceptor {
        /** 退避时真正睡觉的地方。抽出来只为让测试不用真的等 8 秒。 */
        fun interface Sleeper {
            fun sleep(duration: Duration)
        }

        override fun intercept(chain: Interceptor.Chain): Response {
            val request = chain.request()
            if (!request.isRetryable()) {
                return chain.proceed(request)
            }

            var attempt = 1
            while (true) {
                val lastAttempt = attempt >= MAX_ATTEMPTS

                val response = try {
                    chain.proceed(request)
                } catch (io: IOException) {
                    if (lastAttempt || chain.call().isCanceled()) throw io
                    sleeper.sleep(backoff(attempt))
                    attempt++
                    continue
                }

                if (lastAttempt || response.code !in RETRYABLE_STATUS || chain.call().isCanceled()) {
                    return response
                }

                val delay = response.serverRequestedDelay() ?: backoff(attempt)
                // 不关掉就泄连接：这个响应我们不再返回给任何人。
                response.close()
                sleeper.sleep(delay)
                attempt++
            }
        }

        private fun Request.isRetryable(): Boolean =
            when (method) {
                "GET", "HEAD", "DELETE", "PUT" -> true
                "POST" -> header(HttpHeaders.IDEMPOTENCY_KEY) != null
                else -> false
            }

        /** `Retry-After` 是**秒数**（§6.1），不是 HTTP-date。上限见 [MAX_SERVER_DELAY]。 */
        private fun Response.serverRequestedDelay(): Duration? =
            header(HttpHeaders.RETRY_AFTER)
                ?.trim()
                ?.toLongOrNull()
                ?.takeIf { seconds -> seconds >= 0 }
                ?.seconds
                ?.coerceAtMost(MAX_SERVER_DELAY)

        /**
         * 指数退避 + 抖动。抖动不是装饰：一次服务端抖动会让所有在线客户端同时收到 503，
         * 没有抖动的话它们会在同一毫秒一起回来，把刚缓过来的服务端再打死一次。
         */
        private fun backoff(attempt: Int): Duration {
            val exponential = BASE_DELAY_MS shl (attempt - 1)
            val jitter = Random.nextLong(0, exponential / 2 + 1)
            return (exponential + jitter).milliseconds
        }

        private companion object {
            /** 总共最多发 3 次（即最多重试 2 次）。 */
            const val MAX_ATTEMPTS = 3
            const val BASE_DELAY_MS = 500L
            val RETRYABLE_STATUS = setOf(429, 503)

            /**
             * 服务端说等 300 秒也不等 —— 那种情况该让调用方看到错误、显示维护页
             * （§6.1 对 503 的应对），而不是在一个拦截器里静默挂起五分钟。
             */
            val MAX_SERVER_DELAY = 10.seconds
        }
    }
