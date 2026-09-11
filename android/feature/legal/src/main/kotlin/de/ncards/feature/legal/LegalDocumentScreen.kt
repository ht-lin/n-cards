package de.ncards.feature.legal

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.TopAppBar
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.tooling.preview.Preview
import androidx.compose.ui.unit.dp
import de.ncards.core.designsystem.theme.NcardsTheme

/**
 * 一份法律文本的通用外壳：标题 + 若干段落 + 返回。
 *
 * ⚠️ **不使用 `WebView` 加载远程内容**（§8.6 / T-450 的实现要点）。法律页面必须是
 * 本地渲染的，否则「用户看到的条款」取决于一次网络请求的结果，而它恰恰是
 * 用户在没有网络、或者在我们的站点出问题时最需要看到的东西。
 *
 * @param onBack 返回上一页。由 `:app` 的 NavHost 提供 —— 本模块看不见
 *   `feature:onboarding`（§12.3：feature 之间禁止互相依赖）。
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
internal fun LegalDocumentScreen(
    title: String,
    paragraphs: List<String>,
    onBack: () -> Unit,
    modifier: Modifier = Modifier,
) {
    Scaffold(
        modifier = modifier,
        topBar = {
            TopAppBar(
                title = { Text(text = title) },
                navigationIcon = {
                    // 用文字而不是图标：一个返回箭头在这里省不下什么，
                    // 而文字按钮天然满足 §11.2 的 48 dp 触摸目标与 TalkBack 朗读。
                    TextButton(onClick = onBack) {
                        Text(text = stringResource(R.string.legal_back))
                    }
                },
            )
        },
    ) { innerPadding ->
        Column(
            modifier =
                Modifier
                    .fillMaxSize()
                    .padding(innerPadding)
                    .verticalScroll(rememberScrollState())
                    .padding(horizontal = 24.dp, vertical = 16.dp),
            verticalArrangement = Arrangement.spacedBy(16.dp),
        ) {
            paragraphs.forEach { paragraph ->
                Text(
                    text = paragraph,
                    style = MaterialTheme.typography.bodyLarge,
                )
            }
        }
    }
}

@Preview(showBackground = true)
@Composable
private fun LegalDocumentScreenPreview() {
    NcardsTheme {
        LegalDocumentScreen(
            title = stringResource(R.string.legal_terms_title),
            paragraphs = listOf(stringResource(R.string.legal_placeholder_notice)),
            onBack = {},
        )
    }
}
