package de.ncards.core.ui

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.unit.dp

/**
 * 整屏的错误态 + 一个重试入口。
 *
 * ⚠️ §11.2：「**不以颜色作为唯一信息载体**」。所以 [message] 的呈现除了
 * `error` 色之外必须还有别的载体 —— 调用方传进来的文案里应当已经说清是什么错，
 * 而 [title] 用的是正常前景色、靠**位置与措辞**而不是靠红色传达「出事了」。
 *
 * 这与 `feature:onboarding` 的 `OnboardingErrorText`（一个 `⚠ %s` 前缀 + error 色）
 * 是同一条规则的两种落法：那里是表单里的一行行内错误，这里是整屏的错误态。
 * 行内错误需要一个符号把它从周围的说明文字里区分出来；整屏错误不需要，
 * 因为屏幕上没有别的东西。
 */
@Composable
fun ErrorPane(
    title: String,
    message: String,
    modifier: Modifier = Modifier,
    retry: ErrorPaneRetry? = null,
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
            modifier = Modifier.widthIn(max = 480.dp).fillMaxWidth(),
            verticalArrangement = Arrangement.spacedBy(16.dp),
        ) {
            Text(
                text = title,
                style = MaterialTheme.typography.headlineSmall,
                modifier = Modifier.semantics { heading() },
            )

            Text(
                text = message,
                style = MaterialTheme.typography.bodyLarge,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )

            if (retry != null) {
                OutlinedButton(
                    onClick = retry.onClick,
                    modifier = Modifier.fillMaxWidth(),
                ) {
                    Text(text = retry.label)
                }
            }
        }
    }
}

/** 重试按钮。文案由调用方给 —— 「再试一次」与「重新登录」不是同一句话。 */
data class ErrorPaneRetry(
    val label: String,
    val onClick: () -> Unit,
)
