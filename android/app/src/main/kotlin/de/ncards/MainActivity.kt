package de.ncards

import android.content.Intent
import android.os.Bundle
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.activity.viewModels
import androidx.appcompat.app.AppCompatActivity
import dagger.hilt.android.AndroidEntryPoint
import de.ncards.core.designsystem.theme.NcardsTheme
import de.ncards.navigation.MagicLinkUri
import de.ncards.navigation.NcardsNavHost

/**
 * 唯一的 Activity（§4.3：全 Compose，无 XML 布局）。
 *
 * ============================================================================
 * ⚠️ 它是 `AppCompatActivity`，不是 `ComponentActivity`
 * ============================================================================
 * 唯一的理由是 per-app locales：`AppCompatDelegate.setApplicationLocales(...)`
 * 在 API < 33 上只对 AppCompat 的组件生效（minSdk 是 26，所以那是绝大多数设备）。
 * J1 的第一屏要真的切界面语言，这是代价最小的路。
 *
 * 连带约束有两条，缺一边启动即崩或功能静默失效：
 * - `res/values/themes.xml` 的 `Theme.NCards` 必须继承 `Theme.AppCompat.*`；
 * - `AndroidManifest.xml` 必须声明 appcompat 的 `AppLocalesMetadataHolderService`
 *   并带 `autoStoreLocales=true`，否则语言选择活不过一次冷启动。
 *
 * `AppCompatActivity` 仍然是 `ComponentActivity` 的子类，所以 `enableEdgeToEdge()`、
 * `setContent`、`viewModels()` 一个都没变。**不要**因此往界面里引入任何
 * appcompat 的 View —— §4.3 的「全 Compose、无 XML 布局」没有变。
 */
@AndroidEntryPoint
class MainActivity : AppCompatActivity() {
    private val viewModel: AppViewModel by viewModels()

    override fun onCreate(savedInstanceState: Bundle?) {
        enableEdgeToEdge()
        super.onCreate(savedInstanceState)

        // ⚠️ 只在**首次**创建时看 intent。`savedInstanceState != null` 说明这是
        // 一次重建（旋转屏幕、切语言），而 `getIntent()` 还是当初那一个 ——
        // 不判的话同一个 Magic Link 会被消费第二次，而令牌是一次性的：
        // 第二次必定 401，用户看到「链接已失效」而他什么都没做错。
        if (savedInstanceState == null) {
            handleMagicLink(intent)
        }

        // ⚠️⚠️ 这一行是 AppCompatActivity 与 navigation-compose 的交界处，删了就崩。
        //
        // `AppCompatActivity` 覆盖了 `setContentView`，走自己的 `initViewTreeOwners()`。
        // 那个方法是 appcompat 1.6 时代写的，只挂 Lifecycle / ViewModelStore /
        // SavedStateRegistry / OnBackPressedDispatcher 四个 owner ——
        // 它**不知道** `androidx.navigationevent.NavigationEventDispatcherOwner`
        // 的存在（那是 activity 1.12 才加的，而 `ComponentActivity` 实现了它）。
        //
        // 于是 navigation-compose 2.10 的 `NavHost` 一组合就抛
        // `IllegalStateException: No NavigationEventDispatcher was provided via
        // LocalNavigationEventDispatcherOwner` —— 启动即崩，而编译期、lint、
        // 单测、`feature:onboarding` 的 Compose UI Test（它不建 NavHost）全都看不出来。
        //
        // `ComponentActivity.initializeViewTreeOwners()` 是 public 的，把全套 owner
        // 挂到 decorView 上；ComposeView 的查找会沿父链走上来，所以它必须在
        // `setContent` **之前**调。守着这一条的是 `MainActivityLaunchTest`。
        initializeViewTreeOwners()

        setContent {
            NcardsTheme {
                NcardsNavHost(viewModel = viewModel)
            }
        }
    }

    /**
     * App 已经在前台时点邮件里的链接会走这里（manifest 里 `launchMode="singleTop"`）。
     *
     * `setIntent` 是必须的：不设的话后续任何读 `intent` 的代码拿到的还是启动时那一个。
     */
    override fun onNewIntent(intent: Intent) {
        super.onNewIntent(intent)
        setIntent(intent)
        handleMagicLink(intent)
    }

    /**
     * ⚠️ 消费 Magic Link 的是 **App**，不是邮件里那个落地页（ADR-0016）。
     *
     * 落地页是一份静态 HTML，`GET` 它不消费任何东西 —— 企业邮件安全网关会自动
     * `GET` 邮件里的每一个链接，若 `GET` 即消费，用户还没点开就已失效（§7.1）。
     * 真正的消费是这里发出的 `POST /v1/auth/magic/consume`，而且它要带 `device`
     * —— 浏览器构造不出那个请求体。
     */
    private fun handleMagicLink(intent: Intent?) {
        val token = MagicLinkUri.tokenFrom(intent?.data) ?: return
        viewModel.consumeMagicLink(token)
    }
}
