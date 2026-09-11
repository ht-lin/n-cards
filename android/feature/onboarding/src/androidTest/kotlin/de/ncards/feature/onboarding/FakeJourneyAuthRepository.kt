package de.ncards.feature.onboarding

import de.ncards.core.network.api.model.OtpChallenge
import de.ncards.core.network.api.model.OtpRequest
import de.ncards.core.network.api.model.User
import de.ncards.core.network.impl.ApiResult
import de.ncards.core.network.impl.error.ApiError
import de.ncards.data.auth.AuthRepository
import de.ncards.data.auth.SessionState
import de.ncards.data.auth.SignedOutReason
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import java.time.OffsetDateTime
import java.util.UUID

/**
 * [AuthRepository] 的替身，供 Compose UI Test 用。
 *
 * ⚠️ 它是 `src/test` 下那个 `FakeAuthRepository` 的第二份 —— **Gradle 的
 * `androidTest` 源集看不见 `test` 源集**，没有第三条路（除非建一个共享源集，
 * 而那要改 convention plugin）。两份都很小，重复是有意接受的。
 * 第三个模块需要它时该提升到 `:core:testing`，与 T-150 对 `FakeSecretStore`
 * 的处置同一条。
 *
 * ⚠️ [OtpChallenge.resendAfterSeconds] 默认给 **0**：真实的 60 会让 ViewModel 起一个
 * 每秒 tick 一次的协程，而它跑在真的主线程上 —— `waitForIdle()` 会跟着它
 * 转一分钟。倒计时本身由 `OnboardingViewModelTest` 在虚拟时间上验。
 */
internal class FakeJourneyAuthRepository : AuthRepository {
    private val _sessionState = MutableStateFlow<SessionState>(SessionState.SignedOut(SignedOutReason.NeverSignedIn))
    override val sessionState: StateFlow<SessionState> = _sessionState

    /** 首次验证成功即注册，此时 `username` 还是 null（§6.3.1 注 2）。 */
    var verifyOtpResult: ApiResult<User> = success(user(username = null, onboardingComplete = false))
    var setUsernameResult: ApiResult<User> = success(user())

    override suspend fun restoreSession() = Unit

    override suspend fun requestOtp(
        email: String,
        locale: OtpRequest.Locale,
    ): ApiResult<OtpChallenge> =
        success(
            OtpChallenge(
                challengeId = CHALLENGE_ID,
                expiresAt = OffsetDateTime.parse("2026-08-29T10:40:12Z"),
                resendAfterSeconds = 0,
            ),
        )

    override suspend fun verifyOtp(
        challengeId: UUID,
        code: String,
    ): ApiResult<User> = verifyOtpResult

    override suspend fun consumeMagicLink(token: String): ApiResult<User> = verifyOtpResult

    override suspend fun fetchMe(): ApiResult<User> = success(user())

    override suspend fun setUsername(username: String): ApiResult<User> = setUsernameResult

    override suspend fun logout(): ApiResult<Unit> = success(Unit)

    internal companion object {
        val CHALLENGE_ID: UUID = UUID.fromString("0192f3a1-b2c3-7d4e-8f01-23456789abcd")

        fun <T> success(value: T): ApiResult<T> =
            ApiResult.Success(value = value, requestId = "req-1", idempotencyReplayed = false)

        fun failure(error: ApiError): ApiResult<Nothing> = ApiResult.Failure(error)

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

/** [de.ncards.core.model.settings.AppLanguageStore] 的替身：记下选择，不真的重建 Activity。 */
internal class FakeJourneyLanguageStore : de.ncards.core.model.settings.AppLanguageStore {
    private var language = de.ncards.core.model.settings.AppLanguage.GERMAN

    override fun current() = language

    override fun select(language: de.ncards.core.model.settings.AppLanguage) {
        this.language = language
    }
}
