package de.ncards.feature.cardedit

import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.hilt.lifecycle.viewmodel.compose.hiltViewModel
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.navigation.NavGraphBuilder
import androidx.navigation.compose.composable
import de.ncards.core.model.navigation.CardCreateRoute
import de.ncards.core.model.navigation.CardEditRoute
import de.ncards.core.ui.ObserveEvents

/**
 * 新增与编辑两个目的地。由 `:app` 的 NavHost 挂进去。
 *
 * ============================================================================
 * 两个路由、一个实现
 * ============================================================================
 * `CardCreateRoute` 没有参数，`CardEditRoute` 带 `cardId`。
 * [CardEditViewModel] 的判据就是「`SavedStateHandle` 里取不取得到那个 key」——
 * 取不到就是新建。所以这里注册两条 `composable`，指向同一个 [CardEditRoute] Composable。
 *
 * 做成两个路由而不是一个 `cardId: String?`：可空的类型安全路由参数要自己给
 * `NavType`，而「参数缺席」与「参数是字面量 null」在 `SavedStateHandle` 里长得一样。
 *
 * ⚠️ 跨 feature 的去处一律是回调，不是 `navController.navigate(…)`
 * （§12.3：feature 之间禁止互相依赖，`ModuleGraph` 在配置期强制它）。
 * 形状与 `walletDestination` / `cardDetailDestination` 完全一致。
 *
 * @param onSaved 保存成功。`:app` 那边接的是 `popBackStack()` ——
 *   新建完回钱包、编辑完回详情，两处都是「退回来的那一页」。
 */
fun NavGraphBuilder.cardEditDestination(
    onBack: () -> Unit,
    onSaved: () -> Unit,
) {
    composable<CardCreateRoute> {
        CardEditRoute(onBack = onBack, onSaved = onSaved)
    }

    composable<CardEditRoute> {
        // ⚠️ 这里**不**读 `toRoute<CardEditRoute>().cardId`：ViewModel 自己从
        // SavedStateHandle 按 key 取（见它的类注释），而本 Composable 对
        // 「新建还是编辑」一无所知 —— 那正是两个路由能共用一个实现的原因。
        CardEditRoute(onBack = onBack, onSaved = onSaved)
    }
}

/**
 * 给 `:app` 之外的预览与测试用的无 Hilt 入口。
 *
 * ⚠️ 仪器测试**不引 `hilt-android-testing`**（照 `WalletScreenTest` 与
 * `CardDetailScreenTest` 的做法：那样要多一个自定义 runner 与一批
 * `@HiltAndroidTest`，而换来的保证 `:app` 的编译期 Dagger 校验已经给了）。
 */
@Composable
private fun CardEditRoute(
    onBack: () -> Unit,
    onSaved: () -> Unit,
    viewModel: CardEditViewModel = hiltViewModel(),
) {
    val state by viewModel.state.collectAsStateWithLifecycle()

    // 「保存成功了，回去吧」是**一次性事件**（§10.4：Channel + receiveAsFlow）。
    // 本卡是 core:ui 那个 ObserveEvents 的第一个消费者 —— 它在 RESUMED 上收，
    // 于是这一页被盖住时不会触发导航。
    //
    // 放进 UiState 的话，旋转屏幕会让它再触发一次，用户会退两层。
    ObserveEvents(viewModel.savedEvents) { onSaved() }

    CardEditScreen(
        state = state,
        onBack = onBack,
        onTitleChange = viewModel::onTitleChange,
        onMerchantLabelChange = viewModel::onMerchantLabelChange,
        onColorChange = viewModel::onColorChange,
        onBarcodeFormatChange = viewModel::onBarcodeFormatChange,
        onBarcodeValueChange = viewModel::onBarcodeValueChange,
        onNoteChange = viewModel::onNoteChange,
        onExpiresOnChange = viewModel::onExpiresOnChange,
        onSave = viewModel::onSave,
    )
}
