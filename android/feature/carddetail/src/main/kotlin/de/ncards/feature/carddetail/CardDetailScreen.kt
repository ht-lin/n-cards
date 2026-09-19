package de.ncards.feature.carddetail

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Button
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.TopAppBar
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.res.pluralStringResource
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.clearAndSetSemantics
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import de.ncards.core.barcode.format.displayNameRes
import de.ncards.core.model.card.Card
import de.ncards.core.ui.CardTile
import de.ncards.core.ui.ErrorPane
import java.time.format.DateTimeFormatter
import java.time.format.FormatStyle

/**
 * 卡详情（T-154）。
 *
 * 无状态：它只消费 [CardDetailUiState]（§4.3 的分层图第一行）。
 *
 * ============================================================================
 * ⚠️ 这一页**不画条码**，只画一个「显示条码」按钮
 * ============================================================================
 * 两条理由，都不是省事：
 *
 * 1. 一张小条码正是 §10.1 明令禁止的那种东西 ——「必须按目标 View 尺寸生成，
 *    不要生成小图再放大」的反面是「生成一张小的给人看」，而小到详情页那个
 *    尺寸的一维码，扫码枪本来就读不出。画一个读不出的条码，只会让用户在
 *    收银台前先试一次、失败、再去找那个按钮。
 * 2. 画它要多跑一次渲染（另一个尺寸 = 另一个缓存 key），而这一页的用户
 *    下一步几乎必然是去全屏页。
 *
 * 码值以文本形式就在按钮下面，信息一点没少，而且那份文本本来就是
 * §10.2 的无障碍硬要求。
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
internal fun CardDetailScreen(
    state: CardDetailUiState,
    onBack: () -> Unit,
    onShowBarcode: () -> Unit,
    onEdit: () -> Unit,
    modifier: Modifier = Modifier,
) {
    Scaffold(
        modifier = modifier,
        topBar = {
            TopAppBar(
                title = {
                    Text(
                        text = (state as? CardDetailUiState.Content)?.card?.title
                            ?: stringResource(R.string.carddetail_title),
                        maxLines = 1,
                        overflow = TextOverflow.Ellipsis,
                    )
                },
                navigationIcon = {
                    TextButton(onClick = onBack, modifier = Modifier.testTag(DETAIL_BACK_TAG)) {
                        Text(text = stringResource(R.string.carddetail_back))
                    }
                },
            )
        },
    ) { innerPadding ->
        Column(modifier = Modifier.fillMaxSize().padding(innerPadding)) {
            when (state) {
                CardDetailUiState.Loading -> {
                    Unit
                }

                CardDetailUiState.Missing -> {
                    // ⚠️ 不是错误态：owner 删卡 / 我被移出成员 / 解除好友，
                    // 都是正常结局（§16 R12）。说清楚发生了什么，给一条出路。
                    ErrorPane(
                        title = stringResource(R.string.carddetail_missing_title),
                        message = stringResource(R.string.carddetail_missing_body),
                        modifier = Modifier.testTag(DETAIL_MISSING_TAG),
                    )
                }

                is CardDetailUiState.Error -> {
                    ErrorPane(
                        title = state.reason.title(),
                        message = state.reason.message(),
                        modifier = Modifier.testTag(DETAIL_ERROR_TAG),
                    )
                }

                is CardDetailUiState.Content -> {
                    CardDetailContent(
                        card = state.card,
                        onShowBarcode = onShowBarcode,
                        onEdit = onEdit,
                    )
                }
            }
        }
    }
}

@Composable
private fun CardDetailContent(
    card: Card,
    onShowBarcode: () -> Unit,
    onEdit: () -> Unit,
) {
    Column(
        modifier =
            Modifier
                .fillMaxSize()
                .verticalScroll(rememberScrollState())
                .padding(ScreenPadding)
                .testTag(DETAIL_CONTENT_TAG),
        verticalArrangement = Arrangement.spacedBy(BlockSpacing),
    ) {
        CardHeader(card)

        CardDetailSyncBadge(card.syncState)

        // 这一页的主行动（§1.3 的 North Star 场景：收银台前 5 秒内调出条码）。
        Button(
            onClick = onShowBarcode,
            modifier =
                Modifier
                    .fillMaxWidth()
                    // §11.2：交互元素触摸目标 ≥ 48 dp。heightIn 而不是 height，
                    // 字体放大到 200% 时它还要能长高。
                    .heightIn(min = ActionMinHeight)
                    .testTag(DETAIL_SHOW_BARCODE_TAG),
        ) {
            Text(text = stringResource(R.string.carddetail_show_barcode))
        }

        LabelledBlock(label = stringResource(R.string.carddetail_value_label)) {
            BarcodeValueText(
                barcodeValue = card.barcodeValue,
                style = MaterialTheme.typography.titleLarge,
                modifier = Modifier.testTag(DETAIL_VALUE_TAG),
            )
        }

        LabelledBlock(label = stringResource(R.string.carddetail_format_label)) {
            Text(text = stringResource(card.barcodeFormat.displayNameRes))
        }

        card.note?.takeIf(String::isNotBlank)?.let { note ->
            LabelledBlock(label = stringResource(R.string.carddetail_note_label)) {
                Text(text = note)
            }
        }

        card.expiresOn?.let { expiresOn ->
            LabelledBlock(label = stringResource(R.string.carddetail_expires_label)) {
                // §11.1：日期按 locale 格式化，不要自己拼 "dd.MM.yyyy" ——
                // 德语是 24.08.2026，英语是 Aug 24, 2026，而 App 的语言是
                // 用户在设置里选的，不一定等于系统 locale。
                Text(text = expiresOn.format(DateTimeFormatter.ofLocalizedDate(FormatStyle.MEDIUM)))
            }
        }

        // ⚠️ `?.let` 而不是 `?: 0`。viewer 恒为 null（§5.2 / 威胁模型 T21），
        // 而契约原文是「它是 null 时**不得**推断任何默认值」——
        // 画成「共享给 0 人」等于告诉 viewer 这张卡没被共享给别人，那是假的。
        card.memberCount?.let { count ->
            LabelledBlock(label = stringResource(R.string.carddetail_shared_label)) {
                Text(
                    text = pluralStringResource(R.plurals.carddetail_shared_with, count, count),
                    modifier = Modifier.testTag(DETAIL_SHARED_TAG),
                )
            }
        }

        // ⚠️ §7.3 / §4.4 要的是「viewer 的共享卡在 UI 层**即无**编辑入口」，
        // 不是「点了才报错」。所以是 if 而不是 enabled —— 对 viewer 这个节点
        // 根本不存在。gate 用 `card.canEdit`（契约原文要求），不要自己比角色字符串。
        if (card.canEdit) {
            OutlinedButton(
                onClick = onEdit,
                // T-155 起它真的能按了（feature:cardedit 的表单）。
                // ⚠️ 上面那个 `if (card.canEdit)` **不要**改成 enabled —— 那是两回事：
                // viewer 要的是这个节点根本不存在，有一条 assertDoesNotExist 守着。
                modifier =
                    Modifier
                        .fillMaxWidth()
                        .heightIn(min = ActionMinHeight)
                        .testTag(DETAIL_EDIT_TAG),
            ) {
                Text(text = stringResource(R.string.carddetail_edit))
            }
        }
    }
}

@Composable
private fun CardHeader(card: Card) {
    Row(
        modifier = Modifier.fillMaxWidth(),
        horizontalArrangement = Arrangement.spacedBy(HeaderSpacing),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        CardTile(color = card.color, initial = card.initial, size = HeaderTileSize)

        Column(verticalArrangement = Arrangement.spacedBy(HeaderTextSpacing)) {
            Text(text = card.title, style = MaterialTheme.typography.headlineSmall)

            card.merchantLabel?.takeIf(String::isNotBlank)?.let { merchant ->
                Text(
                    text = merchant,
                    style = MaterialTheme.typography.bodyMedium,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
            }
        }
    }
}

/**
 * 一个小标签 + 一段内容。
 *
 * 标签对 TalkBack 是隐藏的（`clearAndSetSemantics`）：「Kartennummer」这个词
 * 已经在下面那个节点的 `contentDescription` 里说过一遍了，念两遍只是噪音。
 * 其余几块的标签同理 —— 内容本身就带着自己的说明。
 */
@Composable
private fun LabelledBlock(
    label: String,
    content: @Composable () -> Unit,
) {
    Column(verticalArrangement = Arrangement.spacedBy(LabelSpacing)) {
        Text(
            text = label,
            style = MaterialTheme.typography.labelMedium,
            color = MaterialTheme.colorScheme.onSurfaceVariant,
            modifier = Modifier.clearAndSetSemantics { },
        )
        content()
    }
}

private val ScreenPadding = 16.dp
private val BlockSpacing = 20.dp
private val HeaderSpacing = 12.dp
private val HeaderTextSpacing = 2.dp
private val LabelSpacing = 4.dp
private val HeaderTileSize = 64.dp
private val ActionMinHeight = 48.dp

internal const val DETAIL_CONTENT_TAG = "carddetail_content"
internal const val DETAIL_BACK_TAG = "carddetail_back"
internal const val DETAIL_SHOW_BARCODE_TAG = "carddetail_show_barcode"
internal const val DETAIL_VALUE_TAG = "carddetail_value"
internal const val DETAIL_EDIT_TAG = "carddetail_edit"
internal const val DETAIL_SHARED_TAG = "carddetail_shared"
internal const val DETAIL_MISSING_TAG = "carddetail_missing"
internal const val DETAIL_ERROR_TAG = "carddetail_error"
