package de.ncards.data.auth

import de.ncards.core.network.impl.error.ApiError
import de.ncards.core.network.impl.error.ApiErrorMapper
import kotlinx.coroutines.runBlocking
import okhttp3.Authenticator
import okhttp3.Request
import okhttp3.Response
import okhttp3.Route
import javax.inject.Inject

/**
 * T-010 留下的两个认证插槽里的第二个：401 的静默刷新与重发（§6.1）。
 *
 * | code | 应对 |
 * |---|---|
 * | `token_expired` | **静默刷新后重试一次** |
 * | `token_invalid` | **清空本地会话，跳登录，不要重试** |
 *
 * ============================================================================
 * 为什么是 `Authenticator` 而不是拦截器
 * ============================================================================
 * OkHttp 在 `RetryAndFollowUpInterceptor` 里调它，也就是**全部应用拦截器之内**。
 * 好处是它重发的请求不会再走一遍整条链（不会多一个 `X-Request-Id`、
 * 不会被 `RetryInterceptor` 当成新的一轮）；代价是它必须**自己**写
 * `Authorization` —— [BearerAuthInterceptor] 不会再被调到。
 *
 * ============================================================================
 * ⚠️ `runBlocking` 在这里是对的
 * ============================================================================
 * `Authenticator.authenticate` 是同步签名，而刷新是 suspend 的。它跑在 OkHttp
 * 的 dispatcher 线程上（`AsyncCall.run()` 内部），阻塞的是那条 call 自己的线程，
 * 不是主线程。
 *
 * ⚠️⚠️ 但它**占着 dispatcher 的槽位不放**，而 `Dispatcher` 的默认
 * `maxRequestsPerHost` 是 **5** —— 这就是刷新必须走一个独立 `Dispatcher` 的
 * 客户端的原因。完整链条写在 `NetworkModule.provideOkHttpClient` 的 KDoc 里；
 * 本类只要记住：`RefreshGate` 注入的是 `@Unauthenticated AuthApi`，别去换成
 * 普通那一个。
 */
internal class SessionAuthenticator
    @Inject
    constructor(
        private val sessions: SessionStore,
        private val gate: RefreshGate,
        private val mapper: ApiErrorMapper,
        private val publicEndpoints: PublicEndpoints,
    ) : Authenticator {
        /**
         * 返回 null = 放弃，把这个 401 原样交给调用方。
         *
         * `ReturnCount` 的抑制：六个 return 对应六种「不能或不必重发」的判定，
         * 每一种的理由都不同（见各自的注释）。合并只会得到一个嵌套六层的版本，
         * 而这个方法的价值恰恰在于能一眼看完它在什么情况下放手。
         */
        @Suppress("ReturnCount")
        override fun authenticate(
            route: Route?,
            response: Response,
        ): Request? {
            val failed = response.request

            // ① 免鉴权端点的 401 不归我们管。最要紧的是它挡住了递归：
            //    刷新自己拿到 401 时，不会再触发一次刷新。
            if (publicEndpoints.contains(failed)) return null

            // ② 「重试**一次**」（§6.1 原话）。OkHttp 会沿着 priorResponse 串起
            //    每一次重发，所以只要这条链上已经有过一次，就到此为止。
            //    没有这一条，一个持续返回 401 的服务端会让客户端刷新到
            //    MAX_FOLLOW_UPS(20) 次 —— 而每一次都是一轮令牌轮换。
            if (response.priorResponse != null) return null

            // ③ 这个请求本来就没带 Bearer（未登录时打受保护端点）。
            //    刷新解决不了它，服务端要的是「先登录」。
            val presented =
                failed
                    .header(BearerAuthInterceptor.AUTHORIZATION)
                    ?.removePrefix(BearerAuthInterceptor.BEARER_PREFIX)
                    ?: return null

            // ④ 对 code 分支，**不解析 detail**（§6.1 铁律）。
            //    peekBody 而不是 body()：响应体只能消费一次，而这个 Response
            //    在我们返回 null 时还要原样交给调用方。
            val error =
                mapper.map(
                    status = response.code,
                    headers = response.headers,
                    body = response.peekBody(PROBLEM_BODY_LIMIT_BYTES).string(),
                )

            return when (error) {
                // 会话已撤销 / 重放被判定为被窃 / 账号已删（ADR-0018 决策四把
                // 「用户行没了」也做成了 401，就是为了走到这里来）。
                // ⚠️ 不刷新：拿一枚属于已撤销会话的 refresh token 去换，
                //    服务端只会再给一个 token_invalid，白白消耗一次 60/h 的配额。
                is ApiError.TokenInvalid -> {
                    sessions.clear(SignedOutReason.SessionRevoked)
                    null
                }

                is ApiError.TokenExpired -> {
                    retryWithFreshToken(failed, presented)
                }

                // ⑤ 401 但 code 不是这两个 —— 契约里 401 只有这两种，所以落到
                //    这里意味着服务端加了新 code（§13.6 允许）或反代插了一手。
                //    保守放手：既不刷新也不清会话。
                else -> {
                    null
                }
            }
        }

        private fun retryWithFreshToken(
            failed: Request,
            presented: String,
        ): Request? =
            when (val outcome = runBlocking { gate.refresh(presented) }) {
                is RefreshGate.Outcome.Refreshed -> {
                    failed
                        .newBuilder()
                        // ⚠️ header() 不是 addHeader()：原请求上已经有一个过期的
                        //    Authorization，追加会得到两个。
                        .header(
                            BearerAuthInterceptor.AUTHORIZATION,
                            BearerAuthInterceptor.BEARER_PREFIX + outcome.accessToken,
                        ).build()
                }

                // ⑥ 会话没了（已清空）或只是这次没换成（429/503/离线）。
                //    两者都返回 null；差别在于前者已经把 SessionState 置成
                //    SignedOut，UI 会跳登录，而后者什么都不动。
                RefreshGate.Outcome.SessionEnded, RefreshGate.Outcome.Transient -> {
                    null
                }
            }

        private companion object {
            /**
             * problem+json 的响应体是几百字节量级（§6.1 的固定字段 + 可选 `errors`）。
             * 8 KiB 有两个数量级的余量，同时挡住「反代返回一个 HTML 错误页」那种
             * 意外把整页读进内存的情况。
             */
            const val PROBLEM_BODY_LIMIT_BYTES = 8L * 1024
        }
    }
