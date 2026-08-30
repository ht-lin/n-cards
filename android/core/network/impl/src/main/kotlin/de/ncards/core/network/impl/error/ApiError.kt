package de.ncards.core.network.impl.error

import de.ncards.core.network.api.model.Problem
import de.ncards.core.network.api.model.ProblemFieldError
import kotlin.time.Duration

/**
 * §6.1 统一错误码表的 Kotlin 形态。**每一个 `code` 一个类型**，外加三个传输层的。
 *
 * ## 为什么一码一类型
 *
 * §6.1 原文：「客户端只能对 `code` 分支，**不得解析 `detail`**」。一个 sealed 层级
 * 让「分支」变成编译器管的事：`when` 漏了一个分支就编译不过。
 *
 * 更要紧的是 [toApiError] 里那个**穷举的 `when`** —— 它直接 `when` 生成的
 * [Problem.Code]。契约新增一个错误码 → 重新生成 → 枚举多一项 → 那个 `when`
 * 不再穷举 → **编译失败**。这就是 T-010 作为「第四方」钉住 §6.1 错误码表的方式，
 * 比任何运行时断言都早，也比任何注释都硬。
 *
 * 三方已经钉死的另外三处（`docs/api/README.md`）：
 * `docs/TECHNICAL_SPEC.md` §6.1 的表、`backend/src/Shared/Domain/Error/ErrorCode.php`、
 * `docs/api/schemas/problem-details.schema.json`。
 *
 * ## 每个类型带什么
 *
 * 只带客户端**真的要用**的东西。§6.1「客户端应对」那一列写「客户端 bug，上报 Sentry」
 * 的，就只有 [requestId]（Sentry 上报要它关联服务端日志）；写「按 Retry-After 退避」的
 * 才带 [Duration]。多带一个字段，就多一个没人维护的字段。
 *
 * `detail` **不进**任何类型：带上它就一定会有人拿去显示给用户，而它是英文开发者文案
 * （§6.1）。面向用户的文案一律由客户端本地化生成。
 */
sealed interface ApiError {
    /**
     * 与响应头 `X-Request-Id` 同值（也与 problem body 的 `request_id` 同值）。
     *
     * 取不到时为 null —— 传输层错误（连不上）本来就没有响应。上报 Sentry 时必须带上，
     * 它是把客户端这一侧的报错接到服务端日志上的唯一线索。
     */
    val requestId: String?

    // ---- 400 ------------------------------------------------------------

    /** 字段校验失败。显示字段错误。 */
    data class ValidationFailed(
        val fieldErrors: List<FieldError>,
        override val requestId: String?,
    ) : ApiError

    /** 请求体不是合法 JSON / 不是 JSON 对象 / 为空。客户端 bug，上报 Sentry。 */
    data class MalformedRequest(
        override val requestId: String?,
    ) : ApiError

    // ---- 401 ------------------------------------------------------------

    /**
     * Access token 过期。静默刷新后重试**一次**。
     *
     * 处理它的是 T-150 的 OkHttp `Authenticator`（刷新必须用 Mutex 串行化 ——
     * 轮换重放检测会把并发刷新的用户踢下线）。本模块只负责把它认出来。
     */
    data class TokenExpired(
        override val requestId: String?,
    ) : ApiError

    /** 令牌无效 / 会话已撤销。清空本地会话，跳登录，**不要重试**。 */
    data class TokenInvalid(
        override val requestId: String?,
    ) : ApiError

    // ---- 403 ------------------------------------------------------------

    /** viewer 试图改卡 / 删卡 / 邀请成员。提示只读，**同时上报 Sentry** —— 正常 UI 不应产生此请求。 */
    data class InsufficientRole(
        override val requestId: String?,
    ) : ApiError

    /** 无权访问该卡。从本地删除该卡。 */
    data class NotAMember(
        override val requestId: String?,
    ) : ApiError

    /** 注册未完成（`username IS NULL`）。跳转 username 设定页（T-151）。 */
    data class UsernameRequired(
        override val requestId: String?,
    ) : ApiError

    /** 共享操作要求双方是已确认好友。提示需先加为好友，并刷新好友列表。 */
    data class NotFriends(
        override val requestId: String?,
    ) : ApiError

    // ---- 404 / 405 ------------------------------------------------------

    /** 含 username 的查找查无此人。显示「未找到该用户」。 */
    data class NotFound(
        override val requestId: String?,
    ) : ApiError

    /** 路由存在但方法不允许。客户端 bug，上报 Sentry。 */
    data class MethodNotAllowed(
        override val requestId: String?,
    ) : ApiError

    // ---- 409 ------------------------------------------------------------

    /**
     * 乐观锁失败。走 §5.4.3 的字段级三路合并。
     *
     * [current] 是服务端当前状态的快照，合并的输入之一。它是
     * `Map<String, JsonElement>` 而不是某个具体模型 —— 契约里就是自由形态对象，
     * 而三路合并本来就是逐字段比对，不需要先解成模型。
     */
    data class RevisionConflict(
        val current: Map<String, kotlinx.serialization.json.JsonElement>?,
        override val requestId: String?,
    ) : ApiError

    /** 同步游标失效。清库全量重同步（T-250）。 */
    data class FullResyncRequired(
        override val requestId: String?,
    ) : ApiError

    /** 好友已存在 / 已是成员 / 已有待处理邀请。幂等处理，当成功看。 */
    data class AlreadyExists(
        override val requestId: String?,
    ) : ApiError

    /** username 已被占用。提示重新输入。 */
    data class UsernameTaken(
        override val requestId: String?,
    ) : ApiError

    /** 试图修改已设定的 username。客户端 bug，上报 Sentry —— UI 不该给出这个入口（§16 R13）。 */
    data class UsernameImmutable(
        override val requestId: String?,
    ) : ApiError

    /** 客户端生成的 id 已属于他人（§5.4.3）。**重新生成 id 重试**。 */
    data class IdConflict(
        override val requestId: String?,
    ) : ApiError

    /**
     * 同一 `Idempotency-Key` 的前一次请求仍在处理中。按 [retryAfter] 退避重试。
     *
     * ⚠️ 这条**不由** `RetryInterceptor` 自动重试：§6.1 明写「outbox 本就重试 409」，
     * 归 §5.4.3 的 outbox 调度。在拦截器里判它还得先 peek 响应体。
     */
    data class IdempotencyInProgress(
        val retryAfter: Duration?,
        override val requestId: String?,
    ) : ApiError

    // ---- 413 / 415 ------------------------------------------------------

    /** 请求体超过上限。客户端 bug，上报 Sentry。 */
    data class PayloadTooLarge(
        override val requestId: String?,
    ) : ApiError

    /** `Content-Type` 不是 `application/json`。客户端 bug，上报 Sentry。 */
    data class UnsupportedMediaType(
        override val requestId: String?,
    ) : ApiError

    // ---- 422 ------------------------------------------------------------

    /** username 不符字符集 / 长度 / 保留词。显示具体规则。 */
    data class UsernameInvalid(
        val fieldErrors: List<FieldError>,
        override val requestId: String?,
    ) : ApiError

    /**
     * 触达系统限额（§7.5 的第一张表）。显示「额度已满」。
     *
     * ⚠️ 与 [RateLimited] **完全是两回事**：这是一个绝对的存量上限，
     * 重试**永远**不会成功。UI 说「稍后重试」就是错的。
     */
    data class LimitExceeded(
        override val requestId: String?,
    ) : ApiError

    /** 同一 `Idempotency-Key` 配了不同的请求体。客户端 bug，**不要重试**，上报 Sentry。 */
    data class IdempotencyKeyReused(
        override val requestId: String?,
    ) : ApiError

    // ---- 426 / 429 ------------------------------------------------------

    /** 低于 `/v1/config` 的 `min_supported_client`。强制升级墙（T-158）。 */
    data class ClientTooOld(
        override val requestId: String?,
    ) : ApiError

    /**
     * 限流：「我判定你超限了」。按 [retryAfter] 退避重试。
     *
     * §7.5 要求限流响应**必须**同时带 `Retry-After` 与 `X-RateLimit-Remaining`，
     * 后者超限时恒为 0。[remaining] 为 null 说明服务端没给 —— 那是服务端的回归
     * （`backend/tests/Api/RateLimitTest` 守着），不是客户端要兜的事。
     */
    data class RateLimited(
        val retryAfter: Duration?,
        val remaining: Int?,
        override val requestId: String?,
    ) : ApiError

    // ---- 500 / 503 ------------------------------------------------------

    /** 未预期的服务端故障。提示稍后重试并上报 Sentry。**不自动重试**（§6.1）。 */
    data class InternalError(
        override val requestId: String?,
    ) : ApiError

    /**
     * 维护中，**或**限流器无法判定（Redis 不可达且该策略 fail-closed，ADR-0005）。
     *
     * 这是服务端故障，走 §5.4.3 的 outbox 重试策略；[RetryInterceptor] 也会
     * 就地退避重试几次。与 [RateLimited]（「我判定你超限了」）不是一回事。
     */
    data class ServiceUnavailable(
        val retryAfter: Duration?,
        override val requestId: String?,
    ) : ApiError

    // ---- 传输层：不是服务端按 §6.1 返回的，是根本没走到那一步 --------------

    /** 连不上 / 超时 / TLS 失败。离线优先的常态，**不是错误提示的理由**（§4.3 铁律三：网络失败不回滚 UI）。 */
    data class Network(
        val cause: Throwable,
    ) : ApiError {
        override val requestId: String? get() = null
    }

    /** 响应解析失败。契约与实现漂了，上报 Sentry。 */
    data class Serialization(
        val cause: Throwable,
        override val requestId: String?,
    ) : ApiError

    /**
     * 服务端返回了本客户端不认识的东西：契约新增的 `code`（老客户端会落到这里）、
     * 不是 `problem+json` 的错误体、或者没有响应体的 4xx/5xx。
     *
     * §13.6 允许服务端新增错误码，所以这条**必须**存在 —— 但每落到这里一次都值得
     * 上报一次 Sentry：它意味着这个客户端版本该升级了。
     */
    data class Unexpected(
        val status: Int,
        val rawCode: String?,
        override val requestId: String?,
    ) : ApiError
}

/** §6.1 的 `errors[].code` 词表。**每一项一个分支**，理由同 [ApiError]。 */
data class FieldError(
    val field: String,
    val code: Code,
    /** 英文开发者文案。**不要显示给用户** —— 与 `detail` 同理。 */
    val developerMessage: String,
) {
    enum class Code {
        /** 必填字段缺失或为 null。 */
        REQUIRED,

        /** 长度 / 元素个数低于下限。 */
        TOO_SHORT,

        /** 长度 / 元素个数超过上限。 */
        TOO_LONG,

        /** 不符合字符集或格式（UUID、RFC 3339 时间、`X-Client`……）。 */
        INVALID_FORMAT,

        /** JSON 类型不对。 */
        INVALID_TYPE,

        /** 数值超出允许区间。 */
        OUT_OF_RANGE,

        /** 唯一性冲突的字段级形态。 */
        NOT_UNIQUE,

        /** 请求体里出现本端点不认识的字段。 */
        UNKNOWN_FIELD,

        /** 参数被本 API 明确不支持（如 `?offset=`）。 */
        UNSUPPORTED_PARAMETER,

        /** 契约新增、本客户端还不认识的字段错误码（§13.6）。 */
        UNKNOWN,
    }
}

/**
 * 生成的 [ProblemFieldError] → [FieldError]。
 *
 * `when` 穷举 [ProblemFieldError.Code]，理由同 [toApiError]。
 */
internal fun ProblemFieldError.toFieldError(): FieldError =
    FieldError(
        field = field,
        developerMessage = message,
        code = when (code) {
            ProblemFieldError.Code.required -> FieldError.Code.REQUIRED
            ProblemFieldError.Code.too_short -> FieldError.Code.TOO_SHORT
            ProblemFieldError.Code.too_long -> FieldError.Code.TOO_LONG
            ProblemFieldError.Code.invalid_format -> FieldError.Code.INVALID_FORMAT
            ProblemFieldError.Code.invalid_type -> FieldError.Code.INVALID_TYPE
            ProblemFieldError.Code.out_of_range -> FieldError.Code.OUT_OF_RANGE
            ProblemFieldError.Code.not_unique -> FieldError.Code.NOT_UNIQUE
            ProblemFieldError.Code.unknown_field -> FieldError.Code.UNKNOWN_FIELD
            ProblemFieldError.Code.unsupported_parameter -> FieldError.Code.UNSUPPORTED_PARAMETER
            ProblemFieldError.Code.unknown_default_open_api -> FieldError.Code.UNKNOWN
        },
    )

/**
 * 生成的 [Problem] → [ApiError]。
 *
 * ⚠️ 下面这个 `when` **没有 `else`**，这是本文件存在的全部理由。契约新增一个错误码，
 * 重新生成后它就不再穷举，编译当场失败，而失败信息直接点名新增的那一项。
 * 加 `else` 就等于把 T-010 的验收标准第 2 条（「`ApiError` 覆盖 §6.1 错误码表全部
 * 条目」）作废掉，别加。
 *
 * @param retryAfter 从响应头解析出来的 `Retry-After`（秒数，不是 HTTP-date）。
 * @param rateLimitRemaining 从响应头解析出来的 `X-RateLimit-Remaining`。
 *
 * `CyclomaticComplexMethod` 的抑制：复杂度 28 = §6.1 错误码表的条目数，一条不多
 * 一条不少。「拆分」在这里只能是把 27 个分支切成几段，让「一个 code 对应哪个类型」
 * 这件事需要跳三个函数才看得完整 —— 而这张表的价值恰恰在于能一眼扫完。
 */
@Suppress("CyclomaticComplexMethod")
internal fun Problem.toApiError(
    retryAfter: Duration?,
    rateLimitRemaining: Int?,
): ApiError {
    val fieldErrors = errors.orEmpty().map(ProblemFieldError::toFieldError)
    return when (code) {
        Problem.Code.validation_failed -> ApiError.ValidationFailed(fieldErrors, requestId)

        Problem.Code.malformed_request -> ApiError.MalformedRequest(requestId)

        Problem.Code.token_expired -> ApiError.TokenExpired(requestId)

        Problem.Code.token_invalid -> ApiError.TokenInvalid(requestId)

        Problem.Code.insufficient_role -> ApiError.InsufficientRole(requestId)

        Problem.Code.not_a_member -> ApiError.NotAMember(requestId)

        Problem.Code.username_required -> ApiError.UsernameRequired(requestId)

        Problem.Code.not_friends -> ApiError.NotFriends(requestId)

        Problem.Code.not_found -> ApiError.NotFound(requestId)

        Problem.Code.method_not_allowed -> ApiError.MethodNotAllowed(requestId)

        Problem.Code.revision_conflict -> ApiError.RevisionConflict(current, requestId)

        Problem.Code.full_resync_required -> ApiError.FullResyncRequired(requestId)

        Problem.Code.already_exists -> ApiError.AlreadyExists(requestId)

        Problem.Code.username_taken -> ApiError.UsernameTaken(requestId)

        Problem.Code.username_immutable -> ApiError.UsernameImmutable(requestId)

        Problem.Code.id_conflict -> ApiError.IdConflict(requestId)

        Problem.Code.idempotency_in_progress -> ApiError.IdempotencyInProgress(retryAfter, requestId)

        Problem.Code.payload_too_large -> ApiError.PayloadTooLarge(requestId)

        Problem.Code.unsupported_media_type -> ApiError.UnsupportedMediaType(requestId)

        Problem.Code.username_invalid -> ApiError.UsernameInvalid(fieldErrors, requestId)

        Problem.Code.limit_exceeded -> ApiError.LimitExceeded(requestId)

        Problem.Code.idempotency_key_reused -> ApiError.IdempotencyKeyReused(requestId)

        Problem.Code.client_too_old -> ApiError.ClientTooOld(requestId)

        Problem.Code.rate_limited -> ApiError.RateLimited(retryAfter, rateLimitRemaining, requestId)

        Problem.Code.internal_error -> ApiError.InternalError(requestId)

        Problem.Code.service_unavailable -> ApiError.ServiceUnavailable(retryAfter, requestId)

        // 生成器的 enumUnknownDefaultCase 兜底项（§13.6：服务端可以新增错误码，
        // 老客户端不能因此崩）。落到这里说明该升级了 —— 调用方应当上报 Sentry。
        Problem.Code.unknown_default_open_api -> ApiError.Unexpected(status, null, requestId)
    }
}
