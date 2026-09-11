package de.ncards.feature.onboarding

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ColumnScope
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.unit.dp

/**
 * 注册流程四屏共用的外壳：标题 + 引导语 + 内容，整页可滚动。
 *
 * ============================================================================
 * ⚠️ 为什么整页都在 `verticalScroll` 里
 * ============================================================================
 * §11.2 的上线门禁有一条「支持系统字体缩放至 200% 不截断」，而 §11.1 提醒
 * 德语单词很长（`Benachrichtigungseinstellungen`）。两件事叠在一起，
 * 任何一屏在 200% + 德语 + 小屏上都会超出一屏高度。
 * 不给滚动的后果不是「难看」，是**按钮够不着** —— 而这四屏每一屏都有一个
 * 非按不可的按钮。
 *
 * 同理：这里**不给**任何元素设固定高度，字号一律走 `MaterialTheme.typography`
 * （它的 `sp` 与行高由 `core:designsystem` 的 `Type.kt` 定）。
 */
@Composable
internal fun OnboardingScaffold(
    title: String,
    modifier: Modifier = Modifier,
    subtitle: String? = null,
    content: @Composable ColumnScope.() -> Unit,
) {
    Scaffold(modifier = modifier) { innerPadding ->
        Column(
            modifier =
                Modifier
                    .fillMaxSize()
                    .padding(innerPadding)
                    .verticalScroll(rememberScrollState())
                    .padding(horizontal = 24.dp, vertical = 24.dp),
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

                if (subtitle != null) {
                    Text(
                        text = subtitle,
                        style = MaterialTheme.typography.bodyLarge,
                    )
                }

                content()
            }
        }
    }
}

/**
 * 错误提示。
 *
 * ⚠️ §11.2：「不以颜色作为唯一信息载体」。所以这里除了 `error` 色之外
 * 还有一个前缀符号 —— 色弱用户与高对比度模式下，红色本身传不了「这是错误」。
 */
@Composable
internal fun OnboardingErrorText(
    message: String,
    modifier: Modifier = Modifier,
) {
    Text(
        text = stringResource(R.string.onboarding_error_prefix, message),
        style = MaterialTheme.typography.bodyMedium,
        color = MaterialTheme.colorScheme.error,
        modifier = modifier.fillMaxWidth(),
    )
}
