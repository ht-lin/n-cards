package de.ncards.core.network.impl.interceptor

import de.ncards.core.network.impl.HttpHeaders
import de.ncards.core.network.impl.NetworkConfig
import okhttp3.Interceptor
import okhttp3.Response
import javax.inject.Inject

/**
 * 给每个请求装上 `X-Client`（§6.1：所有 `/v1` 请求**必填**，缺失即 400）。
 *
 * ## 为什么用 `header()` 而不是 `addHeader()`
 *
 * 契约里 `X-Client` 是**逐操作声明**的参数（OpenAPI 没有「全局请求头」这个概念，
 * 见 `.spectral.yaml` 的 `ncards-operation-requires-x-client`），所以生成的每个
 * Retrofit 方法都带一个必填的 `xClient: String`：
 *
 * ```kotlin
 * suspend fun listCards(@Header("X-Client") xClient: String, ...): Response<CardPage>
 * ```
 *
 * 也就是说这个 header **一定**会被调用方带上一份。`addHeader()` 会得到两份，
 * 而重复的 `X-Client` 在服务端是未定义行为。`header()` 是覆盖语义 ——
 * 于是不管调用方传了什么，发出去的永远是 [NetworkConfig.clientHeader] 这一个值。
 *
 * 换句话说：**这个拦截器是 `X-Client` 的唯一真相源**，生成签名上那个参数是契约的
 * 必然产物，不是第二个开关。调用方按约定传 `NetworkConfig.clientHeader`，
 * 传错了也不会真的发出去 —— 但别指望这条兜底，`NetworkConfig` 的 `init` 会先拦。
 */
internal class ClientHeaderInterceptor
    @Inject
    constructor(
        private val config: NetworkConfig,
    ) : Interceptor {
        override fun intercept(chain: Interceptor.Chain): Response {
            val request = chain
                .request()
                .newBuilder()
                .header(HttpHeaders.X_CLIENT, config.clientHeader)
                .build()
            return chain.proceed(request)
        }
    }
