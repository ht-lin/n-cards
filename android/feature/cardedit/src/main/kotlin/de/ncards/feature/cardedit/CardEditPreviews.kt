package de.ncards.feature.cardedit

import androidx.compose.runtime.Composable
import androidx.compose.ui.tooling.preview.Preview
import de.ncards.core.barcode.format.BarcodePayloadProblem
import de.ncards.core.designsystem.theme.NcardsTheme
import de.ncards.core.model.barcode.BarcodeFormat
import de.ncards.core.model.card.CardColor
import de.ncards.core.model.card.CardDraftProblem
import de.ncards.core.model.card.CardField
import java.time.LocalDate

/*
 * §4.3 的分层图第一行写着「无状态 Composable **+ 预览**」，而 §11.1 要求
 * 「所有按钮/标签必须允许换行并做**长文本预览测试**」。
 *
 * 所以这里每个非 happy 状态各一条，外加德语长词与 fontScale = 2f 两条 ——
 * 与 `WalletPreviews.kt` / `CardDetailPreviews.kt` 同一套清单。
 *
 * ⚠️ 全部 `NcardsTheme(dynamicColor = false)`：默认开着的动态取色跟壁纸走，
 * 预览里会得到一组与真机无关的颜色。
 */

private fun previewForm(
    title: String = "REWE Payback",
    merchantLabel: String = "REWE",
    colorWire: String = CardColor.DEFAULT.wireName,
    barcodeFormat: BarcodeFormat = BarcodeFormat.EAN_13,
    barcodeValue: String = "4012345678901",
    note: String = "",
    expiresOn: LocalDate? = null,
    problems: Map<CardField, CardDraftProblem> = emptyMap(),
    payloadProblem: BarcodePayloadProblem? = null,
    showProblems: Boolean = false,
) = CardEditForm(
    title = title,
    merchantLabel = merchantLabel,
    colorWire = colorWire,
    barcodeFormat = barcodeFormat,
    barcodeValue = barcodeValue,
    note = note,
    expiresOn = expiresOn,
    problems = problems,
    payloadProblem = payloadProblem,
    showProblems = showProblems,
)

@Composable
private fun PreviewScreen(state: CardEditUiState) {
    NcardsTheme(dynamicColor = false) {
        CardEditScreen(
            state = state,
            onBack = {},
            onTitleChange = {},
            onMerchantLabelChange = {},
            onColorChange = {},
            onBarcodeFormatChange = {},
            onBarcodeValueChange = {},
            onNoteChange = {},
            onExpiresOnChange = {},
            onSave = {},
        )
    }
}

/** 新建：空表单。第一格色块必须是默认色（`CardColor` 的 KDoc 点名要求本卡这么做）。 */
@Preview(name = "Neu – leer")
@Composable
private fun CreateEmptyPreview() {
    PreviewScreen(
        CardEditUiState.Editing(
            form =
                CardEditForm(
                    colorWire = CardColor.DEFAULT.wireName,
                    barcodeFormat = BarcodeFormat.EAN_13,
                ),
            mode = CardEditMode.CREATE,
        ),
    )
}

@Preview(name = "Bearbeiten – gefüllt")
@Composable
private fun EditFilledPreview() {
    PreviewScreen(
        CardEditUiState.Editing(
            form = previewForm(expiresOn = LocalDate.of(2026, 12, 31), note = "Rückseite abgenutzt"),
            mode = CardEditMode.EDIT,
        ),
    )
}

/** 两层校验各出一条错，确认它们能同时显示在各自的输入框下面。 */
@Preview(name = "Fehler – beide Ebenen", heightDp = 900)
@Composable
private fun ProblemsPreview() {
    PreviewScreen(
        CardEditUiState.Editing(
            form =
                previewForm(
                    title = "",
                    barcodeValue = "40123456789",
                    problems = mapOf(CardField.TITLE to CardDraftProblem.Blank),
                    payloadProblem = BarcodePayloadProblem.WrongLength(listOf(12, 13)),
                    showProblems = true,
                ),
            mode = CardEditMode.CREATE,
        ),
    )
}

/** 保存中：输入框全禁用、保存按钮按不动。 */
@Preview(name = "Speichert")
@Composable
private fun SavingPreview() {
    PreviewScreen(
        CardEditUiState.Editing(form = previewForm(), mode = CardEditMode.CREATE, isSaving = true),
    )
}

@Preview(name = "Karte weg")
@Composable
private fun MissingPreview() {
    PreviewScreen(CardEditUiState.Missing)
}

@Preview(name = "Fehler")
@Composable
private fun ErrorPreview() {
    PreviewScreen(CardEditUiState.Error(CardEditError.CurrentUserUnknown))
}

/**
 * §11.1 点名的那条：德语长词必须能换行而不是被截断。
 * `Benachrichtigungseinstellungen` 是本仓库统一用的那个样本。
 */
@Preview(name = "Langes Wort", heightDp = 900)
@Composable
private fun LongGermanWordPreview() {
    PreviewScreen(
        CardEditUiState.Editing(
            form =
                previewForm(
                    title = "Benachrichtigungseinstellungen",
                    merchantLabel = "Benachrichtigungseinstellungen",
                    note = "Benachrichtigungseinstellungen",
                ),
            mode = CardEditMode.EDIT,
        ),
    )
}

/** 系统字号拉到两倍 —— 银发用户的常用设置（§13.8 的目标人群）。 */
@Preview(name = "Schriftgröße 2f", fontScale = 2f, heightDp = 1200)
@Composable
private fun LargeFontPreview() {
    PreviewScreen(
        CardEditUiState.Editing(form = previewForm(), mode = CardEditMode.CREATE),
    )
}
