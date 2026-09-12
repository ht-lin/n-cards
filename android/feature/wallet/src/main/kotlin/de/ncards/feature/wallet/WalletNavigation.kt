package de.ncards.feature.wallet

import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.hilt.lifecycle.viewmodel.compose.hiltViewModel
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.navigation.NavGraphBuilder
import androidx.navigation.compose.composable
import de.ncards.core.model.navigation.WalletRoute

/**
 * 钱包目的地。由 `:app` 的 NavHost 挂进去。
 *
 * ============================================================================
 * ⚠️ 跨 feature 的去处一律是回调，不是 `navController.navigate(…)`
 * ============================================================================
 * §12.3：「`feature:*` 之间禁止互相依赖（跨 feature 导航通过 app 的 NavHost +
 * core:model 的路由定义）」，而 `ModuleGraph` 在**配置期**强制它 ——
 * 一行 `feature:wallet → feature:carddetail` 会让 `./gradlew help` 当场变红。
 *
 * 所以这里收的是 [onOpenCard] / [onAddCard] 两个 lambda，由 `:app` 接到
 * T-154 / T-155 的路由上。形状与 `onboardingGraph(onOpenTerms = …)` 完全一致。
 *
 * ⚠️ 路由 `WalletRoute` **不在本模块**，它在 `core:model` —— T-151 就是这么
 * 摆的，为的是让「换掉占位屏」不牵动任何导航调用点。本卡兑现了那个设计：
 * `:app` 那边只改了一行。
 *
 * @param onOpenCard 点一张卡 → 卡详情（T-154）。参数是卡 id。
 * @param onAddCard 空状态上的「添加第一张卡」。
 *   ⚠️ 录入的三条路（T-155 / T-156 / T-157）都还不存在，所以 `:app` 今天传进来的
 *   是一个空实现，而按钮本身是**禁用**的（见 `WalletScreen`）。
 */
fun NavGraphBuilder.walletDestination(
    onOpenCard: (String) -> Unit,
    onAddCard: () -> Unit,
) {
    composable<WalletRoute> {
        val viewModel: WalletViewModel = hiltViewModel()
        // §4.3 的分层图：UI 消费 ViewModel 的 StateFlow 只有这一条路。
        val state by viewModel.state.collectAsStateWithLifecycle()

        WalletScreen(
            state = state,
            onQueryChanged = viewModel::onQueryChanged,
            onClearQuery = viewModel::onClearQuery,
            onCardClick = onOpenCard,
            onTogglePin = viewModel::onTogglePin,
            onReorder = viewModel::onReorder,
            onAddCard = onAddCard,
        )
    }
}

/**
 * 给 `:app` 之外的预览与测试用的无 Hilt 入口。
 *
 * ⚠️ 仪器测试**不引 `hilt-android-testing`**（照 `OnboardingJourneyTest` 的做法：
 * 那样要多一个自定义 runner 与一批 `@HiltAndroidTest`，而换来的保证
 * `:app` 的编译期 Dagger 校验已经给了）。所以测试直接调 [WalletScreen]，
 * 本函数只是让那条路径有一个具名的入口。
 */
@Composable
internal fun WalletRoute(
    viewModel: WalletViewModel,
    onOpenCard: (String) -> Unit,
    onAddCard: () -> Unit,
) {
    val state by viewModel.state.collectAsStateWithLifecycle()

    WalletScreen(
        state = state,
        onQueryChanged = viewModel::onQueryChanged,
        onClearQuery = viewModel::onClearQuery,
        onCardClick = onOpenCard,
        onTogglePin = viewModel::onTogglePin,
        onReorder = viewModel::onReorder,
        onAddCard = onAddCard,
    )
}
