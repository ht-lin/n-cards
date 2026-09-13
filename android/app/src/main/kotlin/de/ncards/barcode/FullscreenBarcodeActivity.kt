package de.ncards.barcode

import android.content.Context
import android.content.Intent
import android.os.Bundle
import android.view.WindowManager
import androidx.activity.compose.ReportDrawnWhen
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.activity.viewModels
import androidx.appcompat.app.AppCompatActivity
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import dagger.hilt.android.AndroidEntryPoint
import de.ncards.core.barcode.render.BarcodeRenderer
import de.ncards.core.common.settings.ScreenshotPolicy
import de.ncards.core.designsystem.theme.NcardsTheme
import de.ncards.feature.carddetail.CardDetailViewModel
import de.ncards.feature.carddetail.FullscreenBarcodeRoute
import javax.inject.Inject

/**
 * 全屏条码页（§10.2 / T-154）。规格原文：
 *
 * > | **全屏条码页** | 独立 `Activity`（便于设置窗口属性） | 亮度 = 1.0f；
 * > `FLAG_KEEP_SCREEN_ON`；退出恢复原亮度（`onPause` 必须恢复，否则用户会
 * > 投诉耗电）；支持横屏放大 1D 码 |
 *
 * ============================================================================
 * ⚠️ 为什么这个类在 `:app`，而界面在 `feature:carddetail`
 * ============================================================================
 * 任务卡把这一页整个划给了卡详情那一卡，最省事的读法是「Activity 也放在
 * `feature:carddetail`」。那条路会踩进一个**没有任何报错**的坑：
 *
 * per-app locales（用户在 J1 第一屏选的界面语言）走
 * `AppCompatDelegate.setApplicationLocales`，而它在 **API < 33 上只对 AppCompat
 * 组件生效** —— minSdk 是 26，那是绝大多数设备。一个住在 feature 模块里的
 * 纯 `ComponentActivity` 会让这一页用**系统语言**而 App 其余部分用用户选的语言，
 * 而且编译、lint、单测、Compose UI 测试全都看不出来。
 *
 * 把它做成 `AppCompatActivity` 则要求 androidx.appcompat，而
 * `gradle/libs.versions.toml` 的那条注释是明令：「T-151：**只给 :app**，
 * 而且只为一件事 —— per-app locales」「⚠️ **不要**因为『反正引进来了』
 * 就在 feature 里用 appcompat 的任何东西」。
 *
 * 第三条理由让这个划分本来就自然：这个类拥有的**全部**东西 —— 亮度、
 * `FLAG_KEEP_SCREEN_ON`、`FLAG_SECURE`、manifest 条目、`exported="false"`、
 * 边到边、`reportFullyDrawn` —— 都是**窗口**的事，而窗口与 manifest 本来
 * 就住在组装层。界面那一半（`FullscreenBarcodeRoute`）在 feature 里，
 * §12.3 的分层一点没破。
 *
 * ⚠️ 它**不托管 NavHost**，所以不需要 `MainActivity` 里那句
 * `initializeViewTreeOwners()` —— 那一行是给 navigation-compose 的
 * `NavigationEventDispatcherOwner` 加的，理由见 `MainActivity` 的类注释。
 *
 * ============================================================================
 * 留给 T-254（Widget）/ T-255（QS Tile）
 * ============================================================================
 * 它们要起这一页，而 `:widget` **既不能依赖 `:app` 也不能依赖 `:feature:*`**
 * （`ModuleGraph` 只给它 `:core:` 与 `:data:`），所以它们够不着 [intent]。
 *
 * 届时的出路不是放宽规则，而是本仓库已经用过两次的那个形状：在 `core:common`
 * 声明一个 `FullscreenBarcodeLauncher`（返回 `Intent` 或 `PendingIntent`），
 * 由 `:app` `@Binds` 一个调用 [intent] 的实现，Glance 侧用 Hilt 的 `EntryPoint`
 * 取。参照 `CurrentUserIdStore` / `AppLanguageStore` / [ScreenshotPolicy]。
 *
 * 本卡**刻意不**提前建那个端口：一个只有一个实现、一个调用方的接口，
 * 按 `android/README.md` 的规则（「第三个消费者出现时才建」）现在不该存在。
 * 本卡欠 T-254 的只是**一个构造点**，那就是 [intent]。
 *
 * ⚠️ 另外留给 T-254 的一条：那时会出现「已经开着这一页，又从 Widget 点了
 * 另一张卡」的场景，需要 `launchMode="singleTop"` + `onNewIntent` 重新种
 * `SavedStateHandle`。今天唯一的入口是详情页的按钮，进来一次就是一张卡，
 * 所以 `launchMode` 保持默认。
 */
@AndroidEntryPoint
class FullscreenBarcodeActivity : AppCompatActivity() {
    private val viewModel: CardDetailViewModel by viewModels()

    @Inject
    lateinit var barcodeRenderer: BarcodeRenderer

    @Inject
    lateinit var screenshotPolicy: ScreenshotPolicy

    /**
     * 进来之前窗口的亮度覆盖值，`onPause` 时原样写回去。
     *
     * 实际上它必然是 `BRIGHTNESS_OVERRIDE_NONE`（`-1f`，「跟随系统与用户设置」）——
     * 一个刚建出来的窗口没有任何覆盖。读一次而不是写死 `-1f`，是为了让这段
     * 代码在将来真有人给主题设了亮度时仍然是对的。
     */
    private var brightnessBeforeEntering = WindowManager.LayoutParams.BRIGHTNESS_OVERRIDE_NONE

    override fun onCreate(savedInstanceState: Bundle?) {
        enableEdgeToEdge()
        super.onCreate(savedInstanceState)

        brightnessBeforeEntering = window.attributes.screenBrightness

        // ⚠️ `FLAG_SECURE` 必须在窗口内容确定之前设好：运行中翻转它需要重建窗口。
        // 所以这里读的是进入这一刻的值，而不是订阅那条流。见 ScreenshotPolicy。
        applyScreenshotPolicy(allowed = screenshotPolicy.allowScreenshots.value)

        setContent {
            var barcodeDrawn by remember { mutableStateOf(false) }

            // §9.1 的「Widget 点击 → 条码**完全渲染** P95 ≤ 800 ms」要一个可测量的
            // 终点。报的是「那张位图真的被组合出来了」，不是「Activity 的窗口出现了」
            // 也不是「render() 返回了」—— 后两者都会把一段用户还看不到条码的时间
            // 算成达标。Macrobenchmark 的 StartupTimingMetric 读的就是这个信号（T-453）。
            ReportDrawnWhen { barcodeDrawn }

            val state by viewModel.state.collectAsStateWithLifecycle()

            // ⚠️ 两个参数都必须显式给（`NcardsTheme` 的 KDoc 点名了这一页）：
            // 条码必须白底黑码，无论 App 主题深浅 —— 深色模式下的条码是扫码失败的
            // 经典原因。条码那一块的颜色 feature 侧另外写死，两件事缺一不可。
            NcardsTheme(darkTheme = false, dynamicColor = false) {
                FullscreenBarcodeRoute(
                    state = state,
                    renderer = barcodeRenderer,
                    onClose = { finish() },
                    onBarcodeDrawn = { barcodeDrawn = true },
                )
            }
        }
    }

    /**
     * ⚠️ 亮度在 `onResume` 拉满，不是在 `onCreate`。
     *
     * 被打断又回来（来电悬浮窗、切走再切回）时，`onCreate` 不会再跑一次 ——
     * 写在那里的话，用户回到这一页会发现屏幕是暗的，而他正站在收银台前。
     */
    override fun onResume() {
        super.onResume()
        setScreenBrightness(WindowManager.LayoutParams.BRIGHTNESS_OVERRIDE_FULL)
        window.addFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON)
    }

    /**
     * ⚠️ **必须在 `onPause` 恢复，不是 `onDestroy`**（任务卡明令，理由是耗电投诉）。
     *
     * `onPause` 而不是 `onStop`：分屏、别的 App 弹了个对话框，这一页会进入
     * 「暂停但仍然可见」的状态 —— 那时它已经不是用户在看的东西了，
     * 没有理由继续把屏幕烧在最亮。
     */
    override fun onPause() {
        super.onPause()
        setScreenBrightness(brightnessBeforeEntering)
        window.clearFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON)
    }

    /**
     * ⚠️ 必须把整个 `attributes` 写回去。
     *
     * `window.attributes` 返回的是一个**副本**：改它的字段而不重新赋值，
     * `WindowManager` 根本不会收到通知，于是亮度纹丝不动 ——
     * 而代码看起来完全正确。这是这个功能最容易悄悄坏掉的一处。
     */
    private fun setScreenBrightness(value: Float) {
        window.attributes = window.attributes.apply { screenBrightness = value }
    }

    private fun applyScreenshotPolicy(allowed: Boolean) {
        if (allowed) {
            window.clearFlags(WindowManager.LayoutParams.FLAG_SECURE)
        } else {
            window.addFlags(WindowManager.LayoutParams.FLAG_SECURE)
        }
    }

    companion object {
        /**
         * 起这一页。
         *
         * extra 的 key 就是导航路由的属性名（[CardDetailViewModel.CARD_ID_KEY]）——
         * 于是同一个 ViewModel 在 NavHost 与本 Activity 两个宿主下都取得到 id。
         * 全仓只有那一处定义。
         */
        fun intent(
            context: Context,
            cardId: String,
        ): Intent =
            Intent(context, FullscreenBarcodeActivity::class.java)
                .putExtra(CardDetailViewModel.CARD_ID_KEY, cardId)
    }
}
