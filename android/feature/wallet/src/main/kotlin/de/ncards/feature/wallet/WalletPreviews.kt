package de.ncards.feature.wallet

import androidx.compose.runtime.Composable
import androidx.compose.ui.tooling.preview.Preview
import de.ncards.core.designsystem.theme.NcardsTheme
import de.ncards.core.model.barcode.BarcodeFormat
import de.ncards.core.model.card.Card
import de.ncards.core.model.card.CardRole
import de.ncards.core.model.sync.SyncState

/**
 * 预览。
 *
 * ⚠️ 它们**不是**装饰：§11.1 逐字要求「所有按钮/标签必须允许换行并做
 * **长文本预览测试**」，而 §4.3 的分层图第一行也写着「无状态 Composable **+ 预览**」。
 * 删掉它们来让 detekt 变绿等于删掉规格要求的东西 —— `UnusedPrivateMember`
 * 已经对 `@Preview` 做了豁免（`detekt.yml`，T-151 加的）。
 *
 * `fontScale = 2f` 的那几个对应 §11.2 的「支持系统字体缩放至 200% 不截断」。
 */
private fun previewCard(
    id: String,
    title: String,
    merchantLabel: String? = "REWE",
    colorWire: String = "blue_600",
    isPinned: Boolean = false,
    syncState: SyncState = SyncState.SYNCED,
    role: CardRole = CardRole.OWNER,
) = Card(
    id = id,
    ownerId = "owner",
    title = title,
    merchantLabel = merchantLabel,
    colorWire = colorWire,
    barcodeFormat = BarcodeFormat.EAN_13,
    barcodeValue = "4012345678901",
    note = null,
    expiresOn = null,
    revision = 1,
    memberCount = 1,
    createdAt = 0,
    updatedAt = 0,
    role = role,
    sortOrder = 0,
    isPinned = isPinned,
    syncState = syncState,
)

private val PreviewCards =
    listOf(
        previewCard(id = "1", title = "REWE Payback", isPinned = true),
        previewCard(id = "2", title = "dm PAYBACK", merchantLabel = "dm", colorWire = "green_600"),
        previewCard(
            id = "3",
            title = "Lidl Plus",
            merchantLabel = "Lidl",
            colorWire = "orange_600",
            syncState = SyncState.PENDING,
        ),
        previewCard(
            id = "4",
            title = "DeutschlandCard",
            colorWire = "red_600",
            syncState = SyncState.FAILED,
        ),
        // viewer 的卡：编辑入口不该出现（§7.3——入口在 UI 层就不存在）。
        previewCard(id = "5", title = "Familienkarte", colorWire = "purple_600", role = CardRole.VIEWER),
    )

@Preview(name = "列表", showBackground = true)
@Composable
private fun WalletContentPreview() {
    NcardsTheme(dynamicColor = false) {
        WalletScreen(
            state = WalletUiState.Content(cards = PreviewCards, query = "", totalCount = PreviewCards.size),
            onQueryChanged = {},
            onClearQuery = {},
            onCardClick = {},
            onTogglePin = {},
            onReorder = { _, _ -> },
            onAddCard = {},
        )
    }
}

/**
 * ⚠️ 验收标准逐字点名的那个词：`Benachrichtigungseinstellungen`（30 个字符）。
 *
 * 它必须**换行**而不是被截断。行高不固定、标题 `maxLines = 2` ——
 * 两条一起才做到这一点。
 */
@Preview(name = "德语长词", showBackground = true)
@Composable
private fun WalletLongGermanWordPreview() {
    NcardsTheme(dynamicColor = false) {
        WalletScreen(
            state =
                WalletUiState.Content(
                    cards =
                        listOf(
                            previewCard(
                                id = "1",
                                title = "Benachrichtigungseinstellungen",
                                merchantLabel = "Benachrichtigungseinstellungen",
                                syncState = SyncState.FAILED,
                            ),
                        ),
                    query = "",
                    totalCount = 1,
                ),
            onQueryChanged = {},
            onClearQuery = {},
            onCardClick = {},
            onTogglePin = {},
            onReorder = { _, _ -> },
            onAddCard = {},
        )
    }
}

/** §11.2：字体缩放到 200% 不得截断。德语长词 + 200% 是最坏的组合。 */
@Preview(name = "德语长词 · 字体 200%", showBackground = true, fontScale = 2f)
@Composable
private fun WalletLongGermanWordLargeFontPreview() {
    WalletLongGermanWordPreview()
}

@Preview(name = "空钱包", showBackground = true)
@Composable
private fun WalletEmptyPreview() {
    NcardsTheme(dynamicColor = false) {
        WalletScreen(
            state = WalletUiState.Empty,
            onQueryChanged = {},
            onClearQuery = {},
            onCardClick = {},
            onTogglePin = {},
            onReorder = { _, _ -> },
            onAddCard = {},
        )
    }
}

/** ⚠️ 与空钱包是**两句不同的话**。见 `WalletUiState` 的类注释。 */
@Preview(name = "搜索无结果", showBackground = true)
@Composable
private fun WalletNoResultsPreview() {
    NcardsTheme(dynamicColor = false) {
        WalletScreen(
            state = WalletUiState.Content(cards = emptyList(), query = "xyz", totalCount = 40),
            onQueryChanged = {},
            onClearQuery = {},
            onCardClick = {},
            onTogglePin = {},
            onReorder = { _, _ -> },
            onAddCard = {},
        )
    }
}

@Preview(name = "错误态", showBackground = true)
@Composable
private fun WalletErrorPreview() {
    NcardsTheme(dynamicColor = false) {
        WalletScreen(
            state = WalletUiState.Error(WalletError.CurrentUserUnknown),
            onQueryChanged = {},
            onClearQuery = {},
            onCardClick = {},
            onTogglePin = {},
            onReorder = { _, _ -> },
            onAddCard = {},
        )
    }
}
