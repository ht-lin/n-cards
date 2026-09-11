package de.ncards.data.auth

import de.ncards.core.network.impl.NetworkConfig
import okhttp3.HttpUrl
import okhttp3.Request
import javax.inject.Inject
import javax.inject.Singleton

/**
 * 免鉴权的五个端点，**与后端 `AuthenticationListener::PUBLIC_ROUTES` 一一对应**。
 *
 * [BearerAuthInterceptor] 用它决定挂不挂 `Authorization`，
 * [SessionAuthenticator] 用它决定要不要接管一个 401。两处共用一张表 ——
 * 各写一份的话，某天加一个公开端点时一定会有人只改其中一处，
 * 而后果是「拦截器不挂 Bearer、Authenticator 却去刷新它的 401」这类没人看得懂的行为。
 *
 * ============================================================================
 * ⚠️⚠️ `auth/logout` **不在**这张表里
 * ============================================================================
 * 这是本文件唯一容易写错、且写错了没有任何症状的一处，与后端那一侧**完全对称**。
 *
 * 五个免鉴权端点里有三个在 `auth/` 下，于是「`auth/` 前缀一律不挂 Bearer」
 * 看起来完全等价 —— 但 `POST /v1/auth/logout` 也在那个前缀下，
 * 而它是 auth 组里**唯一需要 Bearer** 的端点（契约第 368 行往下逐字写了这句）。
 *
 * 用前缀的后果：logout 永远发不出去，而**没有任何迹象**。服务端返回 401，
 * 本地会话照样被 `AuthRepository.logout()` 清掉，用户看到的是「登出成功」，
 * 实际上那条会话在服务端一直活到 90 天后过期。
 *
 * 后端的 `AuthenticationListener` 类注释花了一整段讲同一个坑（那边的后果更严重：
 * 「任何人都能撤销任何会话，而所有测试照常绿」）。两边都选了逐条列出。
 *
 * ============================================================================
 * 为什么比**绝对**路径，不是 `endsWith`
 * ============================================================================
 * `contains` / `endsWith` 在一个将来可能出现 `/v1/admin/config` 的 API 面上
 * 是静默失效的。绝对路径由 [NetworkConfig.baseUrl] 拼出来（它带 `/v1/` 且以 `/`
 * 结尾，`NetworkConfig` 的 `init` 保证了这一点），所以这里比的是
 * `/v1/auth/token/refresh` 这样的完整路径。
 */
@Singleton
internal class PublicEndpoints
    @Inject
    constructor(
        config: NetworkConfig,
    ) {
        /** 绝对路径形态，如 `/v1/auth/token/refresh`。 */
        private val paths: Set<String> =
            RELATIVE_PATHS.mapTo(mutableSetOf()) { config.baseUrl.encodedPath + it }

        fun contains(request: Request): Boolean = contains(request.url)

        fun contains(url: HttpUrl): Boolean = url.encodedPath in paths

        private companion object {
            /**
             * 相对 `baseUrl` 的路径，与生成代码里的 `@POST("auth/token/refresh")` 同形。
             *
             * ⚠️ 前四条是「还没有 token 的人怎么拿到 token」，`config` 不是一类：
             * 它是「任何人都可以读的部署配置」（§6.2 元信息）。后端把这两类也写在
             * 同一张表里并加了同样的注释。免鉴权**不等于**免 `X-Client` ——
             * 一个过旧的客户端在 `config` 上拿到的仍然是 426，那正是 T-158
             * 升级墙的信号源。
             */
            val RELATIVE_PATHS = listOf(
                "auth/otp/request",
                "auth/otp/verify",
                "auth/magic/consume",
                "auth/token/refresh",
                "config",
            )
        }
    }
