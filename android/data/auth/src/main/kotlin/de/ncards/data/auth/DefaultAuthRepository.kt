package de.ncards.data.auth

import de.ncards.core.model.user.UsernameRules
import de.ncards.core.network.api.AuthApi
import de.ncards.core.network.api.MeApi
import de.ncards.core.network.api.model.MagicLinkConsumption
import de.ncards.core.network.api.model.OtpChallenge
import de.ncards.core.network.api.model.OtpRequest
import de.ncards.core.network.api.model.OtpVerification
import de.ncards.core.network.api.model.Session
import de.ncards.core.network.api.model.User
import de.ncards.core.network.api.model.UserEnvelope
import de.ncards.core.network.api.model.UsernameAssignment
import de.ncards.core.network.impl.ApiResult
import de.ncards.core.network.impl.NetworkConfig
import de.ncards.core.network.impl.error.ApiErrorMapper
import de.ncards.core.network.impl.execute
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.withContext
import java.security.MessageDigest
import java.util.UUID
import javax.inject.Inject
import javax.inject.Singleton

/**
 * [AuthRepository] 的实现。薄到只有「调用、持久化、转形」三步。
 *
 * 三处值得一说的地方：
 *
 * - **`xClient` 一律传 [NetworkConfig.clientHeader]。** 生成的每个方法都带一个
 *   必填的 `xClient` 参数，那是契约逐操作声明 `X-Client` 的必然产物，不是第二个
 *   开关 —— 真正发出去的值由 `ClientHeaderInterceptor` 覆盖决定。
 * - **`Idempotency-Key` 在这几个端点上不传。** 它们都不是「重试可能造成重复
 *   副作用」的形状：OTP 请求恒 202，verify / consume 一次性消费掉挑战，
 *   logout 幂等。唯一真的需要它的是刷新，那在 [RefreshGate] 里，
 *   而那里它是**安全机制**不是便利功能。
 * - **[Session] 在这里落地，不往外走**（见 [AuthRepository] 的不变量）。
 */
@Singleton
internal class DefaultAuthRepository
    @Inject
    constructor(
        private val authApi: AuthApi,
        private val meApi: MeApi,
        private val sessions: SessionStore,
        private val devices: DeviceDescriptorProvider,
        private val mapper: ApiErrorMapper,
        private val config: NetworkConfig,
    ) : AuthRepository {
        override val sessionState: StateFlow<SessionState> get() = sessions.state

        /**
         * ⚠️ 用 `Dispatchers.IO` 而不是 `core:common` 的 `DispatcherProvider` ——
         * 那个模块今天还是空壳。第一个真正需要注入调度器的测试出现时
         * （多半是 T-250 的同步引擎），该把它建起来并把这里换过去。
         * 本方法的用例不需要控制调度器：它只是把一次阻塞读挪出主线程。
         */
        override suspend fun restoreSession() {
            withContext(Dispatchers.IO) { sessions.refreshToken() }
        }

        override suspend fun requestOtp(
            email: String,
            locale: OtpRequest.Locale,
        ): ApiResult<OtpChallenge> =
            mapper.execute {
                authApi.requestOtp(
                    xClient = config.clientHeader,
                    // 归一化（trim + 小写）由服务端做 —— 它算的是
                    // HMAC-SHA256(lower(trim(email)), pepper)。客户端再做一遍
                    // 只会制造第二个必须与服务端逐字一致的实现。
                    otpRequest = OtpRequest(email = email, locale = locale),
                )
            }

        override suspend fun verifyOtp(
            challengeId: UUID,
            code: String,
        ): ApiResult<User> =
            persisting {
                authApi.verifyOtp(
                    xClient = config.clientHeader,
                    otpVerification =
                        OtpVerification(
                            challengeId = challengeId,
                            code = code,
                            device = devices.current(),
                        ),
                )
            }

        override suspend fun consumeMagicLink(token: String): ApiResult<User> =
            persisting {
                authApi.consumeMagicLink(
                    xClient = config.clientHeader,
                    magicLinkConsumption =
                        MagicLinkConsumption(
                            token = token,
                            device = devices.current(),
                        ),
                )
            }

        override suspend fun fetchMe(): ApiResult<User> = unwrappingUser { meApi.getMe(config.clientHeader) }

        override suspend fun setUsername(username: String): ApiResult<User> {
            val normalized = UsernameRules.normalize(username)
            return unwrappingUser {
                meApi.setUsername(
                    xClient = config.clientHeader,
                    usernameAssignment = UsernameAssignment(normalized),
                    idempotencyKey = idempotencyKeyFor(normalized),
                )
            }
        }

        /**
         * ⚠️ 本机会话**无条件**清掉，不看服务端那一半的结果。
         *
         * 服务端不可达时不让用户登出，是把一个网络问题变成一个产品问题：
         * 用户在地铁里点登出，看到「失败，请重试」，而他想做的事（把这台设备上的
         * 卡藏起来）完全是本地的。服务端那条会话最坏也就是活到 90 天后过期，
         * 而用户可以从别的设备用设备管理页撤销它。
         */
        override suspend fun logout(): ApiResult<Unit> {
            val result = mapper.execute { authApi.logout(config.clientHeader) }
            sessions.clear(SignedOutReason.UserAction)
            return result
        }

        /**
         * verify 与 consume 共用的那三步：调用 → 存令牌 → 只交出 [User]。
         *
         * 抽出来不是为了省行数，是为了让「持久化」这一步不可能被漏掉 ——
         * 两个端点都返回同一个 `Session` schema，而漏掉的那一侧会表现为
         * 「登录成功了但下一个请求 401」。
         */
        private suspend fun persisting(call: suspend () -> retrofit2.Response<Session>): ApiResult<User> =
            when (val result = mapper.execute(call)) {
                is ApiResult.Success -> {
                    sessions.save(result.value)
                    ApiResult.Success(
                        value = result.value.user,
                        requestId = result.requestId,
                        idempotencyReplayed = result.idempotencyReplayed,
                    )
                }

                is ApiResult.Failure -> {
                    result
                }
            }

        /**
         * `GET /me` 与 `POST /me/username` 共用的拆包：契约里两者返回的是**同一个**
         * `UserEnvelope`（那个 schema 的注释逐字解释了它为什么必须是具名的）。
         */
        private suspend fun unwrappingUser(call: suspend () -> retrofit2.Response<UserEnvelope>): ApiResult<User> =
            when (val result = mapper.execute(call)) {
                is ApiResult.Success -> {
                    ApiResult.Success(
                        value = result.value.user,
                        requestId = result.requestId,
                        idempotencyReplayed = result.idempotencyReplayed,
                    )
                }

                is ApiResult.Failure -> {
                    result
                }
            }

        /**
         * ⚠️ `Idempotency-Key` 在这个端点上守的是**用户的账号**，不是便利。
         *
         * §7.5 给 `POST /me/username` 的配额是按 user **10 次总计的生命周期计数**
         * （`users.username_attempts`，永不恢复）。用尽 = 这个账号永远完成不了
         * onboarding，而 T-108 的拦截器连注销路径都挡着 —— 没有任何自助出路
         * （ADR-0017）。
         *
         * 于是「服务端已经把名字记上了、响应在回来的路上丢了、用户再按一次确认」
         * 这条完全正常的路径，不带 key 就会白烧掉一次。带上导出式的 key，
         * 中间件回放此前那次的响应，计数不动。
         *
         * ⚠️ **由归一化后的 username 导出，不是 `UUID.randomUUID()`。** 随机 key
         * 每次都不同，回放永远命中不了 —— 那就等于没带。理由与 [RefreshGate]
         * 里那一处完全同构，两处的取舍要一起读。
         *
         * 换个名字就换个 key，所以不会撞上 ADR-0003 的
         * 「同 key 异体 → `422 idempotency_key_reused`」。
         *
         * 先过一次 SHA-256 再交给 `nameUUIDFromBytes`（内部是 MD5），
         * 这样这枚 key 不是 username 的可逆函数 —— username 是伪名不是秘密，
         * 但没有理由让它在服务端日志里多一份副本。吐出来的是 v3 UUID，
         * 服务端的 `Uuid::PATTERN` 只比形状、不看版本位。
         */
        private fun idempotencyKeyFor(normalizedUsername: String): UUID {
            val digest =
                MessageDigest
                    .getInstance("SHA-256")
                    .digest("$IDEMPOTENCY_NAMESPACE$normalizedUsername".toByteArray(Charsets.UTF_8))
            return UUID.nameUUIDFromBytes(digest)
        }

        private companion object {
            /** 换一个用途就该换一个命名空间，免得两处导出撞到同一个 key。 */
            const val IDEMPOTENCY_NAMESPACE = "ncards-username:"
        }
    }
