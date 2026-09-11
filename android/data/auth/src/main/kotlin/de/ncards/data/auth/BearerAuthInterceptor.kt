package de.ncards.data.auth

import okhttp3.Interceptor
import okhttp3.Response
import javax.inject.Inject

/**
 * T-010 留下的两个认证插槽里的第一个：给每个请求挂
 * `Authorization: Bearer <access_jwt>`（§6.1 的「认证」那一行）。
 *
 * ## 三条不做的事
 *
 * - **没有令牌时原样放行**，不抛异常也不短路。未登录时调公开端点
 *   （OTP 请求、`GET /v1/config`）是正常路径；而一个已登录用户的令牌恰好为空时，
 *   服务端的 401 比客户端自己编一个错误有用得多 —— 后者会绕开 [SessionAuthenticator]。
 * - **不刷新、不判过期。** 本类不解析 JWT 的 `exp`：那需要在每个请求上做一次
 *   base64 解码 + JSON 解析，而客户端的时钟未必准。「过期了」由服务端的 401 定义，
 *   处置在 [SessionAuthenticator]。
 * - **不碰调用方已经设好的 `Authorization`。** 今天没有这样的调用方，
 *   但覆盖掉一个显式意图从来不是拦截器该做的事（与 `RequestIdInterceptor` 同调）。
 *   ⚠️ 与 `ClientHeaderInterceptor` 的覆盖语义相反，两者的理由也相反：
 *   那个 header 的值有唯一真相源，这个没有。
 *
 * ## 它在链条的最内层
 *
 * `ClientHeader → RequestId → Retry → [日志] → **Bearer** → 网络`
 * （接线见 `NetworkModule.provideOkHttpClient`）。
 *
 * 在 `Retry` **里面**是有意的：一次 503 退避可能睡 8 秒，而 access token 只有
 * 15 分钟寿命。装在最内层意味着每一次真实的网络往返都重新读一次令牌存储，
 * 而不是把这次调用开始时的那一枚钉死到它的全部重试上。
 *
 * ⚠️ 但**不要**指望它给 [SessionAuthenticator] 的重发请求也挂上 header ——
 * 那个重发发生在 `RetryAndFollowUpInterceptor` 里，位于全部应用拦截器**之内**，
 * 根本不会再经过这里。所以 `Authenticator` 自己写 `Authorization`。
 */
internal class BearerAuthInterceptor
    @Inject
    constructor(
        private val sessions: SessionStore,
        private val publicEndpoints: PublicEndpoints,
    ) : Interceptor {
        /**
         * `ReturnCount` 的抑制：三个 return 是三种**放行理由**（免鉴权端点 /
         * 调用方已自带 header / 本机没有令牌）加上一条挂 header 的路径。
         * 合并成两个只能靠一个三目嵌套，而那正好把「为什么不挂」这件事藏起来 ——
         * 这个方法的全部内容就是那三个为什么。
         */
        @Suppress("ReturnCount")
        override fun intercept(chain: Interceptor.Chain): Response {
            val original = chain.request()

            // ⚠️ 见 PublicEndpoints 的类注释：这里是一张逐条列出的路径表，
            // 不是 `auth/` 前缀 —— auth/logout 必须带 Bearer。
            if (publicEndpoints.contains(original) || original.header(AUTHORIZATION) != null) {
                return chain.proceed(original)
            }

            val token = sessions.accessToken() ?: return chain.proceed(original)

            return chain.proceed(
                original
                    .newBuilder()
                    .header(AUTHORIZATION, "$BEARER_PREFIX$token")
                    .build(),
            )
        }

        internal companion object {
            /**
             * ⚠️ 不放进 `core:network:impl` 的 `HttpHeaders`：那个 object 收的是
             * **契约自定义的** header 名（`X-Client`、`Idempotency-Replayed` 这类，
             * 它们不会被生成器提成常量）。`Authorization` 是 RFC 7235 的标准头，
             * 没有拼错了看不出来的风险，也不该让一个跨模块的常量表为它负责。
             */
            const val AUTHORIZATION = "Authorization"

            /** RFC 6750 §2.1 的 scheme。服务端用 `strcasecmp` 比，我们恒发 `Bearer`。 */
            const val BEARER_PREFIX = "Bearer "
        }
    }
