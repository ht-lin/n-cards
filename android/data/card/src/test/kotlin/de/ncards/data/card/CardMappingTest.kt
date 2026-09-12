package de.ncards.data.card

import de.ncards.core.model.barcode.BarcodeFormat
import de.ncards.core.model.card.CardColor
import de.ncards.core.model.card.CardRole
import de.ncards.core.model.sync.SyncState
import org.junit.jupiter.api.Assertions.assertEquals
import org.junit.jupiter.api.Assertions.assertFalse
import org.junit.jupiter.api.Assertions.assertNull
import org.junit.jupiter.api.Assertions.assertSame
import org.junit.jupiter.api.DisplayName
import org.junit.jupiter.api.Test
import java.time.LocalDate

@DisplayName("WalletCard → Card 映射")
class CardMappingTest {
    @Test
    @DisplayName("裸字符串在这道关上变成枚举")
    fun parsesEnumsFromColumns() {
        val card =
            WalletRows
                .row(
                    id = "c1",
                    color = "teal_600",
                    barcodeFormat = "QR_CODE",
                    role = "viewer",
                    syncState = "PENDING",
                ).toCard()

        assertSame(CardColor.TEAL, card.color)
        assertSame(BarcodeFormat.QR_CODE, card.barcodeFormat)
        assertSame(CardRole.VIEWER, card.role)
        assertSame(SyncState.PENDING, card.syncState)
    }

    /**
     * ⚠️ 本文件最重要的一条。
     *
     * 库里那个字符串必须原样活着。把它在映射这一步就换成枚举的话，
     * 一个新版本创建的 `mint_600` 会在老客户端下次编辑时被静默写回成 `blue_600`，
     * 而 `color` 不参与任何服务端校验（`PATCH` 只比 revision）——
     * 没有任何机制会发现这次数据损坏。
     */
    @Test
    @DisplayName("认不出来的色键原样保留，只有渲染兜底")
    fun keepsUnknownColorWireVerbatim() {
        val card = WalletRows.row(id = "c1", color = "mint_600").toCard()

        assertEquals("mint_600", card.colorWire)
        assertSame(CardColor.DEFAULT, card.color)
    }

    /**
     * §13.6：服务端允许新增枚举值，老客户端不得因此崩。
     * 认不出来的码制画不出来，但卡本身要能显示（ADR-0023 的 `UNKNOWN` 兜底样式）。
     */
    @Test
    @DisplayName("认不出来的码制变成 UNKNOWN 而不是抛异常")
    fun unknownBarcodeFormatDoesNotThrow() {
        val card = WalletRows.row(id = "c1", barcodeFormat = "MAXICODE").toCard()

        assertSame(BarcodeFormat.UNKNOWN, card.barcodeFormat)
        assertFalse(card.barcodeFormat.isRenderable)
    }

    /**
     * 库里存的是 **epoch day**，不是 millis。
     *
     * 弄错的症状很隐蔽：19723 当成毫秒是 1970-01-01，于是所有卡都显示成
     * 「1970 年到期」；反过来当成天数则是公元 5 万年。两种都不会崩。
     */
    @Test
    @DisplayName("expires_on 是 epoch day，不是 epoch millis")
    fun expiresOnIsEpochDay() {
        val epochDay = LocalDate.of(2026, 12, 31).toEpochDay()

        val card = WalletRows.row(id = "c1", expiresOn = epochDay).toCard()

        assertEquals(LocalDate.of(2026, 12, 31), card.expiresOn)
    }

    @Test
    @DisplayName("不过期的卡 expiresOn 为 null")
    fun nullExpiresOnStaysNull() {
        assertNull(WalletRows.row(id = "c1", expiresOn = null).toCard().expiresOn)
    }

    @Test
    @DisplayName("每成员私有的 placement 从 join 出来的列上取，不是从 cards 上")
    fun placementComesFromTheMemberRow() {
        val card = WalletRows.row(id = "c1", sortOrder = 7, isPinned = true).toCard()

        assertEquals(7, card.sortOrder)
        assertEquals(true, card.isPinned)
    }

    @Test
    @DisplayName("viewer 没有编辑权——UI 靠它藏掉编辑入口")
    fun viewerCannotEdit() {
        assertFalse(WalletRows.row(id = "c1", role = "viewer").toCard().canEdit)
    }
}
