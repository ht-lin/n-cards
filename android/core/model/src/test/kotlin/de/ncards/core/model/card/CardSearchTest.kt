package de.ncards.core.model.card

import de.ncards.core.model.barcode.BarcodeFormat
import de.ncards.core.model.sync.SyncState
import org.junit.jupiter.api.Assertions.assertFalse
import org.junit.jupiter.api.Assertions.assertTrue
import org.junit.jupiter.api.DisplayName
import org.junit.jupiter.api.Test

@DisplayName("钱包搜索")
class CardSearchTest {
    private fun card(
        title: String = "REWE Payback",
        merchantLabel: String? = "REWE",
        note: String? = null,
    ) = Card(
        id = "0192f3a1-b2c3-7d4e-8f01-23456789abcd",
        ownerId = "0192f3a1-b2c3-7d4e-8f01-00000000a11a",
        title = title,
        merchantLabel = merchantLabel,
        colorWire = "blue_600",
        barcodeFormat = BarcodeFormat.EAN_13,
        barcodeValue = "4012345678901",
        note = note,
        expiresOn = null,
        revision = 1,
        memberCount = 1,
        createdAt = 0,
        updatedAt = 0,
        role = CardRole.OWNER,
        sortOrder = 0,
        isPinned = false,
        syncState = SyncState.SYNCED,
    )

    @Test
    @DisplayName("空白查询匹配一切——调用方因此不需要写「空就别过滤」的分支")
    fun blankQueryMatchesEverything() {
        assertTrue(card().matches(""))
        assertTrue(card().matches("   "))
    }

    @Test
    @DisplayName("匹配标题，大小写不敏感")
    fun matchesTitleCaseInsensitively() {
        assertTrue(card(title = "REWE Payback").matches("rewe"))
        assertTrue(card(title = "rewe payback").matches("REWE"))
        assertTrue(card(title = "REWE Payback").matches("payback"))
    }

    @Test
    @DisplayName("匹配商家名")
    fun matchesMerchantLabel() {
        assertTrue(card(title = "Meine Karte", merchantLabel = "DM").matches("dm"))
    }

    @Test
    @DisplayName("商家名为空时不崩")
    fun handlesNullMerchantLabel() {
        assertFalse(card(title = "Meine Karte", merchantLabel = null).matches("dm"))
    }

    @Test
    @DisplayName("查询词两端的空白被忽略")
    fun trimsTheQuery() {
        assertTrue(card(title = "REWE").matches("  rewe  "))
    }

    // ------------------------------------------------------------------ 德语

    /**
     * ⚠️⚠️ 本文件存在的**主要**理由。
     *
     * SQLite 的 `LIKE` 内建大小写折叠只覆盖 ASCII，所以同样的查询走 SQL
     * 会**匹配不到**这几条。主要市场是德国，而带变音符号的商家名到处都是
     * （Müller、Höffner、Käfer…）。
     */
    @Test
    @DisplayName("德语变音符号的大小写能互相匹配")
    fun matchesGermanUmlautsAcrossCase() {
        assertTrue(card(title = "Müller").matches("müller"))
        assertTrue(card(title = "müller").matches("MÜLLER"))
        assertTrue(card(title = "Höffner").matches("höff"))
        assertTrue(card(title = "Käfer").matches("KÄFER"))
        assertTrue(card(title = "ÖAMTC").matches("öamtc"))
    }

    /**
     * 这条**记录一个已知的不支持**，不是在庆祝它：JVM 的 `lowercase` 不把
     * `ß` 折成 `ss`，所以搜 `"strasse"` 找不到 `"Straße"`。
     *
     * 真要支持得引 ICU 的折叠表 —— 为一个边角情形新开一个依赖不划算，
     * 而用户搜 `"stra"` 仍然找得到（下一条断言）。
     * 哪天有人报了这个 bug，改 `CardSearch.normalize` 一处即可，
     * 而这条测试会告诉他改对了没有。
     */
    @Test
    @DisplayName("ß 不折成 ss——已知不支持，但前缀仍能搜到")
    fun sharpSDoesNotFoldToDoubleS() {
        assertFalse(card(title = "Bahnhofstraße").matches("strasse"))
        assertTrue(card(title = "Bahnhofstraße").matches("stra"))
        assertTrue(card(title = "Bahnhofstraße").matches("STRAßE"))
    }

    /**
     * 土耳其语的 `I` 小写是无点的 `ı`。用默认 locale 做 `lowercase` 的话，
     * 同一份数据在土耳其语设备上会搜不到 —— 这是 JDK 最经典的 locale 陷阱之一，
     * 而它只在那一种设备上现形，本机永远测不出来。
     *
     * `CardSearch.normalize` 显式钉死德语，所以这条在任何设备上都成立。
     */
    @Test
    @DisplayName("大写 I 的折叠不受设备 locale 影响")
    fun dottedCapitalIFoldsConsistently() {
        val previous = java.util.Locale.getDefault()
        try {
            java.util.Locale.setDefault(java.util.Locale.forLanguageTag("tr"))

            assertTrue(card(title = "ITF Karte").matches("itf"))
        } finally {
            java.util.Locale.setDefault(previous)
        }
    }

    // ------------------------------------------------------------------ 不搜什么

    /**
     * 备注最长 2000 字（§7.5），内容与「找哪张卡」无关；
     * 码值是一串数字，搜它只会误命中（搜 "4" 命中半个钱包）。
     */
    @Test
    @DisplayName("不搜备注，也不搜码值")
    fun doesNotSearchNoteOrBarcodeValue() {
        assertFalse(card(note = "Geheimnotiz").matches("geheim"))
        assertFalse(card().matches("4012345678901"))
    }
}
