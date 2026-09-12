package de.ncards.core.ui

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Button
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.unit.dp

/**
 * 「这里什么都没有」+ 一个出路。
 *
 * ⚠️ 文案全部由调用方传进来，本模块没有 `res/` —— 一个跨 feature 的组件不该替
 * 调用方决定那句话怎么说（钱包的空状态与好友列表的空状态是两句不同的话）。
 *
 * ============================================================================
 * ⚠️ 为什么整页在 `verticalScroll` 里
 * ============================================================================
 * 照搬 `OnboardingScaffold` 的那条结论，理由一字不差：§11.2 要求字体缩放到
 * 200% 不截断，而 §11.1 提醒德语单词很长。两件事叠在一起，任何一屏在
 * 200% + 德语 + 小屏上都会超出一屏高度。不给滚动的后果不是「难看」，
 * 是**按钮够不着** —— 而空状态那个按钮正是用户唯一能做的事。
 *
 * 同理：这里不给任何元素设固定高度，字号一律走 `MaterialTheme.typography`。
 *
 * @param action 出路。为 `null` 时不画按钮 —— 「搜索无结果」就没有按钮可给。
 */
@Composable
fun EmptyState(
    title: String,
    body: String,
    modifier: Modifier = Modifier,
    action: EmptyStateAction? = null,
) {
    Column(
        modifier =
            modifier
                .fillMaxSize()
                .verticalScroll(rememberScrollState())
                .padding(horizontal = 24.dp, vertical = 24.dp),
        verticalArrangement = Arrangement.spacedBy(16.dp, Alignment.CenterVertically),
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        Column(
            // 平板/横屏上别把一行文字拉到 800 dp 宽 —— 读起来是灾难。
            modifier = Modifier.widthIn(max = 480.dp).fillMaxWidth(),
            verticalArrangement = Arrangement.spacedBy(16.dp),
        ) {
            Text(
                text = title,
                style = MaterialTheme.typography.headlineMedium,
                // TalkBack 的标题导航靠它；§11.2 的「焦点顺序合理」。
                modifier = Modifier.semantics { heading() },
            )

            Text(
                text = body,
                style = MaterialTheme.typography.bodyLarge,
            )

            if (action != null) {
                Button(
                    onClick = action.onClick,
                    enabled = action.enabled,
                    modifier = Modifier.fillMaxWidth(),
                ) {
                    Text(text = action.label)
                }
            }
        }
    }
}

/**
 * 空状态上那个按钮。
 *
 * [enabled] 存在是因为 T-153 交付时**录入的三条路都还不存在**
 * （T-155 手输 / T-156 扫码 / T-157 图片），而「添加第一张卡」这句话仍然该说 ——
 * 它告诉用户这个 App 是干什么的。按钮画出来但按不动，比一个空白屏诚实。
 */
data class EmptyStateAction(
    val label: String,
    val enabled: Boolean = true,
    val onClick: () -> Unit,
)
