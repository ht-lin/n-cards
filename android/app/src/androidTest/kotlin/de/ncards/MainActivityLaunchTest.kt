package de.ncards

import androidx.compose.ui.test.assertIsDisplayed
import androidx.compose.ui.test.junit4.createAndroidComposeRule
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import androidx.test.ext.junit.runners.AndroidJUnit4
import androidx.test.platform.app.InstrumentationRegistry
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith
import de.ncards.feature.legal.R as LegalR
import de.ncards.feature.onboarding.R as OnboardingR

/**
 * 冷启动烟测：App 真的起得来，并停在注册流程的第一屏。
 *
 * ============================================================================
 * 它补的是 `feature:onboarding` 那套 Compose UI Test **够不到**的三件事
 * ============================================================================
 * 那边手工 `new` ViewModel、手工切屏，所以它证明不了下面这些 —— 而这三件事
 * 恰好都是「错了就是启动即崩 / 静默失效」的类型：
 *
 * 1. **`Theme.NCards` 与 `AppCompatActivity` 配不配。** T-151 把主题父类换成了
 *    `Theme.AppCompat.Light.NoActionBar`，因为 per-app locales 要求 Activity 是
 *    `AppCompatActivity`。谁把主题改回 `android:Theme.Material.*`，
 *    这条用例会以「You need to use a Theme.AppCompat theme」当场红 ——
 *    而编译期、lint、单测都看不出来。
 * 2. **Hilt 的图在运行期真的建得起来。** 编译期只校验**可达**的绑定
 *    （T-150 的落地记录里那条警告），而 `AppViewModel` 注入 `AuthRepository`
 *    会把认证插槽、`SessionStore`、Keystore 一路拉起来。
 * 3. **NavHost 的接线。** 起始目的地由 `restoreSession()` 的三态决定：
 *    全新安装 → `SignedOut(NeverSignedIn)` → onboarding 的第一屏。
 *    `restoreSession()` 被删掉的话 `sessionState` 永远停在 `Unknown`，
 *    这里会一直是 splash，用例超时红。
 *
 * ⚠️ 它**不联网**：全新安装没有令牌，`AppViewModel` 那条路径上一个请求都不发。
 */
@RunWith(AndroidJUnit4::class)
class MainActivityLaunchTest {
    @get:Rule
    val composeTestRule = createAndroidComposeRule<MainActivity>()

    @Test
    fun coldStartLandsOnTheOnboardingWelcomeScreen() {
        awaitWelcomeScreen()

        composeTestRule.onNodeWithText(string(OnboardingR.string.onboarding_welcome_title)).assertIsDisplayed()
    }

    /**
     * ⚠️ 这条走的是**跨模块**的那一跳：`feature:onboarding` 的第一屏 →
     * `feature:legal` 的 Datenschutzerklärung，经 `core:model` 的 `PrivacyRoute`
     * 与本模块的 `NcardsNavHost`。
     *
     * 它是 §12.3 那条规则（feature 之间禁止互相依赖，跨 feature 导航经 app 的
     * NavHost + core:model 的路由定义）唯一真的被执行到的地方 ——
     * 两个 feature 各自的测试都够不着对方。
     *
     * 顺带兑现 §8.6 的「Datenschutzerklärung：**首次启动时链接可见**」。
     */
    @Test
    fun thePrivacyLinkOnTheFirstScreenOpensTheLegalPage() {
        awaitWelcomeScreen()

        composeTestRule.onNodeWithText(string(OnboardingR.string.onboarding_open_privacy)).performClick()

        composeTestRule
            .onNodeWithText(string(LegalR.string.legal_privacy_title))
            .assertIsDisplayed()

        // 返回：法律页是可以退出去的（与 username 设定页恰好相反）。
        composeTestRule.onNodeWithText(string(LegalR.string.legal_back)).performClick()
        composeTestRule.onNodeWithText(string(OnboardingR.string.onboarding_welcome_title)).assertIsDisplayed()
    }

    /**
     * 嵌套图内部的一跳（welcome → email）。
     *
     * ⚠️ **不要**在这里点「Code anfordern」—— 那会真的打一次
     * `POST /auth/otp/request` 到 staging，而 §7.5 给它的配额是每个 IP 20/h。
     * 一条在 CI 上每次合入都跑的用例不该去消耗它。请求那一侧由
     * `OnboardingViewModelTest` 在替身上验。
     */
    @Test
    fun theWelcomeScreenLeadsToTheEmailStep() {
        awaitWelcomeScreen()

        composeTestRule.onNodeWithText(string(OnboardingR.string.onboarding_welcome_continue)).performClick()

        composeTestRule.onNodeWithText(string(OnboardingR.string.onboarding_email_title)).assertIsDisplayed()
        // §8.6：AGB 的链接必须在**注册页**上。
        composeTestRule.onNodeWithText(string(OnboardingR.string.onboarding_open_terms)).assertIsDisplayed()
    }

    /** splash 只在读存储的那几十毫秒里（两趟 Keystore，是 I/O），所以要等一下。 */
    private fun awaitWelcomeScreen() {
        val welcome = string(OnboardingR.string.onboarding_welcome_title)
        composeTestRule.waitUntil(timeoutMillis = LAUNCH_TIMEOUT_MS) {
            composeTestRule.onAllNodesWithText(welcome).fetchSemanticsNodes().isNotEmpty()
        }
    }

    private fun string(resId: Int): String =
        InstrumentationRegistry
            .getInstrumentation()
            .targetContext
            .getString(resId)

    private companion object {
        /** 低端机（§13.8 的 Android 8 / 2 GB）上冷启动 + 两趟 Keystore 的宽松上界。 */
        const val LAUNCH_TIMEOUT_MS = 10_000L
    }
}
