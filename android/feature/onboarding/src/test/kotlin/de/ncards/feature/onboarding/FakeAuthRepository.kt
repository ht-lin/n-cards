package de.ncards.feature.onboarding

import de.ncards.core.network.api.model.OtpChallenge
import de.ncards.core.network.api.model.OtpRequest
import de.ncards.core.network.api.model.User
import de.ncards.core.network.impl.ApiResult
import de.ncards.core.network.impl.error.ApiError
import de.ncards.data.auth.AuthRepository
import de.ncards.data.auth.SessionState
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import java.time.OffsetDateTime
import java.util.UUID

/**
 * [AuthRepository] 的测试替身。
 *
 * ⚠️ 为什么不用 MockK：这几个用例关心的是**调用了几次、带了什么参数**，
 * 而 `data:auth` 的真实行为（令牌落存储、Idempotency-Key 怎么导出）
 * 已经由 `DefaultAuthRepositoryTest` 在真 `MockWebServer` 上验过了。
 * 在这里再 mock 一遍只会把那些断言抄第二份。
 *
 * 每个方法的返回值由一个可写的 `…Result` 字段决定，调用次数记在 `…Calls` 里。
 */
internal class FakeAuthRepository : AuthRepository {
    private val _sessionState = MutableStateFlow<SessionState>(SessionState.Unknown)
    override val sessionState: StateFlow<SessionState> = _sessionState

    var requestOtpResult: ApiResult<OtpChallenge> = success(challenge())
    var verifyOtpResult: ApiResult<User> = success(user())
    var consumeMagicLinkResult: ApiResult<User> = success(user())
    var fetchMeResult: ApiResult<User> = success(user())
    var setUsernameResult: ApiResult<User> = success(user())

    val requestedOtps = mutableListOf<Pair<String, OtpRequest.Locale>>()
    val verifiedCodes = mutableListOf<Pair<UUID, String>>()
    val assignedUsernames = mutableListOf<String>()
    var fetchMeCalls = 0
        private set

    override suspend fun restoreSession() {
        _sessionState.value = SessionState.SignedOut(de.ncards.data.auth.SignedOutReason.NeverSignedIn)
    }

    override suspend fun requestOtp(
        email: String,
        locale: OtpRequest.Locale,
    ): ApiResult<OtpChallenge> {
        requestedOtps += email to locale
        return requestOtpResult
    }

    override suspend fun verifyOtp(
        challengeId: UUID,
        code: String,
    ): ApiResult<User> {
        verifiedCodes += challengeId to code
        return verifyOtpResult
    }

    override suspend fun consumeMagicLink(token: String): ApiResult<User> = consumeMagicLinkResult

    override suspend fun fetchMe(): ApiResult<User> {
        fetchMeCalls += 1
        return fetchMeResult
    }

    override suspend fun setUsername(username: String): ApiResult<User> {
        assignedUsernames += username
        return setUsernameResult
    }

    override suspend fun logout(): ApiResult<Unit> = success(Unit)

    internal companion object {
        val CHALLENGE_ID: UUID = UUID.fromString("0192f3a1-b2c3-7d4e-8f01-23456789abcd")

        /** §7.1：重发间隔 60 秒。用例照服务端下发的值断言，不照写死的 60。 */
        const val RESEND_AFTER_SECONDS = 60

        fun <T> success(value: T): ApiResult<T> =
            ApiResult.Success(value = value, requestId = "req-1", idempotencyReplayed = false)

        fun failure(error: ApiError): ApiResult<Nothing> = ApiResult.Failure(error)

        fun challenge(resendAfterSeconds: Int = RESEND_AFTER_SECONDS): OtpChallenge =
            OtpChallenge(
                challengeId = CHALLENGE_ID,
                expiresAt = OffsetDateTime.parse("2026-08-29T10:40:12Z"),
                resendAfterSeconds = resendAfterSeconds,
            )

        fun user(
            username: String? = "anna_b",
            onboardingComplete: Boolean = true,
        ): User =
            User(
                id = UUID.fromString("0192f3a1-b2c3-7d4e-8f01-00000000a11a"),
                username = username,
                locale = User.Locale.de,
                onboardingComplete = onboardingComplete,
                createdAt = OffsetDateTime.parse("2026-08-29T10:30:12Z"),
            )
    }
}
