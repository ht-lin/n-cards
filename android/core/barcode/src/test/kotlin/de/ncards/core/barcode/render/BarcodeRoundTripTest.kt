package de.ncards.core.barcode.render

import com.google.zxing.BinaryBitmap
import com.google.zxing.DecodeHintType
import com.google.zxing.MultiFormatReader
import com.google.zxing.RGBLuminanceSource
import com.google.zxing.Result
import com.google.zxing.common.HybridBinarizer
import de.ncards.core.barcode.BarcodeFixtures
import de.ncards.core.barcode.format.BarcodeFormatTable
import de.ncards.core.model.barcode.BarcodeDimension
import de.ncards.core.model.barcode.BarcodeFormat
import org.junit.jupiter.api.Assertions.assertEquals
import org.junit.jupiter.api.DisplayName
import org.junit.jupiter.api.Test
import org.junit.jupiter.params.ParameterizedTest
import org.junit.jupiter.params.provider.EnumSource

/**
 * **验收标准「单测覆盖全部码制的映射往返」的主力。**
 *
 * 把渲染出来的像素喂回 ZXing 自己的 reader，断言解出来的就是喂进去的那个值。
 * 这比「枚举 → ZXing 常量 → 枚举」的表往返强得多：它证明的是**这张图真的能被机器读出来**。
 *
 * 三个「不需要」：不需要设备、不需要 Robolectric、不需要新依赖 ——
 * reader 与 writer 在同一个 `com.google.zxing:core` 里，而 `RGBLuminanceSource`
 * 正好吃我们 [BarcodeRaster.pixels] 那种 ARGB 数组。主源集一个 reader 都不碰，
 * 所以 R8 会把这一半整个剥掉，APK 零增量。
 */
@DisplayName("渲染 → 解码 往返（全部 13 种码制）")
class BarcodeRoundTripTest {
    private val rasterizer = BarcodeRasterizer()

    /**
     * ⚠️ `POSSIBLE_FORMATS` 不是可选项。
     *
     * 不给它的话 `MultiFormatUPCEANReader` 会把 UPC-A 报成「前缀补 0 的 EAN_13」——
     * 那不是我们的 bug，是 UPC-A 与 EAN-13 在符号层面本来就是同一串条。
     */
    private fun decode(
        raster: BarcodeRaster,
        expected: BarcodeFormat,
    ): Result {
        val source = RGBLuminanceSource(raster.width, raster.height, raster.pixels)
        return MultiFormatReader().decode(
            BinaryBitmap(HybridBinarizer(source)),
            mapOf(
                DecodeHintType.POSSIBLE_FORMATS to listOf(BarcodeFormatTable.rowFor(expected).zxing),
                DecodeHintType.TRY_HARDER to true,
                // Codabar 的起止符（A…B）是码值的一部分，writer 要求它们必须在，
                // 但 ZXing 的 reader **默认把它们吃掉**（返回 "123456789"）。
                // 打开这个开关才是真正的等价往返；关着的话这条断言会弱一档。
                DecodeHintType.RETURN_CODABAR_START_END to true,
            ),
        )
    }

    @ParameterizedTest(name = "{0}")
    @EnumSource(value = BarcodeFormat::class, names = ["UNKNOWN"], mode = EnumSource.Mode.EXCLUDE)
    @DisplayName("每个码制渲染出来的图都能被 ZXing 解回原值")
    fun rendersAndDecodesEveryFormat(format: BarcodeFormat) {
        val fixture = BarcodeFixtures.of(format)
        // 尺寸取得宽裕：PDF417 的 1× 模块只有 1 px 宽，太小时它的 reader 最先放弃。
        val widthPx = 1200
        val heightPx = if (format.dimension == BarcodeDimension.ONE_D) 400 else 1200

        val outcome = rasterizer.rasterize(format, fixture.payload, widthPx, heightPx)
        val raster = (outcome as RasterOutcome.Success).raster

        val result = decode(raster, format)
        assertEquals(fixture.decodesTo, result.text, "$format 解出来的文本对不上")
    }

    /**
     * 验收标准点名要在真机上实扫的就是这两个。这里额外**不给任何 hint** 再解一遍，
     * 断言码制本身也能被认出来 —— 也就是超市扫码枪看到它时的处境。
     */
    @Test
    @DisplayName("EAN-13 与 QR 在不给 hint 时也能被无歧义地认出来")
    fun ean13AndQrAreUnambiguousWithoutHints() {
        listOf(BarcodeFormat.EAN_13 to 400, BarcodeFormat.QR_CODE to 1200).forEach { (format, heightPx) ->
            val fixture = BarcodeFixtures.of(format)
            val outcome = rasterizer.rasterize(format, fixture.payload, 1200, heightPx)
            val raster = (outcome as RasterOutcome.Success).raster

            val source = RGBLuminanceSource(raster.width, raster.height, raster.pixels)
            val result = MultiFormatReader().decode(BinaryBitmap(HybridBinarizer(source)))

            assertEquals(BarcodeFormatTable.rowFor(format).zxing, result.barcodeFormat, "$format 被认成了别的码制")
            assertEquals(fixture.decodesTo, result.text)
        }
    }
}
