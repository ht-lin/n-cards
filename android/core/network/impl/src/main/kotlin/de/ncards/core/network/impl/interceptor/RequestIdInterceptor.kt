package de.ncards.core.network.impl.interceptor

import de.ncards.core.network.impl.HttpHeaders
import okhttp3.Interceptor
import okhttp3.Response
import java.util.UUID
import javax.inject.Inject

/**
 * 给每个请求装上 `X-Request-Id`（§6.1：客户端可选提供，否则服务端生成，响应回显）。
 *
 * 客户端主动生成的价值在于**请求根本没到服务端时也有 id**：连接超时、TLS 失败、
 * DNS 失败这几类没有响应，服务端那一侧不存在这次请求，而客户端的崩溃报告里仍然要有
 * 一个能和重试串起来的标识。
 *
 * ## 它必须排在 [RetryInterceptor] **外面**
 *
 * 这样一次逻辑调用的多次重试共用同一个 id —— 服务端日志里看到的是「同一个请求被
 * 重试了 3 次」，而不是三次互不相干的请求。装反了，限流排查会变得毫无头绪。
 * 接线顺序见 `NetworkModule`。
 *
 * 已经带了 `X-Request-Id` 的请求不动它：调用方显式指定 id 是有意义的
 * （比如把一次同步的多个请求归到一起），覆盖掉就把那个意图吃掉了。
 */
internal class RequestIdInterceptor
    @Inject
    constructor() : Interceptor {
        override fun intercept(chain: Interceptor.Chain): Response {
            val original = chain.request()
            if (original.header(HttpHeaders.X_REQUEST_ID) != null) {
                return chain.proceed(original)
            }
            val request = original
                .newBuilder()
                .header(HttpHeaders.X_REQUEST_ID, UUID.randomUUID().toString())
                .build()
            return chain.proceed(request)
        }
    }
