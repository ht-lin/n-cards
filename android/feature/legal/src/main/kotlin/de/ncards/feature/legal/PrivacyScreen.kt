package de.ncards.feature.legal

import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource

/**
 * Datenschutzerklärung（Art. 13 GDPR）。
 *
 * §8.6 对这一页的位置要求有两条，T-151 兑现的是第二条：
 * 「设置 → 隐私」（T-450）与 **「首次启动时链接可见」**（本卡：onboarding 第一屏）。
 *
 * ⚠️ 同样是占位文本，归 T-450 换成定稿 —— 理由与措辞禁令见 [TermsScreen] 的类注释。
 * 定稿必须明确写出 §8.6 / T-450 点名的两条实质优势：
 * ML Kit bundled 模型完全在设备本地运行；从图片录入是纯本地解码，
 * 图片不上传、不落盘、不进日志。
 */
@Composable
fun PrivacyScreen(
    onBack: () -> Unit,
    modifier: Modifier = Modifier,
) {
    LegalDocumentScreen(
        title = stringResource(R.string.legal_privacy_title),
        paragraphs =
            listOf(
                stringResource(R.string.legal_placeholder_notice),
                stringResource(R.string.legal_privacy_controller),
                stringResource(R.string.legal_privacy_data),
                stringResource(R.string.legal_privacy_username),
                stringResource(R.string.legal_privacy_rights),
            ),
        onBack = onBack,
        modifier = modifier,
    )
}
