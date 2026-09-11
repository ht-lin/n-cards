package de.ncards.feature.onboarding

import androidx.activity.ComponentActivity
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.test.assertIsDisplayed
import androidx.compose.ui.test.assertIsNotEnabled
import androidx.compose.ui.test.assertTextEquals
import androidx.compose.ui.test.junit4.createAndroidComposeRule
import androidx.compose.ui.test.onNodeWithTag
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performTextInput
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.test.ext.junit.runners.AndroidJUnit4
import androidx.test.platform.app.InstrumentationRegistry
import de.ncards.core.designsystem.theme.NcardsTheme
import de.ncards.core.network.impl.error.ApiError
import de.ncards.feature.onboarding.username.DIALOG_CONFIRM_TAG
import de.ncards.feature.onboarding.username.NORMALIZED_PREVIEW_TAG
import de.ncards.feature.onboarding.username.SERVER_ERROR_TAG
import de.ncards.feature.onboarding.username.SUBMIT_BUTTON_TAG
import de.ncards.feature.onboarding.username.USERNAME_FIELD_TAG
import de.ncards.feature.onboarding.username.UsernameEvent
import de.ncards.feature.onboarding.username.UsernameScreen
import de.ncards.feature.onboarding.username.UsernameUiState
import de.ncards.feature.onboarding.username.UsernameViewModel
import org.junit.Assert.assertTrue
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith

/**
 * §13.4 / T-151 验收标准：**Compose UI Test 覆盖 J1 旅程**，
 * 且 `username_taken` / `username_invalid` 各有对应的本地化错误提示。
 *
 * ============================================================================
 * 为什么不用 Hilt
 * ============================================================================
 * 这里手工 `new` 出两个 ViewModel 并把 [FakeJourneyAuthRepository] 塞进去。
 * 引 `hilt-android-testing` 需要往版本目录里加一条依赖、给 androidTest 配一个
 * 自定义 Runner、再给每个测试类挂 `@HiltAndroidTest` —— 换来的只是
 * 「构造函数由 Dagger 调用」这一件事，而那件事本来就由 `:app` 的编译期校验管着。
 *
 * 代价是这里绕过了 `OnboardingNavigation.kt` 的导航接线（`navigation-compose`
 * 与嵌套图作用域）。那一段由 `:app:assembleDebug` 的编译期类型检查 +
 * 真机手工走一遍 J1 覆盖，见 M1.md 的落地记录。
 *
 * ⚠️ 仪器测试**只在合入 `main` 之后**跑（GMD，api 26 + 34，§14.3 的 12 分钟预算
 * 不允许在 PR 上起模拟器）。提 PR 前请本地跑一遍：
 * `./gradlew :feature:onboarding:connectedDebugAndroidTest`
 */
@RunWith(AndroidJUnit4::class)
class OnboardingJourneyTest {
    /**
     * ⚠️ `createAndroidComposeRule<ComponentActivity>()` 而不是 `createComposeRule()`：
     * 「按返回键」要有一个真的 `OnBackPressedDispatcher`，而那只有宿主 Activity 给得出。
     * 这个 Activity 由 `ui-test-manifest`（debugImplementation）提供，不必自己声明。
     */
    @get:Rule
    val composeTestRule = createAndroidComposeRule<ComponentActivity>()

    private val auth = FakeJourneyAuthRepository()

    private fun string(
        resId: Int,
        vararg args: Any,
    ): String =
        InstrumentationRegistry
            .getInstrumentation()
            .targetContext
            .getString(resId, *args)

    /** 错误提示外面包着 §11.2 的那个前缀符号（不以颜色作为唯一信息载体）。 */
    private fun errorText(resId: Int): String = string(R.string.onboarding_error_prefix, string(resId))

    // ------------------------------------------------------------------ J1

    /**
     * §1.4 J1 的前四步：语言/隐私说明 → 邮箱 → 6 位码 → username 设定。
     *
     * 最后一步刻意停在**二次确认对话框**上 —— 那个对话框是 §3.8 / §16 R13 的
     * MUST，而「按钮直接提交」的实现会让这条用例在这里就红。
     */
    @Test
    fun completesTheFirstRunJourney() {
        var completed = false
        composeTestRule.setContent {
            NcardsTheme { OnboardingJourneyHost(auth = auth, onCompleted = { completed = true }) }
        }

        // 1. Willkommen —— 语言与隐私说明，Datenschutz 链接可见（§8.6）。
        composeTestRule.onNodeWithText(string(R.string.onboarding_welcome_title)).assertIsDisplayed()
        composeTestRule.onNodeWithText(string(R.string.onboarding_open_privacy)).assertIsDisplayed()
        composeTestRule.onNodeWithText(string(R.string.onboarding_welcome_continue)).performClick()

        // 2. E-Mail —— 注册页，AGB 链接必须在这里（§8.6）。
        composeTestRule.onNodeWithText(string(R.string.onboarding_open_terms)).assertIsDisplayed()
        composeTestRule.onNodeWithTag(EMAIL_FIELD_TAG).performTextInput("anna@example.de")
        composeTestRule.onNodeWithText(string(R.string.onboarding_email_submit)).performClick()

        // 3. Code —— 6 位数字。
        composeTestRule.onNodeWithTag(OTP_FIELD_TAG).performTextInput("418396")
        composeTestRule.onNodeWithText(string(R.string.onboarding_otp_submit)).performClick()

        // 4. Nutzername —— 四条 MUST 都要在屏幕上。
        composeTestRule.onNodeWithText(string(R.string.onboarding_username_rules)).assertIsDisplayed()
        composeTestRule
            .onNodeWithText(string(R.string.onboarding_username_discoverability_notice))
            .assertIsDisplayed()

        composeTestRule.onNodeWithTag(USERNAME_FIELD_TAG).performTextInput("Anna_B ")
        // 归一化预览：用户看到的就是会被提交的。
        composeTestRule
            .onNodeWithTag(NORMALIZED_PREVIEW_TAG)
            .assertTextEquals(string(R.string.onboarding_username_normalized_preview, "anna_b"))

        composeTestRule.onNodeWithTag(SUBMIT_BUTTON_TAG).performClick()

        // ⚠️ 二次确认对话框，文案逐字（§3.8 加粗段 / §16 R13）。
        composeTestRule
            .onNodeWithText(string(R.string.onboarding_username_dialog_title))
            .assertIsDisplayed()
        composeTestRule.onNodeWithTag(DIALOG_CONFIRM_TAG).performClick()

        composeTestRule.waitForIdle()
        assertTrue("确认之后应当完成注册", completed)
    }

    /**
     * ⚠️ §3.8「设定时机」：username 设定页**不可跳过、不可返回**。
     *
     * 这条用例真的按一次系统返回，然后断言这一页还在。`BackHandler` 被谁顺手
     * 删掉（或者 `enabled` 被改成某个条件）都会让它红。
     */
    @Test
    fun theUsernamePageSwallowsTheBackGesture() {
        composeTestRule.setContent {
            NcardsTheme {
                UsernameScreen(
                    state = UsernameUiState(),
                    onInputChanged = {},
                    onConfirm = {},
                )
            }
        }

        composeTestRule.runOnUiThread {
            composeTestRule.activity.onBackPressedDispatcher.onBackPressed()
        }
        composeTestRule.waitForIdle()

        composeTestRule.onNodeWithText(string(R.string.onboarding_username_title)).assertIsDisplayed()
        // 空输入时按钮还必须是灰的 —— 不可逆操作不给「先点了再说」的机会。
        composeTestRule.onNodeWithTag(SUBMIT_BUTTON_TAG).assertIsNotEnabled()
    }

    // ---- 验收标准的后半条：两个错误码各有各的本地化文案 --------------------

    @Test
    fun usernameTakenShowsItsOwnLocalisedMessage() {
        auth.setUsernameResult = FakeJourneyAuthRepository.failure(ApiError.UsernameTaken("req-1"))

        submitUsername("anna_b")

        composeTestRule
            .onNodeWithTag(SERVER_ERROR_TAG)
            .assertTextEquals(errorText(R.string.onboarding_username_error_taken))
    }

    @Test
    fun usernameInvalidShowsItsOwnLocalisedMessage() {
        auth.setUsernameResult =
            FakeJourneyAuthRepository.failure(
                ApiError.UsernameInvalid(fieldErrors = emptyList(), requestId = "req-1"),
            )

        // 保留词走的就是这一路：本地放行，服务端拒（ADR-0017：不消耗 10 次计数）。
        submitUsername("admin")

        composeTestRule
            .onNodeWithTag(SERVER_ERROR_TAG)
            .assertTextEquals(errorText(R.string.onboarding_username_error_invalid))
    }

    /**
     * ⚠️ ADR-0017 决策二：`limit_exceeded` 是**终局**。文案里不能是「稍后再试」，
     * 也不该让用户面对一个还能再按的按钮 —— 这条用例钉住它与 `username_taken`
     * 不是同一句话。
     */
    @Test
    fun exhaustedAttemptsShowsATerminalMessage() {
        auth.setUsernameResult = FakeJourneyAuthRepository.failure(ApiError.LimitExceeded("req-1"))

        submitUsername("anna_b")

        composeTestRule
            .onNodeWithTag(SERVER_ERROR_TAG)
            .assertTextEquals(errorText(R.string.onboarding_username_error_attempts_exhausted))
    }

    private fun submitUsername(username: String) {
        composeTestRule.setContent {
            NcardsTheme { UsernameStep(auth = auth, onCompleted = {}) }
        }

        composeTestRule.onNodeWithTag(USERNAME_FIELD_TAG).performTextInput(username)
        composeTestRule.onNodeWithTag(SUBMIT_BUTTON_TAG).performClick()
        composeTestRule.onNodeWithTag(DIALOG_CONFIRM_TAG).performClick()
        composeTestRule.waitForIdle()
    }
}

/**
 * 把四屏串起来的测试宿主。
 *
 * 它替代的是 `OnboardingNavigation.kt` 的 `NavHost` 接线 —— 见
 * [OnboardingJourneyTest] 类注释里「为什么不用 Hilt」那一段。
 */
@Composable
private fun OnboardingJourneyHost(
    auth: FakeJourneyAuthRepository,
    onCompleted: () -> Unit,
) {
    val viewModel = remember { OnboardingViewModel(auth, FakeJourneyLanguageStore()) }
    val state by viewModel.state.collectAsStateWithLifecycle()
    var step by remember { mutableStateOf(JourneyStep.Welcome) }

    LaunchedEffect(viewModel) {
        viewModel.events.collect { event ->
            step =
                when (event) {
                    OnboardingEvent.CodeSent -> JourneyStep.Code
                    OnboardingEvent.UsernameRequired -> JourneyStep.Username
                    OnboardingEvent.SignedIn -> JourneyStep.Username
                }
        }
    }

    when (step) {
        JourneyStep.Welcome -> {
            WelcomeScreen(
                language = state.language,
                onLanguageSelected = viewModel::onLanguageSelected,
                onContinue = { step = JourneyStep.Email },
                onOpenPrivacy = {},
            )
        }

        JourneyStep.Email -> {
            EmailScreen(
                state = state,
                onEmailChanged = viewModel::onEmailChanged,
                onSubmit = viewModel::requestCode,
                onOpenTerms = {},
                onOpenPrivacy = {},
            )
        }

        JourneyStep.Code -> {
            OtpScreen(
                state = state,
                onCodeChanged = viewModel::onCodeChanged,
                onSubmit = viewModel::verifyCode,
                onResend = viewModel::resendCode,
            )
        }

        JourneyStep.Username -> {
            UsernameStep(auth = auth, onCompleted = onCompleted)
        }
    }
}

@Composable
private fun UsernameStep(
    auth: FakeJourneyAuthRepository,
    onCompleted: () -> Unit,
) {
    val viewModel = remember { UsernameViewModel(auth) }
    val state by viewModel.state.collectAsStateWithLifecycle()

    LaunchedEffect(viewModel) {
        viewModel.events.collect { event ->
            when (event) {
                UsernameEvent.Completed -> onCompleted()
            }
        }
    }

    UsernameScreen(
        state = state,
        onInputChanged = viewModel::onInputChanged,
        onConfirm = viewModel::submit,
    )
}

private enum class JourneyStep { Welcome, Email, Code, Username }
