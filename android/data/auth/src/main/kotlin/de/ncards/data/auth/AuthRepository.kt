package de.ncards.data.auth

import de.ncards.core.network.api.model.OtpChallenge
import de.ncards.core.network.api.model.OtpRequest
import de.ncards.core.network.api.model.User
import de.ncards.core.network.impl.ApiResult
import kotlinx.coroutines.flow.StateFlow
import java.util.UUID

/**
 * `data:auth` 对外的全部面（§12.3：feature 层只经 Repository 拿数据）。
 *
 * ============================================================================
 * ⚠️ 不变量：`Session` 这个类型**不出本模块**
 * ============================================================================
 * 登录与刷新的响应体里有 `access_token` / `refresh_token`，而 §7.3 要求令牌
 * 只存加密存储、绝不明文落到别处。这里的做法不是「提醒调用方别乱存」，
 * 而是让令牌**在类型层面**就到不了 UI：[verifyOtp] / [consumeMagicLink] 内部
 * 把 `Session` 交给 `SessionStore`，只把 [User] 交出去。
 *
 * T-151 的 ViewModel 因此拿不到令牌，也就不可能把它写进 `SavedStateHandle`
 * 或者打进日志。
 */
interface AuthRepository {
    /**
     * 本机有没有可用会话。`:app` 的 NavHost 用它选起始目的地。
     *
     * ⚠️ 初值是 [SessionState.Unknown]，那期间要显示 splash 而不是登录页 ——
     * 理由见 [SessionState] 的类注释。
     */
    val sessionState: StateFlow<SessionState>

    /**
     * 把存储里的会话读进内存，把 [sessionState] 从 [SessionState.Unknown] 推到终态。
     *
     * ⚠️ **`:app` 启动时必须调一次**（在协程里）。令牌是懒加载的 ——
     * 不调它的话，在第一个网络请求发生之前 [sessionState] 会一直停在
     * `Unknown`，而 NavHost 会一直显示 splash。
     *
     * 懒加载而不是在构造里读，是因为读一次要过两趟 Keystore，
     * 而 `SessionStore` 这个 `@Singleton` 完全可能在主线程上被第一次注入。
     * 这个方法自己切到 IO。
     */
    suspend fun restoreSession()

    /**
     * `POST /v1/auth/otp/request`。**恒 202**，即使邮箱没注册过（§3.8 防枚举）。
     *
     * 429 时**不要**自动重试，照 `Retry-After` 退避 —— 限速是
     * 每邮箱 1/min、5/h、10/day。
     */
    suspend fun requestOtp(
        email: String,
        locale: OtpRequest.Locale,
    ): ApiResult<OtpChallenge>

    /**
     * `POST /v1/auth/otp/verify`。成功即持久化会话。
     *
     * 返回的 [User] 的 `onboardingComplete` 为 false 时，调用方必须路由到
     * username 设定页（T-151）—— 在那之前除 `GET /me`、`POST /me/username`、
     * `POST /auth/logout` 外的每个 `/v1` 端点都会回 `403 username_required`。
     *
     * 限速：按 `challenge_id` 5 次总计；按 IP 60/h。
     */
    suspend fun verifyOtp(
        challengeId: UUID,
        code: String,
    ): ApiResult<User>

    /**
     * `POST /v1/auth/magic/consume`。语义与 [verifyOtp] 完全相同 ——
     * 契约里两者返回的是同一个 `Session` schema。
     *
     * ⚠️ 调用方是 **App**，不是邮件里那个落地页：它签发的是一次真实登录，
     * 所以要带 `device`（§6.2）。
     */
    suspend fun consumeMagicLink(token: String): ApiResult<User>

    /**
     * `GET /v1/me`。冷启动判 onboarding 的**唯一**来源。
     *
     * ⚠️ 不要试图从 access token 里读 onboarding 状态：§7.1 把 claim 集钉成了
     * 恰好 `sub sid did jti iat exp`，ADR-0018 明确拒绝了加一个 `onb` claim。
     */
    suspend fun fetchMe(): ApiResult<User>

    /**
     * `POST /v1/auth/logout`（要 Bearer），然后**无论结果如何**清空本机会话。
     *
     * 返回值是服务端那一半的结果，给调用方上报用；它是 `Failure` 也不代表
     * 用户还登着 —— 本机已经登出了。
     */
    suspend fun logout(): ApiResult<Unit>
}
