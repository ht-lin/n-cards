package de.ncards.core.barcode.format

import de.ncards.core.barcode.render.BarcodeRasterizer
import de.ncards.core.model.barcode.BarcodeFormat
import org.junit.jupiter.api.Assertions.assertEquals
import org.junit.jupiter.api.Assertions.assertNull
import org.junit.jupiter.api.DisplayName
import org.junit.jupiter.api.Nested
import org.junit.jupiter.api.Test
import org.junit.jupiter.api.assertAll

@DisplayName("码值校验器")
class BarcodePayloadValidatorTest {
    /**
     * ========================================================================
     * 本文件最重要的一条 —— 它是校验器**正确性的定义**
     * ========================================================================
     * 本模块对外承诺的是「说合法就画得出来」。这条把承诺写成了断言：
     *
     * > `validateBarcodePayload(f, v) == null` **当且仅当** rasterizer 编得出 `(f, v)`
     *
     * 声明式规则里任何一条写错，都会在这里当场红，而不是变成一个
     * 「表单说没问题、全屏条码页一片空白」的投诉 —— 那是最难查的一类，
     * 因为两处代码各自看起来都对。
     *
     * 它同时也是 ZXing 的**升版回归测试**：`ZXING_LINEAR_MAX_LENGTH = 80` 这类
     * 「从源码里读出来、ZXing 并不作为 API 承诺」的数字，哪天变了这里会红。
     */
    @Nested
    @DisplayName("与 ZXing 一致（校验器的正确性定义）")
    inner class AgreesWithZxing {
        @Test
        @DisplayName("每个码制的每条语料，校验器与 rasterizer 给出同一个答案")
        fun validatorAgreesWithTheEncoderOnEverySample() {
            val disagreements =
                CORPUS.flatMap { (format, samples) ->
                    samples.mapNotNull { sample ->
                        val saysValid = validateBarcodePayload(format, sample) == null
                        val canEncode = BarcodeRasterizer.canEncode(format, sample)

                        if (saysValid == canEncode) {
                            null
                        } else {
                            "$format / ${sample.describe()}：校验器说 ${verdict(saysValid)}，" +
                                "ZXing 说 ${verdict(canEncode)}"
                        }
                    }
                }

            assertEquals(emptyList<String>(), disagreements, "校验器与渲染器对同一个码值不同意")
        }

        private fun verdict(valid: Boolean) = if (valid) "行" else "不行"
    }

    /**
     * T-152 的落地记录点名交办了三条它实测出来的 ZXing 行为，说它们「会直接影响你的规则」。
     * 上面那条性质测试已经覆盖了它们，但这三条单独写出来 —— 它们是**反直觉**的，
     * 下一个人最可能「顺手修正」的就是这三处。
     */
    @Nested
    @DisplayName("T-152 交办的三条反直觉行为")
    inner class HandedOverFromT152 {
        /**
         * 最容易写错的一条：以为 EAN-13 就得是 13 位。
         * 包装上印的是 13 位，而很多贴纸只印 12 位主体 —— 两种都要收。
         */
        @Test
        @DisplayName("EAN / UPC 少一位是合法的（ZXing 自己补校验位）")
        fun eanAcceptsThePayloadWithoutItsCheckDigit() {
            assertAll(
                { assertNull(validateBarcodePayload(BarcodeFormat.EAN_13, "401234567890")) },
                { assertNull(validateBarcodePayload(BarcodeFormat.EAN_13, "4012345678901")) },
                { assertNull(validateBarcodePayload(BarcodeFormat.EAN_8, "1234567")) },
                { assertNull(validateBarcodePayload(BarcodeFormat.UPC_A, "01234567890")) },
            )
        }

        @Test
        @DisplayName("给满位数时校验位必须对")
        fun fullLengthPayloadMustCarryTheRightCheckDigit() {
            assertEquals(
                BarcodePayloadProblem.ChecksumMismatch,
                validateBarcodePayload(BarcodeFormat.EAN_13, "4012345678902"),
            )
        }

        /**
         * Code 39 的小写**合法**（ZXing 切到扩展 ASCII 模式）。
         * 真正编不出来的是非 ASCII —— 对德语市场就是变音字母，用户会真的输进来。
         */
        @Test
        @DisplayName("Code 39 小写合法，德语变音字母不合法")
        fun code39AcceptsLowercaseButNotUmlauts() {
            assertNull(validateBarcodePayload(BarcodeFormat.CODE_39, "rewe-payback"))

            assertEquals(
                BarcodePayloadProblem.IllegalCharacters("ä"),
                validateBarcodePayload(BarcodeFormat.CODE_39, "bäcker"),
            )
        }

        /**
         * 「不带起止符」是合法的（writer 自动补 `A…A`）；**落单的**那一个才非法。
         * T-152 的原话：「T-155 若要求『必须带起止符』，那是我们自己的规则，不是 ZXing 的」。
         */
        @Test
        @DisplayName("Codabar 不带起止符合法，落单的起止符不合法")
        fun codabarGuardsAreOptionalButMustBePaired() {
            assertNull(validateBarcodePayload(BarcodeFormat.CODABAR, "12345"))
            assertNull(validateBarcodePayload(BarcodeFormat.CODABAR, "A12345A"))

            assertEquals(
                BarcodePayloadProblem.IllegalCharacters("A"),
                validateBarcodePayload(BarcodeFormat.CODABAR, "A12345"),
            )
        }
    }

    @Nested
    @DisplayName("原因")
    inner class Reasons {
        @Test
        @DisplayName("空码值报 Empty —— 但全是空格的不报（空格是合法载荷字符）")
        fun emptyIsReportedButBlankIsNot() {
            assertEquals(BarcodePayloadProblem.Empty, validateBarcodePayload(BarcodeFormat.CODE_128, ""))
            assertNull(validateBarcodePayload(BarcodeFormat.CODE_128, "   "))
        }

        @Test
        @DisplayName("UNKNOWN 报 UnsupportedFormat，不报「字符非法」")
        fun unknownFormatIsItsOwnReason() {
            assertEquals(
                BarcodePayloadProblem.UnsupportedFormat,
                validateBarcodePayload(BarcodeFormat.UNKNOWN, "4012345678901"),
            )
        }

        @Test
        @DisplayName("位数不对时带上允许的位数（文案要填空）")
        fun wrongLengthCarriesTheAllowedLengths() {
            assertEquals(
                BarcodePayloadProblem.WrongLength(listOf(12, 13)),
                validateBarcodePayload(BarcodeFormat.EAN_13, "40123456789"),
            )
        }

        @Test
        @DisplayName("EAN 里的字母报「字符非法」而不是「位数不对」——先说最具体的那条")
        fun charsetIsCheckedBeforeLength() {
            assertEquals(
                BarcodePayloadProblem.IllegalCharacters("X"),
                validateBarcodePayload(BarcodeFormat.EAN_13, "401234567890X"),
            )
        }

        @Test
        @DisplayName("ITF 的奇数位报 OddLength")
        fun itfRejectsOddLength() {
            assertEquals(BarcodePayloadProblem.OddLength, validateBarcodePayload(BarcodeFormat.ITF, "12345"))
            assertNull(validateBarcodePayload(BarcodeFormat.ITF, "123456"))
        }

        /**
         * ⚠️ 上限**只对 Code 39 / Code 93 / ITF 成立**，不是「一维码都是 80」。
         *
         * 第一版把 Code 128 也写成了 80（四个一维 writer 的源码里都有一句
         * 「between 1 and 80」），而上面那条一致性性质测试当场把它否了 ——
         * `Code128Writer` 与 `CodaBarWriter` 实测**没有**长度上限。
         * 这两条断言合起来钉住这个区别，免得下一个人「顺手统一」回去。
         */
        @Test
        @DisplayName("Code 39 / 93 / ITF 超过 80 个字符报 TooLong；Code 128 与 Codabar 没有上限")
        fun onlySomeLinearFormatsAreCappedAtEighty() {
            assertNull(validateBarcodePayload(BarcodeFormat.CODE_39, "A".repeat(80)))
            assertEquals(
                BarcodePayloadProblem.TooLong(80),
                validateBarcodePayload(BarcodeFormat.CODE_39, "A".repeat(81)),
            )

            assertNull(validateBarcodePayload(BarcodeFormat.CODE_128, "A".repeat(81)))
            assertNull(validateBarcodePayload(BarcodeFormat.CODABAR, "1".repeat(81)))
        }

        /**
         * 二维码的容量取决于纠错级别与编码模式，算不出一个能写进表里的上限 ——
         * 所以它由「真的编一次」兜底，报的是 [BarcodePayloadProblem.NotEncodable]。
         * 这条守的是那个兜底**确实接上了**：去掉它的话，超容量的 QR 会一路存进库。
         */
        @Test
        @DisplayName("二维码容量超限报 NotEncodable（声明式规则算不出这个上限）")
        fun twoDimensionalCapacityFallsBackToTheEncodeProbe() {
            assertEquals(
                BarcodePayloadProblem.NotEncodable,
                validateBarcodePayload(BarcodeFormat.QR_CODE, "A".repeat(5000)),
            )
        }
    }

    @Nested
    @DisplayName("覆盖")
    inner class Coverage {
        /** 加第 14 个码制时，它的语料也必须补上 —— 否则上面那条性质测试就成了空转。 */
        @Test
        @DisplayName("每个码制都有语料")
        fun everyFormatHasCorpus() {
            assertEquals(BarcodeFormat.entries.toSet(), CORPUS.keys)
        }
    }

    private fun String.describe() = if (length > 24) "「${take(24)}…」($length 字符)" else "「$this」"

    private companion object {
        /**
         * 每个码制一组语料，**合法与非法都要有**。
         *
         * ⚠️ 加语料的时候不要去想「它应该合法吗」—— 那条性质测试比较的是校验器与 ZXing，
         * 不是校验器与你的预期。想不清楚的边界正是最该丢进来的。
         */
        val CORPUS: Map<BarcodeFormat, List<String>> =
            mapOf(
                BarcodeFormat.EAN_13 to
                    listOf("", "4012345678901", "4012345678902", "401234567890", "40123456789", "401234567890X"),
                BarcodeFormat.EAN_8 to listOf("", "12345670", "12345671", "1234567", "123456"),
                BarcodeFormat.UPC_A to listOf("", "012345678905", "012345678900", "01234567890", "0123456789"),
                BarcodeFormat.UPC_E to listOf("", "01234565", "0123456", "012345"),
                BarcodeFormat.CODE_128 to
                    listOf(
                        "",
                        " ",
                        "REWE-2024",
                        "rewe-2024",
                        "bäcker",
                        "A".repeat(80),
                        "A".repeat(81),
                        "A".repeat(200),
                    ),
                BarcodeFormat.CODE_39 to
                    listOf("", " ", "REWE-2024", "rewe-payback", "bäcker", "A".repeat(80), "A".repeat(81)),
                BarcodeFormat.CODE_93 to listOf("", " ", "REWE-2024", "rewe", "bäcker", "A".repeat(81)),
                BarcodeFormat.ITF to listOf("", "123456", "12345", "1234567890123456", "12345X"),
                BarcodeFormat.CODABAR to
                    listOf("", "12345", "A12345A", "A12345", "12345A", "12-34", "12345X", "1".repeat(81)),
                BarcodeFormat.QR_CODE to listOf("", "https://n-cards.de/x", "bäcker 🛒", "A".repeat(5000)),
                BarcodeFormat.AZTEC to listOf("", "NCARDS-AZ-1", "bäcker"),
                BarcodeFormat.PDF_417 to listOf("", "NCARDS-PDF-1", "bäcker"),
                BarcodeFormat.DATA_MATRIX to listOf("", "NCARDS-DM-1", "bäcker"),
                BarcodeFormat.UNKNOWN to listOf("", "4012345678901"),
            )
    }
}
