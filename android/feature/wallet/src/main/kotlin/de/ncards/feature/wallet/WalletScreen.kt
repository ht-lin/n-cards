package de.ncards.feature.wallet

import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.offset
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.itemsIndexed
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.FloatingActionButton
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.material3.TopAppBar
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.alpha
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.unit.IntOffset
import androidx.compose.ui.unit.dp
import de.ncards.core.model.card.Card
import de.ncards.core.ui.EmptyState
import de.ncards.core.ui.EmptyStateAction
import de.ncards.core.ui.ErrorPane

/**
 * 钱包列表（T-153 的主屏）。
 *
 * 无状态：它只消费 [WalletUiState]，不持有任何业务状态
 * （§4.3 的分层图第一行：「UI (Compose) 无状态 Composable + 预览；只消费 UiState」）。
 * 唯一的例外是拖拽的中间态 —— 那**必须**留在这一层，理由见 [ReorderableListState]。
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
internal fun WalletScreen(
    state: WalletUiState,
    onQueryChanged: (String) -> Unit,
    onClearQuery: () -> Unit,
    onCardClick: (String) -> Unit,
    onTogglePin: (String) -> Unit,
    onReorder: (cardId: String, toIndex: Int) -> Unit,
    onAddCard: () -> Unit,
    modifier: Modifier = Modifier,
) {
    val addCardDescription = stringResource(R.string.wallet_add_card)

    Scaffold(
        modifier = modifier,
        topBar = { TopAppBar(title = { Text(text = stringResource(R.string.wallet_title)) }) },
        floatingActionButton = {
            // ⚠️ 没有它，录入功能只能用一次：空状态那个按钮在**有卡之后就消失了**，
            // 于是用户加完第一张卡就再也找不到入口。T-155 的任务卡没写这一条，
            // 但 J1（引导加第一张）与 J4（地铁上临时加一张）都需要它。
            //
            // 与空状态那个按钮共用同一个 onAddCard —— 两个入口、一个去处。
            // 加第二个回调只会让 :app 那边要接两次同样的 navigate。
            if (state is WalletUiState.Content) {
                FloatingActionButton(
                    onClick = onAddCard,
                    modifier = Modifier.testTag(WALLET_ADD_FAB_TAG),
                ) {
                    // 仓库没有图标库（SyncStateBadge 与 ColorPicker 的先例都是字形）。
                    // contentDescription 挂在 FAB 自己身上 —— 一个只有「+」的
                    // 无障碍标签会被读成「加号」，那不告诉用户会发生什么。
                    Text(
                        text = ADD_GLYPH,
                        style = MaterialTheme.typography.headlineSmall,
                        modifier = Modifier.semantics { contentDescription = addCardDescription },
                    )
                }
            }
        },
    ) { innerPadding ->
        Column(modifier = Modifier.fillMaxSize().padding(innerPadding)) {
            // 搜索框只在「有卡」时出现 —— 一张卡都没有的时候给一个搜索框
            // 是在问用户「你想在空盒子里找什么」。
            if (state is WalletUiState.Content) {
                WalletSearchField(
                    query = state.query,
                    onQueryChanged = onQueryChanged,
                    onClearQuery = onClearQuery,
                )
            }

            when (state) {
                WalletUiState.Loading -> {
                    Unit
                }

                WalletUiState.Empty -> {
                    EmptyState(
                        title = stringResource(R.string.wallet_empty_title),
                        body = stringResource(R.string.wallet_empty_body),
                        action =
                            EmptyStateAction(
                                label = stringResource(R.string.wallet_add_first_card),
                                // T-155 起它真的能按了（手输录入）。T-156 / T-157
                                // 会在表单里补上扫码与图片两条来源，入口不变。
                                onClick = onAddCard,
                            ),
                        modifier = Modifier.testTag(WALLET_EMPTY_TAG),
                    )
                }

                is WalletUiState.Error -> {
                    ErrorPane(
                        title = state.reason.title(),
                        message = state.reason.message(),
                        modifier = Modifier.testTag(WALLET_ERROR_TAG),
                    )
                }

                is WalletUiState.Content -> {
                    if (state.isNoSearchResult) {
                        // ⚠️ **不是** Empty。一个有 40 张卡的用户搜错一个字母，
                        // 不该被告知「你还没有任何卡」——那既是谎话，
                        // 给的出路（添加第一张卡）也是错的。
                        EmptyState(
                            title = stringResource(R.string.wallet_no_results_title),
                            body = stringResource(R.string.wallet_no_results_body, state.query),
                            modifier = Modifier.testTag(WALLET_NO_RESULTS_TAG),
                        )
                    } else {
                        WalletList(
                            cards = state.cards,
                            // 搜索结果里不给拖拽：用户看到的顺序是过滤过的，
                            // 把第 2 个拖到第 5 个，在**完整**列表里对应哪一步
                            // 是没有定义的。清空搜索才能排序。
                            reorderable = state.query.isBlank(),
                            onCardClick = onCardClick,
                            onTogglePin = onTogglePin,
                            onReorder = onReorder,
                        )
                    }
                }
            }
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun WalletSearchField(
    query: String,
    onQueryChanged: (String) -> Unit,
    onClearQuery: () -> Unit,
) {
    OutlinedTextField(
        value = query,
        onValueChange = onQueryChanged,
        singleLine = true,
        label = { Text(text = stringResource(R.string.wallet_search_hint)) },
        trailingIcon = {
            if (query.isNotEmpty()) {
                val clearLabel = stringResource(R.string.wallet_search_clear)

                IconButton(
                    onClick = onClearQuery,
                    // ⚠️ 没有图标库（见 SyncStateBadge 的注释），所以「清空」
                    // 是一个文字叉。它是**装饰**，真正的标签挂在按钮上 ——
                    // §11.2「全部图标按钮有 contentDescription」。
                    modifier = Modifier.semantics { contentDescription = clearLabel },
                ) {
                    Text(text = CLEAR_GLYPH)
                }
            }
        },
        modifier =
            Modifier
                .fillMaxWidth()
                .padding(horizontal = 16.dp, vertical = 8.dp)
                .testTag(WALLET_SEARCH_TAG),
    )
}

@Composable
private fun WalletList(
    cards: List<Card>,
    reorderable: Boolean,
    onCardClick: (String) -> Unit,
    onTogglePin: (String) -> Unit,
    onReorder: (cardId: String, toIndex: Int) -> Unit,
) {
    val listState = rememberLazyListState()
    val reorderState =
        rememberReorderableListState(listState) { from, to ->
            cards.getOrNull(from)?.let { onReorder(it.id, to) }
        }

    LazyColumn(
        state = listState,
        modifier = Modifier.fillMaxSize().testTag(WALLET_LIST_TAG),
    ) {
        // ⚠️ itemsIndexed 而不是 items + indexOf：后者是每一项一次线性扫描，
        // 200 张卡就是 O(n²)，而 §9.1 给滚动的预算是 P99 < 16.6 ms。
        itemsIndexed(
            items = cards,
            // ⚠️ 稳定 key 是那条预算的前提：没有它，任何一次列表变化都会让
            // Compose 重建所有项而不是复用。卡 id 是客户端生成的 UUIDv7，
            // 天然稳定（§4.3）。
            key = { _, card -> card.id },
            // 置顶与非置顶的行结构相同，但分开能让 Compose 的复用池更准。
            contentType = { _, card -> card.isPinned },
        ) { index, card ->
            WalletCardRow(
                card = card,
                onClick = { onCardClick(card.id) },
                onTogglePin = { onTogglePin(card.id) },
                // TalkBack 的等价操作：第一行没有「向上」，最后一行没有「向下」。
                onMoveUp = { onReorder(card.id, index - 1) }.takeIf { reorderable && index > 0 },
                onMoveDown =
                    { onReorder(card.id, index + 1) }
                        .takeIf { reorderable && index < cards.lastIndex },
                modifier =
                    with(reorderState) {
                        Modifier
                            // ⚠️ animateItem 要在 offset **之前**：重排动画由它负责，
                            // 而拖拽中的手动位移是叠在动画结果之上的。反过来的话
                            // 手指位移会被动画插值覆盖掉，拖起来像在打滑。
                            //
                            // 它自动尊重系统的「减少动画」设置（§11.2 最后一条）：
                            // Compose 的动画统一看 ANIMATOR_DURATION_SCALE，
                            // 关掉时直接跳到终态而不是自己插值。
                            .animateItem()
                            .offset { IntOffset(0, offsetFor(index).toPixelOffset()) }
                            // 被拖起来的那一项半透明，让下面的落点看得见。
                            .alpha(if (draggingIndex == index) DRAGGING_ALPHA else 1f)
                            .then(if (reorderable) Modifier.reorderableItem(index) else Modifier)
                    },
            )
        }
    }
}

/** 「清空」按钮上的那个叉。装饰字形，真正的标签在按钮的 contentDescription 上。 */
private const val CLEAR_GLYPH = "\u00D7"

private const val DRAGGING_ALPHA = 0.85f

internal const val WALLET_LIST_TAG = "wallet_list"
internal const val WALLET_SEARCH_TAG = "wallet_search_field"
internal const val WALLET_ADD_FAB_TAG = "wallet_add_fab"
internal const val WALLET_EMPTY_TAG = "wallet_empty"
internal const val WALLET_NO_RESULTS_TAG = "wallet_no_results"
internal const val WALLET_ERROR_TAG = "wallet_error"

/** U+FF0B FULLWIDTH PLUS SIGN —— 比 ASCII 的 `+` 在 FAB 里视觉重心更正。 */
private const val ADD_GLYPH = "\uFF0B"
