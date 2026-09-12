package de.ncards.feature.wallet

import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.CustomAccessibilityAction
import androidx.compose.ui.semantics.customActions
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import de.ncards.core.model.card.Card
import de.ncards.core.ui.CardTile

/**
 * 钱包列表里的一行。
 *
 * ============================================================================
 * ⚠️ 无障碍：拖拽**必须**有一个非手势的等价物
 * ============================================================================
 * 排序是本卡的交付物之一，而 TalkBack 用户**做不了长按拖拽手势** ——
 * 在 TalkBack 打开时，长按会被无障碍服务拦截掉。只做拖拽等于把「排序」
 * 这个功能对视障用户整个关掉，而 §11.2 的目标用户里明确包含银发与视障群体。
 *
 * 出路是 `customActions`：TalkBack 的「操作」菜单里会出现「向上移动」/
 * 「向下移动」/「置顶」。这是 §11.2 「可后置」清单里
 * 「无障碍服务的自定义动作」那一条 —— 但对**这一屏**它不可后置，
 * 因为没有它就没有等价路径。
 *
 * ============================================================================
 * ⚠️ 整行是**一个**语义节点
 * ============================================================================
 * `CardTile` 自己 `clearAndSetSemantics { }` 把颜色磁贴摘掉了 ——
 * 「蓝色，R」对视障用户没有用处，而卡的身份由标题承载。
 * 所以这一行读出来是「REWE Payback, REWE」加上徽章文字，然后是可用的操作。
 *
 * @param onMoveUp / [onMoveDown] 为 `null` 时那条自定义动作不出现
 *   （第一行没有「向上移动」）。
 */
@Composable
internal fun WalletCardRow(
    card: Card,
    onClick: () -> Unit,
    onTogglePin: () -> Unit,
    modifier: Modifier = Modifier,
    onMoveUp: (() -> Unit)? = null,
    onMoveDown: (() -> Unit)? = null,
) {
    val pinLabel = stringResource(if (card.isPinned) R.string.wallet_unpin else R.string.wallet_pin)
    val moveUpLabel = stringResource(R.string.wallet_move_up)
    val moveDownLabel = stringResource(R.string.wallet_move_down)

    val actions =
        buildList {
            add(
                CustomAccessibilityAction(pinLabel) {
                    onTogglePin()
                    true
                },
            )
            onMoveUp?.let {
                add(
                    CustomAccessibilityAction(moveUpLabel) {
                        it()
                        true
                    },
                )
            }
            onMoveDown?.let {
                add(
                    CustomAccessibilityAction(moveDownLabel) {
                        it()
                        true
                    },
                )
            }
        }

    Surface(
        modifier =
            modifier
                .fillMaxWidth()
                .semantics { customActions = actions },
        color = MaterialTheme.colorScheme.surface,
    ) {
        Row(
            modifier =
                Modifier
                    .clickable(onClick = onClick)
                    // ⚠️ heightIn(min) 而不是 height：§11.2 要求字体放大到 200%
                    // 不截断，固定高度会把第二行商家名切掉。48 dp 是那条
                    // 「所有交互元素触摸目标 ≥ 48 dp」的下限。
                    .heightIn(min = MinRowHeight)
                    .padding(horizontal = 16.dp, vertical = 8.dp),
            horizontalArrangement = Arrangement.spacedBy(12.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            CardTile(color = card.color, initial = card.initial)

            Column(
                modifier = Modifier.weight(1f),
                verticalArrangement = Arrangement.spacedBy(2.dp),
            ) {
                Text(
                    text = card.title,
                    style = MaterialTheme.typography.titleMedium,
                    // ⚠️ 两行而不是一行：德语的卡名很长
                    // （验收标准点名的 Benachrichtigungseinstellungen 是 30 个字符）。
                    // 一行 + Ellipsis 会让长德语名全都看起来一样。
                    maxLines = 2,
                    overflow = TextOverflow.Ellipsis,
                )

                card.merchantLabel?.takeIf { it.isNotBlank() }?.let { merchant ->
                    Text(
                        text = merchant,
                        style = MaterialTheme.typography.bodyMedium,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                        maxLines = 1,
                        overflow = TextOverflow.Ellipsis,
                    )
                }

                SyncStateBadge(syncState = card.syncState)
            }
        }
    }
}

private val MinRowHeight = 48.dp
