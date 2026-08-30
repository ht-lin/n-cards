package de.ncards.core.network.impl

import de.ncards.core.network.impl.error.ApiError
import de.ncards.core.network.impl.error.ApiErrorMapper
import kotlinx.coroutines.CancellationException
import retrofit2.Response

/**
 * 一次 API 调用的结果。`data:*` 的 Repository 只应该看见这个类型，
 * 看不见 `retrofit2.Response`、`HttpException` 或 `IOException`。
 *
 * 为什么不用 `kotlin.Result`：它的失败侧是 `Throwable`，而 [ApiError] 不是异常 ——
 * 「离线」在离线优先的 App 里是常态而不是异常（§4.3 铁律三：网络失败不回滚 UI，
 * 只更 `sync_state`）。把它建模成异常，第一个后果就是有人写 `catch` 然后吞掉。
 */
sealed interface ApiResult<out T> {
    data class Success<T>(
        val value: T,
        /** 响应回显的 `X-Request-Id`。 */
        val requestId: String?,
        /**
         * 命中了幂等回放 —— 本次**没有**真正执行，拿到的是此前那次的响应（ADR-0003）。
         *
         * outbox 重放一条已经成功过的写操作时会看到 `true`。此时不该再触发
         * 「创建成功」一类的用户可见反馈。
         */
        val idempotencyReplayed: Boolean,
    ) : ApiResult<T>

    data class Failure(
        val error: ApiError,
    ) : ApiResult<Nothing>
}

/** 成功取值，失败给 null。只用在「拿不到就算了」的读路径上。 */
fun <T> ApiResult<T>.valueOrNull(): T? = (this as? ApiResult.Success)?.value

/** 失败时的 [ApiError]，成功为 null。 */
fun <T> ApiResult<T>.errorOrNull(): ApiError? = (this as? ApiResult.Failure)?.error

/**
 * 把一次生成的 Retrofit 调用收敛成 [ApiResult]。
 *
 * 生成的方法一律返回 `Response<T>`（而不是裸 `T`），所以错误响应不会抛异常 ——
 * 但传输层失败仍然会。这个函数是两者唯一的汇合点：调用方从此只需要 `when (result)`。
 *
 * ```kotlin
 * when (val result = apiErrorMapper.execute { cardsApi.listCards(config.clientHeader) }) {
 *     is ApiResult.Success -> result.value.items
 *     is ApiResult.Failure -> when (result.error) { ... }
 * }
 * ```
 *
 * `204 No Content` 一类没有响应体的成功响应，生成的签名是 `Response<Unit>`，
 * `body()` 为 null —— 这里按 [Unit] 补上，所以调用方不需要为「成功但没有 body」
 * 单独写一个分支。
 *
 * ## 两条 detekt 抑制的理由
 *
 * - `TooGenericExceptionCaught`：这里**必须**兜住一切。Retrofit 的 suspend 调用会抛
 *   `IOException`（传输层）、`HttpException`、以及 converter 抛出的
 *   `SerializationException` —— 三者没有共同父类，而漏掉任何一个都会让一次网络
 *   失败变成 App 崩溃。`CancellationException` 已经单独放行。
 * - `ReturnCount`：三个 return 对应「传输失败 / 错误响应 / 成功」三条出路，
 *   合并成一条只会得到一个嵌套更深的版本。
 */
@Suppress("UNCHECKED_CAST", "TooGenericExceptionCaught", "ReturnCount")
suspend fun <T> ApiErrorMapper.execute(call: suspend () -> Response<T>): ApiResult<T> {
    val response = try {
        call()
    } catch (cancellation: CancellationException) {
        // 协程取消必须原样往上抛，否则 structured concurrency 就断了 ——
        // 「用户离开了这个页面」会被记成一次网络错误。
        throw cancellation
    } catch (throwable: Throwable) {
        return ApiResult.Failure(map(throwable))
    }

    if (!response.isSuccessful) {
        val body = runCatching { response.errorBody()?.string() }.getOrNull()
        return ApiResult.Failure(map(response.code(), response.headers(), body))
    }

    val value = response.body() ?: Unit as T
    return ApiResult.Success(
        value = value,
        requestId = response.headers()[HttpHeaders.X_REQUEST_ID],
        idempotencyReplayed = response.headers()[HttpHeaders.IDEMPOTENCY_REPLAYED] == "true",
    )
}
