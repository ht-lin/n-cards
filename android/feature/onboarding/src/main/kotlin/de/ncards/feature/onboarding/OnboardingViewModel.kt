package de.ncards.feature.onboarding

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import dagger.hilt.android.lifecycle.HiltViewModel
import de.ncards.core.model.settings.AppLanguage
import de.ncards.core.model.settings.AppLanguageStore
import de.ncards.core.network.api.model.OtpRequest
import de.ncards.core.network.impl.ApiResult
import de.ncards.core.network.impl.error.ApiError
import de.ncards.data.auth.AuthRepository
import kotlinx.coroutines.Job
import kotlinx.coroutines.channels.Channel
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.receiveAsFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch
import javax.inject.Inject
import kotlin.time.Duration.Companion.seconds

/**
 * 注册流程前半段的 ViewModel：语言/隐私说明 → 邮箱 → 6 位码（§1.4 J1）。
 *
 * ============================================================================
 * 它 scoped 到**嵌套图**，不是单屏
 * ============================================================================
 * 三屏共享同一份输入（语言、邮箱、挑战 id、重发倒计时）。挂在
 * `OnboardingGraph` 的 `NavBackStackEntry` 上，用户从 6 位码那一屏退回邮箱页
 * 再前进时，倒计时不会重来 —— 而重来意味着他要多等 60 秒，服务端那边
 * 挑战却还活着。
 *
 * ⚠️ username 设定页**不**共用它：那一页是顶层目的地（冷启动可以直接落在上面），
 * 有自己的 [de.ncards.feature.onboarding.username.UsernameViewModel]。
 *
 * ============================================================================
 * §7.3 的不变量在这里是免费的
 * ============================================================================
 * `AuthRepository` 的 `verifyOtp` / `consumeMagicLink` 返回的是 `User` 而不是
 * `Session` —— 令牌在类型层面就到不了这里，所以不可能被写进 `SavedStateHandle`
 * 或者打进日志。别想办法把它们要出来。
 */
@HiltViewModel
class OnboardingViewModel
    @Inject
    constructor(
        private val auth: AuthRepository,
        private val languages: AppLanguageStore,
    ) : ViewModel() {
        private val _state = MutableStateFlow(OnboardingUiState(language = languages.current()))
        val state: StateFlow<OnboardingUiState> = _state.asStateFlow()

        /**
         * §10.4：一次性事件走 `Channel` + `receiveAsFlow()`，**不放进** `StateFlow`。
         *
         * 放进状态里的后果是它会在每次重组/旋转屏幕时重放 —— 「导航到 6 位码页」
         * 重放一次没事，「登录成功」重放一次就是把用户从钱包里再踢一遍。
         */
        private val eventChannel = Channel<OnboardingEvent>(Channel.BUFFERED)
        val events: Flow<OnboardingEvent> = eventChannel.receiveAsFlow()

        private var resendCountdown: Job? = null

        // ---- 第一屏：语言 ---------------------------------------------------

        /**
         * ⚠️ 切换语言会重建 Activity（见 [AppLanguageStore.select]）。
         * 这一屏上用户还什么都没输入，所以丢掉的界面状态恰好是零 ——
         * 这也是为什么语言开关只出现在这里和（将来的）设置页。
         */
        fun onLanguageSelected(language: AppLanguage) {
            _state.update { it.copy(language = language) }
            languages.select(language)
        }

        // ---- 第二屏：邮箱 ---------------------------------------------------

        fun onEmailChanged(value: String) {
            _state.update {
                it.copy(emailInput = value.take(OnboardingUiState.MAX_EMAIL_LENGTH), progress = OnboardingProgress.Idle)
            }
        }

        /**
         * `POST /auth/otp/request`。**恒 202**，即使这个邮箱从没注册过 ——
         * 服务端在这条路径上根本不查 `users`（ADR-0014）。
         *
         * 推论：UI 上**没有**「登录」与「注册」两个入口，也没有「该邮箱未注册」
         * 这种状态。对客户端来说两件事是同一条流程。
         */
        fun requestCode() {
            val current = _state.value
            if (!current.emailLooksValid) {
                _state.update { it.copy(progress = OnboardingProgress.Failed(OnboardingError.EmailLooksInvalid)) }
                return
            }
            sendCode(navigateOnSuccess = true)
        }

        // ---- 第三屏：6 位码 -------------------------------------------------

        fun onCodeChanged(value: String) {
            val digits = value.filter(Char::isDigit).take(OnboardingUiState.CODE_LENGTH)
            _state.update { it.copy(codeInput = digits, progress = OnboardingProgress.Idle) }
        }

        /**
         * 重发。倒计时没走完就什么都不做 —— §7.5 给这个端点的第一个窗口是
         * 每邮箱 **1/min**，抢跑只会换来一个 429。
         */
        fun resendCode() {
            if (_state.value.resendInSeconds > 0) return
            _state.update { it.copy(codeInput = "") }
            sendCode(navigateOnSuccess = false)
        }

        /** `POST /auth/otp/verify`。成功即登录；首次成功即注册（§6.3.1 注 2）。 */
        fun verifyCode() {
            val current = _state.value
            val challengeId = current.challengeId ?: return
            if (!current.codeComplete || current.progress == OnboardingProgress.Busy) return

            viewModelScope.launch {
                _state.update { it.copy(progress = OnboardingProgress.Busy) }

                when (val result = auth.verifyOtp(challengeId, current.codeInput)) {
                    is ApiResult.Success -> {
                        _state.update { it.copy(progress = OnboardingProgress.Idle) }
                        eventChannel.send(
                            // ⚠️ onboarding 状态只看这个字段。不要从 access token 里读 ——
                            // §7.1 把 claim 集钉成了恰好 sub/sid/did/jti/iat/exp，
                            // ADR-0018 明确拒绝了加一个 `onb` claim。
                            if (result.value.onboardingComplete) {
                                OnboardingEvent.SignedIn
                            } else {
                                OnboardingEvent.UsernameRequired
                            },
                        )
                    }

                    is ApiResult.Failure -> {
                        _state.update {
                            it.copy(
                                // 码被拒之后把输入框清空：用户要做的是重输六个数字，
                                // 而不是在一串已经错了的数字里找哪一位错了。
                                codeInput = "",
                                progress = OnboardingProgress.Failed(result.error.toOnboardingError()),
                            )
                        }
                    }
                }
            }
        }

        // ---- 共用 -----------------------------------------------------------

        private fun sendCode(navigateOnSuccess: Boolean) {
            if (_state.value.progress == OnboardingProgress.Busy) return

            viewModelScope.launch {
                _state.update { it.copy(progress = OnboardingProgress.Busy) }

                when (
                    val result = auth.requestOtp(
                        _state.value.emailInput.trim(),
                        _state.value.language.toContractLocale(),
                    )
                ) {
                    is ApiResult.Success -> {
                        _state.update {
                            it.copy(
                                challengeId = result.value.challengeId,
                                progress = OnboardingProgress.Idle,
                            )
                        }
                        startResendCountdown(result.value.resendAfterSeconds)
                        if (navigateOnSuccess) {
                            eventChannel.send(OnboardingEvent.CodeSent)
                        }
                    }

                    is ApiResult.Failure -> {
                        _state.update {
                            it.copy(
                                progress = OnboardingProgress.Failed(result.error.toOnboardingError()),
                            )
                        }
                    }
                }
            }
        }

        /**
         * 秒数由服务端下发（`OtpChallenge.resendAfterSeconds`，§7.1 是 60）。
         * **不写死** —— 改这个间隔不该要求发一版客户端。
         */
        private fun startResendCountdown(seconds: Int) {
            resendCountdown?.cancel()
            _state.update { it.copy(resendInSeconds = seconds.coerceAtLeast(0)) }
            if (seconds <= 0) return

            resendCountdown =
                viewModelScope.launch {
                    var remaining = seconds
                    while (remaining > 0) {
                        delay(1.seconds)
                        remaining -= 1
                        _state.update { it.copy(resendInSeconds = remaining) }
                    }
                }
        }
    }

/** 语言 → 契约的 `locale` 枚举。值域两边都恰好是 `de` / `en`（§11.1）。 */
internal fun AppLanguage.toContractLocale(): OtpRequest.Locale =
    when (this) {
        AppLanguage.GERMAN -> OtpRequest.Locale.de
        AppLanguage.ENGLISH -> OtpRequest.Locale.en
    }

/**
 * §6.1 的错误码 → 用户看得到的那一句话。
 *
 * ⚠️ 这**不是**一一映射，也不该是。`token_invalid` 在这条流程上只可能是
 * 「这个码不行」—— 服务端刻意让码错 / 过期 / 已消费 / 次数耗尽 / Magic Link
 * 已被用掉五种情形返回**逐字相同**的 401（§6.3.1 注 3 / ADR-0014），
 * 编出来的区分只能是猜的。
 *
 * 剩下那一长串「客户端 bug，上报 Sentry」的码合并成 [OnboardingError.Unexpected]：
 * 它们对用户是同一件事（这一步现在做不了），区别只在我们要不要看日志。
 */
internal fun ApiError.toOnboardingError(): OnboardingError =
    when (this) {
        is ApiError.TokenInvalid, is ApiError.TokenExpired -> OnboardingError.CodeRejected
        is ApiError.NotFound -> OnboardingError.CodeRejected
        is ApiError.RateLimited -> OnboardingError.TooManyRequests(retryAfter?.inWholeSeconds)
        is ApiError.Network -> OnboardingError.Offline
        is ApiError.ServiceUnavailable -> OnboardingError.ServiceUnavailable
        is ApiError.ClientTooOld -> OnboardingError.ClientTooOld
        else -> OnboardingError.Unexpected
    }

/**
 * 一次性事件。三格，全都是「NavHost 该去哪」。
 */
sealed interface OnboardingEvent {
    /** 202 到手，挑战建好了 → 去 6 位码那一屏。 */
    data object CodeSent : OnboardingEvent

    /** 登录成功且 `onboarding_complete == true` → 钱包。 */
    data object SignedIn : OnboardingEvent

    /** 登录成功但 `username IS NULL` → username 设定页，**不可跳过**（§5.2）。 */
    data object UsernameRequired : OnboardingEvent
}
