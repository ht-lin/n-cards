package de.ncards.core.model.card

import de.ncards.core.model.barcode.BarcodeFormat
import org.junit.jupiter.api.Assertions.assertEquals
import org.junit.jupiter.api.Assertions.assertNull
import org.junit.jupiter.api.Assertions.assertTrue
import org.junit.jupiter.api.DisplayName
import org.junit.jupiter.api.Nested
import org.junit.jupiter.api.Test

@DisplayName("CardDraft 的长度校验")
class CardDraftTest {
    @Test
    @DisplayName("一张正常的卡没有任何问题")
    fun happyDraftHasNoProblems() {
        assertTrue(draft().problems().isEmpty())
    }

    @Nested
    @DisplayName("必填")
    inner class Required {
        @Test
        @DisplayName("标题全是空白算没填（列表里那一格会是一片空白）")
        fun blankTitleIsMissing() {
            assertEquals(CardDraftProblem.Blank, draft(title = "   ").problems()[CardField.TITLE])
        }

        @Test
        @DisplayName("码值为空算没填")
        fun emptyValueIsMissing() {
            assertEquals(CardDraftProblem.Blank, draft(barcodeValue = "").problems()[CardField.BARCODE_VALUE])
        }

        /**
         * 这一条守的是 `problems()` 里码值那句**刻意**用 `isEmpty` 而不是 `isBlank`。
         * 空格在 Code 39 / Code 128 里是合法载荷字符 —— 用 `isBlank` 的话，
         * 一个真的由空格组成的码值会被本机判成「没填」，而它其实编得出来。
         */
        @Test
        @DisplayName("全是空格的码值**不**算没填 —— 空格是合法载荷字符")
        fun blankButNonEmptyValueIsNotMissing() {
            assertNull(draft(barcodeValue = "   ").problems()[CardField.BARCODE_VALUE])
        }

        @Test
        @DisplayName("商家名与备注可空，也可以是空串")
        fun optionalFieldsAcceptNullAndEmpty() {
            assertTrue(draft(merchantLabel = null, note = null).problems().isEmpty())
            assertTrue(draft(merchantLabel = "", note = "").problems().isEmpty())
        }
    }

    @Nested
    @DisplayName("上限")
    inner class Limits {
        @Test
        @DisplayName("刚好到上限是合法的，多一个才不合法")
        fun boundariesAreInclusive() {
            assertTrue(draft(title = "a".repeat(100)).problems().isEmpty())

            assertEquals(
                CardDraftProblem.TooLong(100, CardDraftProblem.LimitUnit.CHARACTERS),
                draft(title = "a".repeat(101)).problems()[CardField.TITLE],
            )
        }

        @Test
        @DisplayName("商家名 100 / 备注 2000")
        fun merchantAndNoteLimits() {
            assertTrue(draft(merchantLabel = "a".repeat(100), note = "a".repeat(2000)).problems().isEmpty())

            assertEquals(
                CardDraftProblem.TooLong(100, CardDraftProblem.LimitUnit.CHARACTERS),
                draft(merchantLabel = "a".repeat(101)).problems()[CardField.MERCHANT_LABEL],
            )
            assertEquals(
                CardDraftProblem.TooLong(2000, CardDraftProblem.LimitUnit.CHARACTERS),
                draft(note = "a".repeat(2001)).problems()[CardField.NOTE],
            )
        }

        /**
         * ⚠️ 这一条是本文件的重点。
         *
         * 文本字段按**码点**算而不是 `String.length`（UTF-16 码元）：
         * 一个 emoji 占两个码元，用 `String.length` 的话 50 个 emoji 的标题就被本机拒了 ——
         * 而契约的 `maxLength` 数的是字符，服务端其实收得下 100 个。
         */
        @Test
        @DisplayName("标题按码点算：100 个 emoji 合法（String.length 会是 200）")
        fun titleCountsCodePointsNotUtf16Units() {
            val emojiTitle = "🛒".repeat(100)

            assertEquals(200, emojiTitle.length, "前提：一个 emoji 是两个 UTF-16 码元")
            assertTrue(emojiTitle.codePointCount(0, emojiTitle.length) == 100)

            assertTrue(draft(title = emojiTitle).problems().isEmpty())
            assertEquals(
                CardDraftProblem.TooLong(100, CardDraftProblem.LimitUnit.CHARACTERS),
                draft(title = "🛒".repeat(101)).problems()[CardField.TITLE],
            )
        }

        /**
         * 反方向：码值按 **UTF-8 字节**算（§7.5 / [BarcodeFormat.MAX_PAYLOAD_BYTES]）。
         * 德语变音字母在 UTF-8 里是两个字节，所以 1024 个 `ä` 是 2048 字节 —— 超限。
         */
        @Test
        @DisplayName("码值按 UTF-8 字节算：1024 个变音字母超限（字符数正好是 1024）")
        fun barcodeValueCountsUtf8Bytes() {
            val umlauts = "ä".repeat(1024)

            assertEquals(1024, umlauts.length, "前提：字符数正好卡在上限上")
            assertEquals(2048, umlauts.toByteArray(Charsets.UTF_8).size)

            assertEquals(
                CardDraftProblem.TooLong(1024, CardDraftProblem.LimitUnit.BYTES),
                draft(barcodeValue = umlauts).problems()[CardField.BARCODE_VALUE],
            )

            // 纯 ASCII 时字节数 == 字符数，边界仍然是包含的。
            assertTrue(draft(barcodeValue = "1".repeat(1024)).problems().isEmpty())
            assertEquals(
                CardDraftProblem.TooLong(1024, CardDraftProblem.LimitUnit.BYTES),
                draft(barcodeValue = "1".repeat(1025)).problems()[CardField.BARCODE_VALUE],
            )
        }

        @Test
        @DisplayName("上限常量与契约一致")
        fun limitsMatchTheContract() {
            assertEquals(100, CardLimits.TITLE_MAX_CHARACTERS)
            assertEquals(100, CardLimits.MERCHANT_LABEL_MAX_CHARACTERS)
            assertEquals(2000, CardLimits.NOTE_MAX_CHARACTERS)
            assertEquals(BarcodeFormat.MAX_PAYLOAD_BYTES, CardLimits.BARCODE_VALUE_MAX_BYTES)
        }
    }

    @Test
    @DisplayName("多个字段同时出问题时全都报出来，不是只报第一个")
    fun reportsEveryFieldAtOnce() {
        val problems = draft(title = "", barcodeValue = "", note = "a".repeat(2001)).problems()

        assertEquals(
            setOf(CardField.TITLE, CardField.BARCODE_VALUE, CardField.NOTE),
            problems.keys,
        )
    }

    private fun draft(
        title: String = "REWE Payback",
        merchantLabel: String? = "REWE",
        colorWire: String = "blue_600",
        barcodeFormat: BarcodeFormat = BarcodeFormat.EAN_13,
        barcodeValue: String = "4012345678901",
        note: String? = null,
    ) = CardDraft(
        title = title,
        merchantLabel = merchantLabel,
        colorWire = colorWire,
        barcodeFormat = barcodeFormat,
        barcodeValue = barcodeValue,
        note = note,
        expiresOn = null,
    )
}
