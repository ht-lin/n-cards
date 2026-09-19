package de.ncards.feature.cardedit

import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.selection.selectable
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.clearAndSetSemantics
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.unit.dp
import de.ncards.core.designsystem.theme.cardColorSchemeOf
import de.ncards.core.model.card.CardColor

/**
 * 调色板。
 *
 * ============================================================================
 * ⚠️ 顺序就是 `CardColor.entries`，而第一格必须是默认色
 * ============================================================================
 * `CardColor` 的 KDoc 逐字写着这条是给本卡的：`DEFAULT = BLUE` 是 `entries` 的
 * **第一个**，所以「什么都不选」与「选第一格」拿到同一个色 ——
 * 用户不会遇到「我明明没选，怎么是绿的」。
 *
 * 换句话说这里**不许**排序、不许过滤、不许把默认色挪到别处。
 *
 * ============================================================================
 * ⚠️ 选中态不能只靠颜色（§11.2）
 * ============================================================================
 * 一个「被选中的蓝格」与「没被选中的蓝格」如果只差一层高亮，色盲用户与
 * 视障用户都分不出来。这里给了两个颜色之外的信号：
 * 一圈**边框**（视觉），以及 `selectable(selected = …)` 带来的选中语义（TalkBack）。
 *
 * ⚠️ `contentDescription` 与 `selectable` 必须在**同一个节点**上 ——
 * T-154 有两条仪器断言正是栽在「tag 在外层、语义在内层」这件事上
 * （两个未合并的语义节点，`fetchSemanticsNode()` 看不见）。所以这里
 * 语义写在最外层的 `Box` 上，里面那层纯色块用 `clearAndSetSemantics {}` 清空。
 *
 * @param selectedWire **原样的**色键，可能是本版本不认识的值（例如新版本 App
 *   建的 `mint_600`）。那时**一格都不高亮**是正确行为 —— 解析成枚举再高亮，
 *   等于告诉用户这张卡是蓝色的，而它不是。
 */
@OptIn(ExperimentalLayoutApi::class)
@Composable
internal fun ColorPicker(
    selectedWire: String,
    onSelect: (CardColor) -> Unit,
    modifier: Modifier = Modifier,
    enabled: Boolean = true,
) {
    FlowRow(
        modifier = modifier,
        horizontalArrangement = Arrangement.spacedBy(SwatchGap),
        verticalArrangement = Arrangement.spacedBy(SwatchGap),
    ) {
        CardColor.entries.forEach { color ->
            // ⚠️ 比的是 wireName 而不是 `CardColor.fromWire(selectedWire) == color`。
            // 后者会把认不出来的 `mint_600` 兜底成 BLUE，于是蓝格亮起来 ——
            // 而用户这张卡并不是蓝色的。见 CardColor.fromWire 的注释。
            val selected = color.wireName == selectedWire
            val name = stringResource(color.nameRes)

            // ⚠️ 在 semantics {} **外面**算：那个 lambda 不是 @Composable 上下文，
            // stringResource 在里面调不了（编译期就报，不是运行期）。
            val description = if (selected) stringResource(R.string.cardedit_color_selected, name) else name

            Box(
                contentAlignment = Alignment.Center,
                modifier =
                    Modifier
                        .size(SwatchTouchTarget)
                        .selectable(
                            selected = selected,
                            enabled = enabled,
                            onClick = { onSelect(color) },
                        ).semantics {
                            // TalkBack 读到的是「Blau, ausgewählt」而不是「Schaltfläche」。
                            // selectable 已经带了选中语义，文案里再说一遍是给那些
                            // 不朗读选中状态的 TTS 设置兜底 —— §11.2 要的是
                            // 「不以颜色作为唯一信息载体」，两层都给才算做到。
                            contentDescription = description
                        },
            ) {
                Box(
                    modifier =
                        Modifier
                            .size(SwatchSize)
                            .clip(CircleShape)
                            .background(cardColorSchemeOf(color).container)
                            .border(
                                width = if (selected) SelectedBorder else UnselectedBorder,
                                color =
                                    if (selected) {
                                        MaterialTheme.colorScheme.onSurface
                                    } else {
                                        MaterialTheme.colorScheme.outlineVariant
                                    },
                                shape = CircleShape,
                            )
                            // 外层已经承担了全部语义，里面这层是纯装饰 ——
                            // 不清空的话 TalkBack 会在同一个格子上停两次。
                            .clearAndSetSemantics {},
                ) {
                    if (selected) {
                        // 仓库没有图标库（见 SyncStateBadge 的先例），用字形。
                        // 它在 clearAndSetSemantics 之内，所以不会被读出来 ——
                        // 选中状态由外层的语义负责。
                        Text(
                            text = CHECK_GLYPH,
                            style = MaterialTheme.typography.labelLarge,
                            color = cardColorSchemeOf(color).onContainer,
                            modifier = Modifier.padding(CheckPadding),
                        )
                    }
                }
            }
        }
    }
}

/**
 * ⚠️ 48dp 是**触摸目标**，不是色块的大小（§11.2 / Material 的无障碍下限）。
 * 色块本身画 36dp，外面那圈透明区域把可点区域撑到 48 —— 十一个格子排在一起时，
 * 点错一格对银发用户是常态而不是意外。
 */
private val SwatchTouchTarget = 48.dp
private val SwatchSize = 36.dp
private val SwatchGap = 4.dp
private val SelectedBorder = 3.dp
private val UnselectedBorder = 1.dp
private val CheckPadding = 2.dp

/** U+2713 CHECK MARK。装饰性的，语义在外层节点上。 */
private const val CHECK_GLYPH = "✓"
