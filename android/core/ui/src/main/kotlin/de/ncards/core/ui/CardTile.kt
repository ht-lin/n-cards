package de.ncards.core.ui

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.defaultMinSize
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.semantics.clearAndSetSemantics
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import de.ncards.core.designsystem.theme.cardColorSchemeOf
import de.ncards.core.model.card.CardColor

/**
 * 一张卡的**颜色 + 首字母**方块（§12.3 点名 `CardTile` 属于 `core:ui`）。
 *
 * 三个消费者：T-153 的钱包列表、T-154 的卡详情页、T-254 的 Glance Widget
 * （后者要自己重画一遍 —— Glance 的 `RemoteViews` 吃不了 Compose 的 Composable，
 * 但色值与首字母的规则该是同一套，所以它们共用 `cardColorSchemeOf` 与
 * `Card.initial`）。
 *
 * ============================================================================
 * ⚠️ 无障碍：它对 TalkBack 是**不可见**的
 * ============================================================================
 * `clearAndSetSemantics { }`（空）把它从无障碍树上摘掉。这不是遗漏：
 *
 * 颜色与首字母是**装饰**，不是信息 —— 卡的身份由旁边那行标题承载，而标题就在
 * 同一个列表项里。给它一个 `contentDescription` 的话，TalkBack 会先念
 * 「蓝色，R」再念「REWE Payback」，而前半句对视障用户没有任何用处。
 *
 * §11.2 的「装饰性图片为 `null`」说的就是这种情况。调用方**必须**保证标题
 * 在同一个可聚焦的项里 —— 钱包列表是这么做的（整行一个语义节点）。
 *
 * ⚠️ 也因此这里没有任何 `contentDescription = "字面量"`，
 * `checkComposeHardcodedText` 不会被触发。
 *
 * @param initial 首字母。空串时画一个纯色块 —— 兜底样式归 UI，
 *   而 `Card.initial` 刻意不在 `core:model` 里兜底成 `"?"`（那是用户可见字符，
 *   §11.1 要求它住在 `strings.xml` 里，而那个模块没有 `res/`）。
 */
@Composable
fun CardTile(
    color: CardColor,
    initial: String,
    modifier: Modifier = Modifier,
    size: androidx.compose.ui.unit.Dp = DefaultTileSize,
) {
    val scheme = cardColorSchemeOf(color)

    Box(
        modifier =
            modifier
                // defaultMinSize 而不是 size：字体放大到 200% 时首字母会变大，
                // 固定尺寸会把它切掉（§11.2「不设固定高度」）。
                .defaultMinSize(minWidth = size, minHeight = size)
                .clip(RoundedCornerShape(TileCornerRadius))
                .background(scheme.container)
                .padding(TilePadding)
                .clearAndSetSemantics { },
        contentAlignment = Alignment.Center,
    ) {
        if (initial.isNotEmpty()) {
            Text(
                text = initial,
                style = MaterialTheme.typography.titleLarge,
                color = scheme.onContainer,
                textAlign = TextAlign.Center,
            )
        }
    }
}

/**
 * 48 dp —— 与 §11.2 的「所有交互元素触摸目标 ≥ 48 dp」同一个数。
 *
 * 磁贴本身不可点（整行才是点击目标），但让它恰好等于那个尺寸，
 * 行高就自然不会低于 48 dp。
 */
private val DefaultTileSize = 48.dp
private val TileCornerRadius = 12.dp
private val TilePadding = 4.dp
