package de.ncards.data.auth

import de.ncards.core.network.api.AuthApi
import de.ncards.core.network.api.model.TokenRefresh
import de.ncards.core.network.impl.ApiResult
import de.ncards.core.network.impl.NetworkConfig
import de.ncards.core.network.impl.di.Unauthenticated
import de.ncards.core.network.impl.error.ApiError
import de.ncards.core.network.impl.error.ApiErrorMapper
import de.ncards.core.network.impl.execute
import kotlinx.coroutines.sync.Mutex
import kotlinx.coroutines.sync.withLock
import timber.log.Timber
import java.security.MessageDigest
import java.util.UUID
import javax.inject.Inject
import javax.inject.Singleton

/**
 * `POST /v1/auth/token/refresh` 的**唯一**入口，并发刷新在这里被串行化。
 *
 * ============================================================================
 * ⚠️⚠️ 为什么必须串行：并发刷新会把用户踢下线
 * ============================================================================
 * §7.1 的轮换是「每次刷新签发新 refresh token，**旧的立即失效**，
 * `previous_token_hash` 记录」。服务端收到一个**已被使用过**的 refresh token
 * 不会只是拒绝它 —— 那被判定为**令牌被窃**：
 *
 *   撤销该会话家族的全部令牌 + 写 `audit_log(reuse_detected)` + 告警 +
 *   **给用户发一封安全提醒邮件**。
 *
 * 也就是说：两个请求同时 401、各自拿着同一枚 refresh token 去刷新，第二个必然
 * 命中 `previous_token_hash`，而用户会收到一封「你的令牌可能被窃」的信，
 * 同时被登出。后端 `RefreshTokenService` 的类注释与 M1.md:582 都点名了
 * 「客户端侧的对应约束是 T-150 的 Mutex 串行化」—— 这个类就是那句话。
 *
 * ============================================================================
 * Mutex 只保证串行，**快速通道**才保证「只刷新一次」
 * ============================================================================
 * 光有锁的话，5 个并发 401 会变成 5 次**排队的**刷新 —— 第 2 次就踩重放检测。
 * 真正让「并发 5 个 401 只触发一次刷新」（T-150 验收标准第一条）成立的是
 * [refresh] 开头那一段：拿到锁之后先看看存储里的 access token 是不是**已经变了**。
 * 变了就说明前一个持锁者刚换过，直接用新的重发即可。
 *
 * 比较的是「**我这次失败时用的那一枚**」而不是「存储里有没有值」——
 * 后者永远是 true，那样写等于没有快速通道。
 */
@Singleton
internal class RefreshGate
    @Inject
    constructor(
        // ⚠️ @Unauthenticated：刷新必须走那条没有 Bearer、没有 Authenticator、
        // 且**不共享 Dispatcher** 的路。三条理由见 NetworkModule 的两处 KDoc；
        // 其中 Dispatcher 那条是硬的 —— 共用会在恰好 5 个并发 401 时死锁。
        @Unauthenticated private val api: AuthApi,
        private val sessions: SessionStore,
        private val mapper: ApiErrorMapper,
        private val config: NetworkConfig,
    ) {
        private val mutex = Mutex()

        /**
         * @param presentedAccessToken 本次 401 的那个请求用的 access token。
         *   用它判断「别人是不是已经替我换过了」。
         */
        suspend fun refresh(presentedAccessToken: String?): Outcome =
            mutex.withLock {
                // ---- 快速通道：前一个持锁者刚换过 ------------------------------
                val current = sessions.accessToken()
                if (current != null && current != presentedAccessToken) {
                    return@withLock Outcome.Refreshed(current)
                }

                val refreshToken = sessions.refreshToken()
                if (refreshToken == null) {
                    // 会话已经在别处被清掉了（比如另一条 401 判了 token_invalid）。
                    return@withLock Outcome.SessionEnded
                }

                when (
                    val result =
                        mapper.execute {
                            api.refreshToken(
                                xClient = config.clientHeader,
                                tokenRefresh = TokenRefresh(refreshToken),
                                idempotencyKey = idempotencyKeyFor(refreshToken),
                            )
                        }
                ) {
                    is ApiResult.Success -> {
                        sessions.save(result.value)
                        Outcome.Refreshed(result.value.accessToken)
                    }

                    is ApiResult.Failure -> {
                        failure(result.error)
                    }
                }
            }

        /**
         * 刷新失败 → 怎么办。**这张表里只有一行清会话**，那不是疏漏。
         *
         * | 错误 | 处置 | 为什么 |
         * |---|---|---|
         * | `token_invalid` | 清会话 | 已撤销 / 重放 / 账号已删。§6.1：**不要重试** |
         * | 429 `rate_limited` | 保留 | 刷新是 session 60/h，超了不代表会话没了 |
         * | 503 `service_unavailable` | 保留 | 维护窗口 / Vault 故障 |
         * | 409 `idempotency_in_progress` | 保留 | 另一次刷新在飞，下个请求会再试 |
         * | 426 `client_too_old` | 保留 | 强制升级墙是 T-158 的事，不是登出 |
         * | 网络 / 500 / 未知 | 保留 | 离线是常态（§4.3 铁律三：网络失败不回滚 UI） |
         *
         * ⚠️ 把 503 或网络错误压成「登出」，后果是**一次维护窗口把全体用户踢回
         * 登录页**，而登录本身在那个窗口里也是坏的。后端
         * `AuthenticationListener` 对同一件事的措辞是「尤其是 503：把『Vault 挂了』
         * 压成 401 会让全体客户端在一次故障里清空会话、退回登录页」。
         *
         * ⚠️ `token_expired` 也**不**清会话：refresh token 有 90 天，服务端对它
         * 只会回 `token_invalid`（`RefreshTokenService::REJECTED` 是唯一的拒绝文案）。
         * 真要在这里看到它，那是服务端的回归，不是用户该被登出的理由。
         */
        private fun failure(error: ApiError): Outcome =
            when (error) {
                is ApiError.TokenInvalid -> {
                    sessions.clear(SignedOutReason.SessionRevoked)
                    Outcome.SessionEnded
                }

                else -> {
                    // 不打印令牌，也不打印 detail（§6.1：detail 是英文开发者文案）。
                    Timber.d("刷新未成功，保留本机会话：%s", error::class.simpleName)
                    Outcome.Transient
                }
            }

        /**
         * ⚠️⚠️ `Idempotency-Key` 在这个端点上是**安全机制**，不是便利功能。
         *
         * 服务端轮换完、响应在路上丢了，客户端拿着**旧**令牌重试 →
         * 命中 `previous_token_hash` → 会话家族被撤销 + 一封安全警报邮件，
         * 而实际上什么都没发生。契约给这个端点挂 `Idempotency-Key` 就是为了堵住
         * 这条误报路径：中间件会回放此前存下的 200，连同**同一对**新令牌。
         * （`TokenRefreshController` 的类注释逐字写了这段，并点名「客户端（T-150）
         * 必须真的带上它」。）
         *
         * **由 refresh token 本身导出**，不是随手 `UUID.randomUUID()`：
         * 随机 key 只能盖住 `RetryInterceptor` 在同一次调用内的重试。而
         * 「整个调用失败了（连接断了）、下一个请求的 401 又来一次」这条路径上，
         * 随机 key 会换一个新的，于是旧令牌配新 key 打过去 —— 正中重放检测。
         * 导出式的 key 让「同一枚 refresh token 的任何一次重试」都命中同一条
         * 幂等记录（服务端存 24 h，而刷新间隔 ≤ 15 min）。
         *
         * 先过一次 SHA-256 再交给 `nameUUIDFromBytes`（它内部是 MD5）：
         * 这样这枚 key 不是令牌的可逆函数。它本来就和令牌一起明文上线，
         * 但没有理由让它在服务端日志里变成第二份令牌副本。
         *
         * 吐出来的是 **v3** UUID。服务端接受：`Shared\Domain\Identity\Uuid::PATTERN`
         * 只比 8-4-4-4-12 的十六进制形状，不看版本位。
         */
        private fun idempotencyKeyFor(refreshToken: String): UUID {
            val digest =
                MessageDigest
                    .getInstance("SHA-256")
                    .digest("$IDEMPOTENCY_NAMESPACE$refreshToken".toByteArray(Charsets.UTF_8))
            return UUID.nameUUIDFromBytes(digest)
        }

        sealed interface Outcome {
            /** 拿到了一枚可用的 access token（自己换的，或别人刚换好的）。 */
            data class Refreshed(
                val accessToken: String,
            ) : Outcome

            /** 会话确实没了，已清空本机存储。调用方**不要**重试。 */
            data object SessionEnded : Outcome

            /** 这次没换成，但会话还在。放弃本次请求，下一次再说。 */
            data object Transient : Outcome
        }

        private companion object {
            /** 换一个用途就该换一个命名空间，免得两处导出撞到同一个 key。 */
            const val IDEMPOTENCY_NAMESPACE = "ncards-refresh:"
        }
    }
