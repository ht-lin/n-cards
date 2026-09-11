package de.ncards.feature.legal

import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource

/**
 * AGB / Nutzungsbedingungen（§8.6：**注册页链接**）。
 *
 * ============================================================================
 * ⚠️ 这是占位文本，归 T-450 换成定稿
 * ============================================================================
 * §8.6 要求四份文本（Impressum / Datenschutzerklärung / AGB / 开源许可），
 * 全部德语 + 英语，而 §16 R8 把「法律文本未及时通过律师复核阻塞上架」列为
 * 中等风险并要求「M1 即启动律师沟通，不要留到 M4」。
 *
 * T-151 只负责**这条链路存在**：注册页有一个链接，点了能看到一页本地渲染的文本。
 * 文本本身由 T-450 替换（并补上 Impressum 与开源许可两页）。
 * 占位文本里逐字写明了它是占位 —— 一份看起来像正式条款的假条款比没有更糟。
 *
 * ⚠️ 换文本时**不得**出现 *"Ende-zu-Ende-verschlüsselt"* / *"Zero Knowledge"* /
 * *"Wir können deine Daten nicht sehen"*（T-450 的实现要点：可诉的虚假宣传风险）。
 * 对外只允许 *"Deine Kartennummern werden verschlüsselt gespeichert"*。
 */
@Composable
fun TermsScreen(
    onBack: () -> Unit,
    modifier: Modifier = Modifier,
) {
    LegalDocumentScreen(
        title = stringResource(R.string.legal_terms_title),
        paragraphs =
            listOf(
                stringResource(R.string.legal_placeholder_notice),
                stringResource(R.string.legal_terms_scope),
                stringResource(R.string.legal_terms_account),
                stringResource(R.string.legal_terms_username),
                stringResource(R.string.legal_terms_availability),
            ),
        onBack = onBack,
        modifier = modifier,
    )
}
