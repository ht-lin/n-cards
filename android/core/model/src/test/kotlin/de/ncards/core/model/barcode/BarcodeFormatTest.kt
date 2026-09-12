package de.ncards.core.model.barcode

import org.junit.jupiter.api.Assertions.assertEquals
import org.junit.jupiter.api.Assertions.assertFalse
import org.junit.jupiter.api.Assertions.assertTrue
import org.junit.jupiter.api.DisplayName
import org.junit.jupiter.api.Test

@DisplayName("BarcodeFormat")
class BarcodeFormatTest {
    /**
     * 契约的值域钉死在这里。
     *
     * ⚠️ **刻意硬编码，而不是跟 `core:network:api` 的生成枚举比对** ——
     * `core:model` 是纯 Kotlin 模块，看不见那个模块（那是 §12.3 的结构约束）。
     * 这份字面清单的真相源是 `docs/api/openapi.yaml` 的 `BarcodeFormat` schema。
     *
     * 这条测试守的是一个**没有症状**的错误：把某个常量的 `wireName` 拼错或改名，
     * 编译照过、单测照过，然后所有已存在的 Room 行在下次读取时全部变成
     * `UNKNOWN` —— 用户看到的是「我的卡忽然都画不出来了」，而 diff 里只有一个字母。
     */
    private val contractWireNames = setOf(
        "EAN_13",
        "EAN_8",
        "UPC_A",
        "UPC_E",
        "CODE_128",
        "CODE_39",
        "CODE_93",
        "ITF",
        "CODABAR",
        "QR_CODE",
        "AZTEC",
        "PDF_417",
        "DATA_MATRIX",
    )

    @Test
    @DisplayName("十三个可渲染码制的 wireName 与契约逐字一致")
    fun wireNamesMatchTheContract() {
        assertEquals(
            contractWireNames,
            BarcodeFormat.renderable.map(BarcodeFormat::wireName).toSet(),
        )
        assertEquals(13, BarcodeFormat.renderable.size)
    }

    @Test
    @DisplayName("每个可渲染码制都能由自己的 wireName 还原")
    fun fromWireRoundTripsEveryRenderableFormat() {
        BarcodeFormat.renderable.forEach { format ->
            assertEquals(format, BarcodeFormat.fromWire(format.wireName), "wireName=${format.wireName}")
        }
    }

    /**
     * §13.6 允许服务端新增枚举值，老客户端**不得因此崩**。兜底必须是一个值，
     * 不是异常，也不是 null。
     */
    @Test
    @DisplayName("认不出来的一律给 UNKNOWN，不抛异常")
    fun unknownInputsFallBackToUnknown() {
        assertEquals(BarcodeFormat.UNKNOWN, BarcodeFormat.fromWire(null))
        assertEquals(BarcodeFormat.UNKNOWN, BarcodeFormat.fromWire(""))
        assertEquals(BarcodeFormat.UNKNOWN, BarcodeFormat.fromWire("GS1_DATABAR"))
        assertEquals(BarcodeFormat.UNKNOWN, BarcodeFormat.fromWire("unknown_default_open_api"))
    }

    /**
     * 生成的 `core:network:api` 枚举的 `decode()` 是大小写不敏感的；这里刻意不是。
     * 一个小写的 `ean_13` 进了库就是数据出了问题，悄悄认下来只会让它更晚暴露。
     */
    @Test
    @DisplayName("大小写敏感：小写的 ean_13 不被认作 EAN_13")
    fun fromWireIsCaseSensitive() {
        assertEquals(BarcodeFormat.UNKNOWN, BarcodeFormat.fromWire("ean_13"))
        assertEquals(BarcodeFormat.UNKNOWN, BarcodeFormat.fromWire("Qr_Code"))
    }

    @Test
    @DisplayName("UNKNOWN 是第 14 个常量，且不可渲染")
    fun unknownIsTheFourteenthConstant() {
        assertEquals(14, BarcodeFormat.entries.size)
        assertFalse(BarcodeFormat.UNKNOWN.isRenderable)
        assertTrue(BarcodeFormat.UNKNOWN !in BarcodeFormat.renderable)
        assertEquals(BarcodeDimension.UNKNOWN, BarcodeFormat.UNKNOWN.dimension)
    }

    /**
     * 维度不是装饰：渲染器靠它决定静区宽度与「高度能不能自由拉伸」。
     * 分错一个，那个码制的静区就会用错一套数字，而症状只会出现在收银台前。
     */
    @Test
    @DisplayName("九个一维、四个二维，分类与 §1.3 的码制表一致")
    fun dimensionsMatchTheSpecTable() {
        val oneD = BarcodeFormat.renderable.filter { it.dimension == BarcodeDimension.ONE_D }
        val twoD = BarcodeFormat.renderable.filter { it.dimension == BarcodeDimension.TWO_D }

        assertEquals(
            setOf("EAN_13", "EAN_8", "UPC_A", "UPC_E", "CODE_128", "CODE_39", "CODE_93", "ITF", "CODABAR"),
            oneD.map(BarcodeFormat::wireName).toSet(),
        )
        assertEquals(
            setOf("QR_CODE", "AZTEC", "PDF_417", "DATA_MATRIX"),
            twoD.map(BarcodeFormat::wireName).toSet(),
        )
    }

    /** 与契约的 `barcode_value.maxLength` 及后端 `ncards.limits.barcode_payload_bytes` 是同一个数。 */
    @Test
    @DisplayName("载荷上限与契约 / §7.5 一致")
    fun payloadLimitMatchesTheContract() {
        assertEquals(1024, BarcodeFormat.MAX_PAYLOAD_BYTES)
    }
}
