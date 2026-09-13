package de.ncards.feature.carddetail

import androidx.compose.runtime.Composable
import androidx.compose.ui.tooling.preview.Preview
import de.ncards.core.barcode.render.BarcodeRenderRequest
import de.ncards.core.barcode.render.BarcodeRenderResult
import de.ncards.core.barcode.render.BarcodeRenderer
import de.ncards.core.designsystem.theme.NcardsTheme
import de.ncards.core.model.barcode.BarcodeFormat
import de.ncards.core.model.card.Card
import de.ncards.core.model.card.CardRole
import de.ncards.core.model.sync.SyncState
import java.time.LocalDate

/*
 * §4.3 的分层图第一行：「UI (Compose) 无状态 Composable **+ 预览**」。
 *
 * ⚠️ 两条**不是装饰**的预览：
 * - `fontScale = 2f`：§11.2 要求系统字体缩放到 200% 不截断，而德语本来就长。
 * - 长德语词：`Benachrichtigungseinstellungen` 是 §13.4 点名的那个词。
 *
 * 全屏页的 `Success` **没有**预览：那一格要一张真 `Bitmap`，而预览渲染器里
 * 造位图既不可靠也证明不了什么 —— 真正该看的是「白底黑码 + 1:1 不缩放」，
 * 那两条由仪器测试（截图断言像素是白）与真机实扫守着。
 * 预览这里覆盖的是三种**失败态**，它们恰恰是最容易被忘掉的三格。
 */

private fun previewCard(
    role: CardRole = CardRole.OWNER,
    title: String = "REWE Payback",
    note: String? = null,
    syncState: SyncState = SyncState.SYNCED,
    memberCount: Int? = 1,
    expiresOn: LocalDate? = null,
) = Card(
    id = "0192f3a1-b2c3-7d4e-8f01-23456789abcd",
    ownerId = "0192f3a1-b2c3-7d4e-8f01-00000000a11a",
    title = title,
    merchantLabel = "REWE",
    colorWire = "blue_600",
    barcodeFormat = BarcodeFormat.EAN_13,
    barcodeValue = "4012345678901",
    note = note,
    expiresOn = expiresOn,
    revision = 1,
    memberCount = memberCount,
    createdAt = 0,
    updatedAt = 0,
    role = role,
    sortOrder = 0,
    isPinned = false,
    syncState = syncState,
)

/** 固定返回一种结局的渲染器。预览与失败态用。 */
private class PreviewRenderer(
    private val result: BarcodeRenderResult,
) : BarcodeRenderer {
    override suspend fun render(request: BarcodeRenderRequest): BarcodeRenderResult = result
}

@Preview(name = "详情 · owner", showBackground = true)
@Composable
private fun CardDetailOwnerPreview() {
    NcardsTheme(dynamicColor = false) {
        CardDetailScreen(
            state =
                CardDetailUiState.Content(
                    previewCard(
                        note = "Karte liegt im Handschuhfach.",
                        syncState = SyncState.PENDING,
                        memberCount = 3,
                        expiresOn = LocalDate.of(2027, 8, 24),
                    ),
                ),
            onBack = {},
            onShowBarcode = {},
            onEdit = {},
        )
    }
}

/**
 * ⚠️ viewer 这一格要看的是**没有**什么：没有「编辑」按钮（§7.3：入口在 UI 层
 * 就不存在，不是点了报 403），也没有「共享给 N 人」那一行（`memberCount`
 * 对 viewer 恒为 null，且不得兜底成 0）。
 */
@Preview(name = "详情 · viewer（无编辑入口、无成员数）", showBackground = true)
@Composable
private fun CardDetailViewerPreview() {
    NcardsTheme(dynamicColor = false) {
        CardDetailScreen(
            state = CardDetailUiState.Content(previewCard(role = CardRole.VIEWER, memberCount = null)),
            onBack = {},
            onShowBarcode = {},
            onEdit = {},
        )
    }
}

@Preview(name = "详情 · 德语长词 + 200% 字体", showBackground = true, fontScale = 2f)
@Composable
private fun CardDetailLongGermanPreview() {
    NcardsTheme(dynamicColor = false) {
        CardDetailScreen(
            state =
                CardDetailUiState.Content(
                    previewCard(
                        title = "Benachrichtigungseinstellungen",
                        note = "Benachrichtigungseinstellungen für die Kundenkarte",
                    ),
                ),
            onBack = {},
            onShowBarcode = {},
            onEdit = {},
        )
    }
}

@Preview(name = "详情 · 卡已不在（不是错误）", showBackground = true)
@Composable
private fun CardDetailMissingPreview() {
    NcardsTheme(dynamicColor = false) {
        CardDetailScreen(
            state = CardDetailUiState.Missing,
            onBack = {},
            onShowBarcode = {},
            onEdit = {},
        )
    }
}

@Preview(name = "详情 · 读不出「我是谁」", showBackground = true)
@Composable
private fun CardDetailErrorPreview() {
    NcardsTheme(dynamicColor = false) {
        CardDetailScreen(
            state = CardDetailUiState.Error(CardDetailError.CurrentUserUnknown),
            onBack = {},
            onShowBarcode = {},
            onEdit = {},
        )
    }
}

/**
 * ⚠️ 全屏页的三个预览都显式关掉深色与动态取色，与 `FullscreenBarcodeActivity`
 * 里那一行一致 —— `NcardsTheme` 的 KDoc 点名这一页不得用深色配色。
 */
@Preview(name = "全屏 · 不认识的码制", showBackground = true)
@Composable
private fun FullscreenUnsupportedPreview() {
    NcardsTheme(darkTheme = false, dynamicColor = false) {
        FullscreenBarcodeScreen(
            state = CardDetailUiState.Content(previewCard()),
            renderer = PreviewRenderer(BarcodeRenderResult.UnsupportedFormat),
            onClose = {},
            onBarcodeDrawn = {},
        )
    }
}

@Preview(name = "全屏 · 码值与码制不匹配", showBackground = true)
@Composable
private fun FullscreenInvalidPreview() {
    NcardsTheme(darkTheme = false, dynamicColor = false) {
        FullscreenBarcodeScreen(
            state = CardDetailUiState.Content(previewCard()),
            renderer = PreviewRenderer(BarcodeRenderResult.InvalidPayload),
            onClose = {},
            onBarcodeDrawn = {},
        )
    }
}

@Preview(name = "全屏 · 太小，提示转横屏", showBackground = true)
@Composable
private fun FullscreenTooSmallPreview() {
    NcardsTheme(darkTheme = false, dynamicColor = false) {
        FullscreenBarcodeScreen(
            state =
                CardDetailUiState.Content(previewCard()),
            renderer =
                PreviewRenderer(
                    BarcodeRenderResult.TooSmall(minimumWidthPx = 4000, minimumHeightPx = 40),
                ),
            onClose = {},
            onBarcodeDrawn = {},
        )
    }
}
