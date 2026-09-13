package de.ncards.feature.carddetail

import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.hilt.lifecycle.viewmodel.compose.hiltViewModel
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.navigation.NavGraphBuilder
import androidx.navigation.compose.composable
import androidx.navigation.toRoute
import de.ncards.core.model.navigation.CardDetailRoute

/**
 * 卡详情目的地。由 `:app` 的 NavHost 挂进去。
 *
 * ============================================================================
 * ⚠️ 跨 feature 的去处一律是回调，不是 `navController.navigate(…)`
 * ============================================================================
 * §12.3：「`feature:*` 之间禁止互相依赖」，而 `ModuleGraph` 在**配置期**强制它。
 * 形状与 `walletDestination(onOpenCard = …)` 完全一致。
 *
 * [onShowBarcode] 更特别一点：全屏条码页**不是一个路由**，它是一个独立
 * `Activity`（§10.2「便于设置窗口属性」），而那个 Activity 住在 `:app`。
 * 所以这里收到的仍然是一个 lambda，只不过 `:app` 那边接的是
 * `startActivity` 而不是 `navigate`。本模块对此一无所知，这正是要的。
 *
 * @param onShowBarcode 打开全屏条码页。参数是卡 id。
 * @param onEdit 编辑（T-155）。⚠️ `:app` 今天传的是空实现，而按钮本身是
 *   **禁用**的（见 [CardDetailScreen]）——录入的三条路都还不存在。
 */
fun NavGraphBuilder.cardDetailDestination(
    onBack: () -> Unit,
    onShowBarcode: (String) -> Unit,
    onEdit: (String) -> Unit,
) {
    composable<CardDetailRoute> { backStackEntry ->
        val viewModel: CardDetailViewModel = hiltViewModel()
        // §4.3 的分层图：UI 消费 ViewModel 的 StateFlow 只有这一条路。
        val state by viewModel.state.collectAsStateWithLifecycle()

        // ⚠️ 这里**不**从 state 里取 id：`Missing` / `Error` 两格里没有卡，
        // 而「编辑哪一张」是导航参数不是数据。
        //
        // 用 `toRoute<CardDetailRoute>()` 而不是自己去 `arguments` 里按名字捞 ——
        // 那是类型安全路由的正经取法，改了属性名编译期就会报。
        // （ViewModel 那边取不了它：它还有第二个宿主，一个根本没有路由的
        // Activity。见 `CardDetailViewModel` 的类注释。）
        val cardId = backStackEntry.toRoute<CardDetailRoute>().cardId

        CardDetailScreen(
            state = state,
            onBack = onBack,
            onShowBarcode = { onShowBarcode(cardId) },
            onEdit = { onEdit(cardId) },
        )
    }
}

/**
 * 给 `:app` 之外的预览与测试用的无 Hilt 入口。
 *
 * ⚠️ 仪器测试**不引 `hilt-android-testing`**（照 `WalletScreenTest` 与
 * `OnboardingJourneyTest` 的做法：那样要多一个自定义 runner 与一批
 * `@HiltAndroidTest`，而换来的保证 `:app` 的编译期 Dagger 校验已经给了）。
 */
@Composable
internal fun CardDetailRoute(
    viewModel: CardDetailViewModel,
    cardId: String,
    onBack: () -> Unit,
    onShowBarcode: (String) -> Unit,
    onEdit: (String) -> Unit,
) {
    val state by viewModel.state.collectAsStateWithLifecycle()

    CardDetailScreen(
        state = state,
        onBack = onBack,
        onShowBarcode = { onShowBarcode(cardId) },
        onEdit = { onEdit(cardId) },
    )
}
