package de.ncards.feature.onboarding

import app.cash.turbine.test
import de.ncards.core.model.settings.AppLanguage
import de.ncards.core.network.api.model.OtpRequest
import de.ncards.core.network.impl.error.ApiError
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.test.TestScope
import kotlinx.coroutines.test.advanceTimeBy
import kotlinx.coroutines.test.advanceUntilIdle
import kotlinx.coroutines.test.runCurrent
import kotlinx.coroutines.test.runTest
import org.junit.jupiter.api.Assertions.assertEquals
import org.junit.jupiter.api.Assertions.assertInstanceOf
import org.junit.jupiter.api.Assertions.assertTrue
import org.junit.jupiter.api.DisplayName
import org.junit.jupiter.api.Test
import org.junit.jupiter.api.extension.RegisterExtension
import kotlin.time.Duration.Companion.seconds

@OptIn(ExperimentalCoroutinesApi::class)
@DisplayName("OnboardingViewModel")
class OnboardingViewModelTest {
    @JvmField
    @RegisterExtension
    val mainDispatcher = MainDispatcherExtension()

    private val auth = FakeAuthRepository()
    private val languages = FakeAppLanguageStore()

    private fun viewModel() = OnboardingViewModel(auth, languages)

    // ---- 第一屏：语言 ---------------------------------------------------

    @Test
    @DisplayName("选语言：写进状态，也写进 AppLanguageStore")
    fun languageSelectionReachesTheStore() =
        runTest {
            val viewModel = viewModel()

            viewModel.onLanguageSelected(AppLanguage.ENGLISH)

            assertEquals(AppLanguage.ENGLISH, viewModel.state.value.language)
            assertEquals(listOf(AppLanguage.ENGLISH), languages.selections)
        }

    /** §11.1：验证码邮件的语言由 `POST /auth/otp/request` 的 `locale` 决定。 */
    @Test
    @DisplayName("选了英语之后，请求码时带的 locale 是 en")
    fun selectedLanguageDrivesTheEmailLocale() =
        runTest {
            val viewModel = viewModel()
            viewModel.onLanguageSelected(AppLanguage.ENGLISH)
            viewModel.onEmailChanged("anna@example.de")

            viewModel.requestCode()
            advanceUntilIdle()

            assertEquals(OtpRequest.Locale.en, auth.requestedOtps.single().second)
        }

    // ---- 第二屏：邮箱 ---------------------------------------------------

    /**
     * ⚠️ 本地预校验挡下的请求**不能**发出去，否则每一次手滑都要烧掉
     * §7.5 那三个窗口里的一格（每邮箱 1/min、5/h、10/day）。
     */
    @Test
    @DisplayName("看着不像邮箱：就地报错，一个请求都不发")
    fun invalidEmailNeverReachesTheNetwork() =
        runTest {
            val viewModel = viewModel()
            viewModel.onEmailChanged("nicht-eine-adresse")

            viewModel.requestCode()
            advanceUntilIdle()

            assertEquals(
                OnboardingProgress.Failed(OnboardingError.EmailLooksInvalid),
                viewModel.state.value.progress,
            )
            assertTrue(auth.requestedOtps.isEmpty())
        }

    @Test
    @DisplayName("202 到手：记下 challenge，倒计时取自服务端，并发出 CodeSent")
    fun successfulRequestMovesToTheCodeStep() =
        runTest {
            // ⚠️ 故意不是 60：写死 60 的实现会在这条用例上绿，改成 90 才看得出来。
            auth.requestOtpResult = FakeAuthRepository.success(FakeAuthRepository.challenge(resendAfterSeconds = 90))
            val viewModel = viewModel()
            viewModel.onEmailChanged("anna@example.de")

            viewModel.events.test {
                viewModel.requestCode()
                // ⚠️ `runCurrent()` 而不是 `advanceUntilIdle()`：后者会把虚拟时间
                // 一路推到没有待办任务为止，也就是把 90 秒的倒计时整个跑完 ——
                // 于是这条断言永远看到 0，而写死 60 的实现也能绿。
                runCurrent()

                assertEquals(OnboardingEvent.CodeSent, awaitItem())
            }

            assertEquals(FakeAuthRepository.CHALLENGE_ID, viewModel.state.value.challengeId)
            assertEquals(90, viewModel.state.value.resendInSeconds)
        }

    /**
     * ⚠️ §7.5 的三个窗口挡的是「用 N-Cards 的域名给别人的收件箱发信」。
     * 自动重试等于替攻击者按住按钮 —— 所以 429 之后**一个**请求都不能再发。
     */
    @Test
    @DisplayName("429：显示退避时间，不自动重试")
    fun rateLimitedDoesNotRetry() =
        runTest {
            auth.requestOtpResult =
                FakeAuthRepository.failure(
                    ApiError.RateLimited(retryAfter = 42.seconds, remaining = 0, requestId = "req-1"),
                )
            val viewModel = viewModel()
            viewModel.onEmailChanged("anna@example.de")

            viewModel.requestCode()
            advanceUntilIdle()

            val progress = assertInstanceOf(OnboardingProgress.Failed::class.java, viewModel.state.value.progress)
            assertEquals(OnboardingError.TooManyRequests(retryAfterSeconds = 42), progress.error)
            assertEquals(1, auth.requestedOtps.size)
        }

    // ---- 第三屏：6 位码 -------------------------------------------------

    @Test
    @DisplayName("重发倒计时按秒走到 0")
    fun resendCountdownTicksDown() =
        runTest {
            val viewModel = givenChallengeIssued()

            assertEquals(FakeAuthRepository.RESEND_AFTER_SECONDS, viewModel.state.value.resendInSeconds)

            // ⚠️ `advanceTimeBy` 只跑**严格早于**目标时刻的任务，所以补一次
            // `runCurrent()` 把恰好落在第 10 秒上的那一跳也跑掉。不补的话这里是 51，
            // 一个看起来像 off-by-one 的假失败。
            advanceTimeBy(10.seconds)
            runCurrent()
            assertEquals(FakeAuthRepository.RESEND_AFTER_SECONDS - 10, viewModel.state.value.resendInSeconds)

            advanceUntilIdle()
            assertEquals(0, viewModel.state.value.resendInSeconds)
        }

    /** 抢在倒计时之前重发只会换来一个 429（§7.5：每邮箱 1/min）。 */
    @Test
    @DisplayName("倒计时没走完时，重发什么都不做")
    fun resendIsIgnoredWhileTheCountdownRuns() =
        runTest {
            val viewModel = givenChallengeIssued()

            viewModel.resendCode()
            runCurrent()

            assertEquals(1, auth.requestedOtps.size)
        }

    @Test
    @DisplayName("倒计时归零后可以重发，且重发不再导航")
    fun resendWorksAfterTheCountdown() =
        runTest {
            val viewModel = givenChallengeIssued()
            advanceUntilIdle()

            viewModel.events.test {
                // 第一次请求码时发出的 CodeSent 还躺在 Channel 的缓冲里。
                skipItems(1)

                viewModel.resendCode()
                runCurrent()

                assertEquals(2, auth.requestedOtps.size)
                // 重发留在本屏 —— 再发一次 CodeSent 会把用户「导航」到他已经在的那一页。
                expectNoEvents()
            }
        }

    @Test
    @DisplayName("只收数字，最多 6 位")
    fun codeInputAcceptsSixDigitsOnly() =
        runTest {
            val viewModel = viewModel()

            viewModel.onCodeChanged("41a8-39 6123")

            assertEquals("418396", viewModel.state.value.codeInput)
            assertTrue(viewModel.state.value.codeComplete)
        }

    /**
     * ⚠️ 五种拒绝形状（码错 / 过期 / 已消费 / 次数耗尽 / Magic Link 已被用掉）
     * 在契约里是**逐字相同**的 401（§6.3.1 注 3）。客户端问不出是哪一种，
     * 所以只能有一格 [OnboardingError.CodeRejected]。
     */
    @Test
    @DisplayName("401：一句通用错误，并把输入框清空")
    fun rejectedCodeClearsTheInput() =
        runTest {
            auth.verifyOtpResult = FakeAuthRepository.failure(ApiError.TokenInvalid("req-1"))
            val viewModel = givenChallengeIssued()
            viewModel.onCodeChanged("000000")

            viewModel.verifyCode()
            advanceUntilIdle()

            assertEquals(OnboardingProgress.Failed(OnboardingError.CodeRejected), viewModel.state.value.progress)
            assertEquals("", viewModel.state.value.codeInput)
        }

    /**
     * §6.3.1 注 2：首次验证成功即注册，此时 `username` 仍是 null ——
     * 用户必须被送到 username 设定页，而不是钱包。
     */
    @Test
    @DisplayName("验证成功但 onboarding 未完成 → UsernameRequired")
    fun firstTimeUserGoesToTheUsernamePage() =
        runTest {
            auth.verifyOtpResult =
                FakeAuthRepository.success(FakeAuthRepository.user(username = null, onboardingComplete = false))
            val viewModel = givenChallengeIssued()
            viewModel.onCodeChanged("418396")

            viewModel.events.test {
                skipItems(1) // 请求码时发出的 CodeSent。

                viewModel.verifyCode()
                runCurrent()

                assertEquals(OnboardingEvent.UsernameRequired, awaitItem())
            }
            assertEquals(FakeAuthRepository.CHALLENGE_ID to "418396", auth.verifiedCodes.single())
        }

    /** §6.3.1 注 2：「已注册用户的后续登录**不经过** username 步骤」。 */
    @Test
    @DisplayName("验证成功且 onboarding 已完成 → SignedIn")
    fun returningUserGoesStraightToTheWallet() =
        runTest {
            auth.verifyOtpResult = FakeAuthRepository.success(FakeAuthRepository.user(onboardingComplete = true))
            val viewModel = givenChallengeIssued()
            viewModel.onCodeChanged("418396")

            viewModel.events.test {
                skipItems(1) // 请求码时发出的 CodeSent。

                viewModel.verifyCode()
                runCurrent()

                assertEquals(OnboardingEvent.SignedIn, awaitItem())
            }
        }

    @Test
    @DisplayName("码没输满时不发请求")
    fun incompleteCodeIsNotSubmitted() =
        runTest {
            val viewModel = givenChallengeIssued()
            viewModel.onCodeChanged("418")

            viewModel.verifyCode()
            advanceUntilIdle()

            assertTrue(auth.verifiedCodes.isEmpty())
        }

    /**
     * 走到「码已发出」这一步。
     *
     * ⚠️ 收尾用 `runCurrent()` 而不是 `advanceUntilIdle()`：后者会把倒计时整个跑完，
     * 于是每一条依赖「倒计时还在走」的用例都会静默地测了个别的东西。
     */
    private fun TestScope.givenChallengeIssued(): OnboardingViewModel {
        val viewModel = viewModel()
        viewModel.onEmailChanged("anna@example.de")
        viewModel.requestCode()
        runCurrent()
        return viewModel
    }
}
