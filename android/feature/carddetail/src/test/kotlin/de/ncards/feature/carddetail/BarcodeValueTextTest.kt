package de.ncards.feature.carddetail

import org.junit.jupiter.api.Assertions.assertEquals
import org.junit.jupiter.api.DisplayName
import org.junit.jupiter.api.Test

/**
 * §10.2 的无障碍硬要求里最容易被做错的那一半。
 *
 * 「码值必须……带 `contentDescription`，使 TalkBack 用户可以让系统朗读、
 * **口述给收银员**」——把原样的码值塞进 `contentDescription` 在代码审查里
 * 看不出任何问题，但 TTS 会把它念成一个天文数字，功能等于没做。
 */
@DisplayName("码值的朗读串")
class BarcodeValueTextTest {
    @Test
    @DisplayName("会员号逐个数字念，中间有停顿")
    fun spellsOutDigits() {
        assertEquals("4, 0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 0, 1", spokenBarcodeValue("4012345678901"))
    }

    @Test
    @DisplayName("短的也一样")
    fun spellsOutShortDigits() {
        assertEquals("1, 2, 3", spokenBarcodeValue("123"))
    }

    /**
     * ⚠️ 二维码的载荷动辄上百字符（网址、结构化数据）。把它拆成一个字符一个
     * 逗号，得到的是一段没人听得完的噪音 —— 比不拆更糟。
     * 而那类码值本来也不是用来口述的：会员卡号才是（§1.3 的 North Star 场景）。
     */
    @Test
    @DisplayName("长载荷原样念，不拆")
    fun keepsLongPayloadsIntact() {
        val url = "https://app.n-cards.de/l/magic/" + "a".repeat(64)

        assertEquals(url, spokenBarcodeValue(url))
    }

    @Test
    @DisplayName("带字母的载荷原样念，不拆")
    fun keepsAlphanumericIntact() {
        assertEquals("ABC-123", spokenBarcodeValue("ABC-123"))
    }

    /** 上限是 20：刚好覆盖全部一维码制的实际位数。 */
    @Test
    @DisplayName("刚好在长度上限内的全数字串仍然逐个念")
    fun spellsOutAtTheLengthLimit() {
        val twenty = "1".repeat(20)

        assertEquals(List(20) { "1" }.joinToString(", "), spokenBarcodeValue(twenty))
    }

    @Test
    @DisplayName("超过长度上限的全数字串原样念")
    fun keepsOverlongDigitsIntact() {
        val twentyOne = "1".repeat(21)

        assertEquals(twentyOne, spokenBarcodeValue(twentyOne))
    }

    @Test
    @DisplayName("空串不崩")
    fun handlesEmpty() {
        assertEquals("", spokenBarcodeValue(""))
    }
}
