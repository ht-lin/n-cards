package de.ncards.core.model.card

import de.ncards.core.model.barcode.BarcodeFormat
import de.ncards.core.model.sync.SyncState
import org.junit.jupiter.api.Assertions.assertEquals
import org.junit.jupiter.api.Assertions.assertFalse
import org.junit.jupiter.api.Assertions.assertSame
import org.junit.jupiter.api.Assertions.assertTrue
import org.junit.jupiter.api.DisplayName
import org.junit.jupiter.api.Test

@DisplayName("Card")
class CardTest {
    private fun card(
        title: String = "REWE Payback",
        colorWire: String = "blue_600",
        role: CardRole = CardRole.OWNER,
    ) = Card(
        id = "0192f3a1-b2c3-7d4e-8f01-23456789abcd",
        ownerId = "0192f3a1-b2c3-7d4e-8f01-00000000a11a",
        title = title,
        merchantLabel = "REWE",
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
        isPinned = false,
        syncState = SyncState.SYNCED,
    )

    @Test
    @DisplayName("color 由 colorWire 解析，认不出来兜底到 DEFAULT")
    fun colorParsesFromWire() {
        assertSame(CardColor.TEAL, card(colorWire = "teal_600").color)
        assertSame(CardColor.DEFAULT, card(colorWire = "mint_600").color)
    }

    /**
     * 这条是 [Card.color] 那段注释的可执行版本：**原样字符串必须活下来**。
     *
     * 它守的是一个悄无声息的数据损坏 —— 老客户端编辑一张新版本创建的卡，
     * 把 `mint_600` 写回成 `blue_600`，而 `color` 不参与任何服务端校验，
     * `PATCH` 只比 revision，所以没有任何机制会发现。
     */
    @Test
    @DisplayName("认不出来的色键原样保留在 colorWire 里，不被枚举吃掉")
    fun unknownColorWireSurvives() {
        assertEquals("mint_600", card(colorWire = "mint_600").colorWire)
    }

    @Test
    @DisplayName("canEdit 转发 role，viewer 没有编辑权")
    fun canEditForwardsRole() {
        assertTrue(card(role = CardRole.OWNER).canEdit)
        assertFalse(card(role = CardRole.VIEWER).canEdit)
        assertFalse(card(role = CardRole.UNKNOWN).canEdit)
    }

    @Test
    @DisplayName("initial 取首字母并大写")
    fun initialIsTheUppercasedFirstLetter() {
        assertEquals("R", card(title = "REWE Payback").initial)
        assertEquals("D", card(title = "dm Payback").initial)
    }

    /** 德语变音符号是单个码位，不该被切坏，也不该被折叠成 A/O/U。 */
    @Test
    @DisplayName("德语变音符号原样保留")
    fun initialKeepsUmlauts() {
        assertEquals("Ä", card(title = "Ähnliche Karte").initial)
        assertEquals("Ö", card(title = "ÖAMTC").initial)
    }

    /**
     * `title.first()` 在这里会切出半个代理对，渲染成一个豆腐块。
     * 用户完全可能把卡叫成「🛒 REWE」。
     */
    @Test
    @DisplayName("emoji 开头的标题不被切成半个代理对")
    fun initialHandlesSurrogatePairs() {
        val shoppingCart = "🛒"

        assertEquals(shoppingCart, card(title = "$shoppingCart REWE").initial)
    }

    /**
     * 空标题返回空串而**不是** `"?"`：那会是一个用户可见的字符，
     * 而 §11.1 要求所有用户可见字符串住在 `strings.xml` 里 —— 本模块没有 `res/`。
     */
    @Test
    @DisplayName("空白标题返回空串，兜底样式归 UI")
    fun blankTitleYieldsEmptyInitial() {
        assertEquals("", card(title = "   ").initial)
        assertEquals("", card(title = "").initial)
    }
}
