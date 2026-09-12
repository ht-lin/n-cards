package de.ncards.core.barcode.render

import de.ncards.core.barcode.BarcodeFixtures
import de.ncards.core.model.barcode.BarcodeDimension
import de.ncards.core.model.barcode.BarcodeFormat
import org.junit.jupiter.api.Assertions.assertEquals
import org.junit.jupiter.api.Assertions.assertTrue
import org.junit.jupiter.api.DisplayName
import org.junit.jupiter.api.Test
import org.junit.jupiter.params.ParameterizedTest
import org.junit.jupiter.params.provider.EnumSource

@DisplayName("BarcodeRasterizer")
class BarcodeRasterizerTest {
    private val rasterizer = BarcodeRasterizer()

    private fun rasterOf(
        format: BarcodeFormat,
        widthPx: Int = 1200,
        heightPx: Int = 600,
    ): BarcodeRaster {
        val outcome = rasterizer.rasterize(format, BarcodeFixtures.of(format).payload, widthPx, heightPx)
        return (outcome as RasterOutcome.Success).raster
    }

    /**
     * §10.1：「**必须**强制白底黑码，无论 App 主题是深色还是浅色。
     * 深色模式下的条码是扫码失败的经典原因。」
     *
     * 这里能断言得这么干脆，正是因为颜色写死在 [BarcodeRasterizer] 的两个常量里、
     * 不从 `MaterialTheme` 取 —— 栅格化这一层根本够不着主题。
     */
    @ParameterizedTest(name = "{0}")
    @EnumSource(value = BarcodeFormat::class, names = ["UNKNOWN"], mode = EnumSource.Mode.EXCLUDE)
    @DisplayName("只有纯黑与纯白两种像素，且四角必为白")
    fun rendersBlackOnWhiteOnly(format: BarcodeFormat) {
        val raster = rasterOf(format)

        assertEquals(
            setOf(BarcodeRasterizer.WHITE, BarcodeRasterizer.BLACK),
            raster.pixels.toSortedSet().toSet(),
            "$format 出现了黑白以外的像素",
        )

        val corners = listOf(
            0,
            raster.width - 1,
            (raster.height - 1) * raster.width,
            raster.height * raster.width - 1,
        )
        corners.forEach { index ->
            assertEquals(BarcodeRasterizer.WHITE, raster.pixels[index], "$format 的角像素不是白的")
        }
    }

    @ParameterizedTest(name = "{0}")
    @EnumSource(value = BarcodeFormat::class, names = ["UNKNOWN"], mode = EnumSource.Mode.EXCLUDE)
    @DisplayName("像素数组长度恰为 width * height，且尺寸不超过请求值")
    fun rasterDimensionsAreSelfConsistent(format: BarcodeFormat) {
        val raster = rasterOf(format, widthPx = 1200, heightPx = 600)

        assertEquals(raster.width * raster.height, raster.pixels.size)
        assertTrue(raster.width <= 1200, "$format 的宽 ${raster.width} 超过了请求的 1200")
        assertTrue(raster.height <= 600, "$format 的高 ${raster.height} 超过了请求的 600")
    }

    /**
     * **防模糊的真正断言。**
     *
     * §10.1 要求按目标尺寸生成、不要小图放大，理由是边缘模糊会让扫码枪读不出。
     * 「模块宽必须是整数像素」是那句话的可检验形式：只要某一段暗像素的游程不是
     * [BarcodeRaster.moduleSizePx] 的整数倍，就说明有非整数缩放混了进来。
     */
    @Test
    @DisplayName("一维码的暗条游程全是模块宽的整数倍")
    fun oneDimensionalRunsAreWholeModules() {
        val raster = rasterOf(BarcodeFormat.EAN_13, widthPx = 1000, heightPx = 300)
        val midRow = raster.height / 2

        var run = 0
        for (x in 0 until raster.width) {
            val dark = raster.pixels[midRow * raster.width + x] == BarcodeRasterizer.BLACK
            if (dark) {
                run++
            } else if (run > 0) {
                assertEquals(0, run % raster.moduleSizePx, "在 x=$x 处出现了 $run px 的暗条，不是模块宽的整数倍")
                run = 0
            }
        }
    }

    /**
     * §10.1：「1D 条码：`margin` 至少 10 模块宽（静区）。」
     *
     * 断的是**实际白边像素数 ÷ 模块宽**，不是「我们传了什么 hint」—— 后者证明不了任何事，
     * 尤其因为 ZXing 对这个 hint 有五套互不相同的语义（见 QuietZone 的注释）。
     */
    @ParameterizedTest(name = "{0}")
    @EnumSource(
        value = BarcodeFormat::class,
        names = ["EAN_13", "EAN_8", "UPC_A", "UPC_E", "CODE_128", "CODE_39", "CODE_93", "ITF", "CODABAR"],
    )
    @DisplayName("一维码左右两侧的实际静区都 ≥ 10 模块")
    fun oneDimensionalQuietZoneIsAtLeastTenModules(format: BarcodeFormat) {
        val raster = rasterOf(format, widthPx = 1000, heightPx = 300)
        val midRow = raster.height / 2

        fun pixelAt(x: Int) = raster.pixels[midRow * raster.width + x]

        var left = 0
        while (left < raster.width && pixelAt(left) == BarcodeRasterizer.WHITE) left++
        var right = 0
        while (right < raster.width && pixelAt(raster.width - 1 - right) == BarcodeRasterizer.WHITE) right++

        assertTrue(
            left / raster.moduleSizePx >= 10,
            "$format 左静区只有 ${left / raster.moduleSizePx} 模块",
        )
        assertTrue(
            right / raster.moduleSizePx >= 10,
            "$format 右静区只有 ${right / raster.moduleSizePx} 模块",
        )
    }

    @Test
    @DisplayName("一维码的高度自由拉伸到请求值（T-154 的横屏放大靠这条）")
    fun oneDimensionalHeightFollowsTheRequest() {
        assertEquals(300, rasterOf(BarcodeFormat.EAN_13, 1000, 300).height)
        assertEquals(900, rasterOf(BarcodeFormat.EAN_13, 1000, 900).height)
    }

    /**
     * 二维码保持符号自身的宽高比，**不**把请求的矩形填满。
     *
     * QR / Aztec 的符号是方的，所以出来是方的；DataMatrix 被强制成方形符号
     * （理由见 BarcodeRasterizer.hintsFor）；PDF417 本来就是扁的，那就保持扁的。
     * 让 ZXing 自己去填满请求矩形的话，一个 1080×1920 的 QR 请求会得到一张
     * 8 MB、一多半是白边的图。
     */
    @Test
    @DisplayName("方形符号在长方形请求里保持方形，不被拉伸也不填满")
    fun squareSymbolsStaySquare() {
        listOf(BarcodeFormat.QR_CODE, BarcodeFormat.AZTEC, BarcodeFormat.DATA_MATRIX).forEach { format ->
            val raster = rasterOf(format, widthPx = 1080, heightPx = 1920)
            assertEquals(raster.width, raster.height, "$format 不是方的")
            assertTrue(raster.width <= 1080, "$format 超出了较短的那条边")
        }
    }

    @Test
    @DisplayName("PDF417 保持自身的扁宽比，不被撑成方形")
    fun pdf417KeepsItsIntrinsicAspect() {
        val raster = rasterOf(BarcodeFormat.PDF_417, widthPx = 1200, heightPx = 1200)
        assertTrue(raster.width > raster.height, "PDF417 应当明显比高要宽，实际 ${raster.width}x${raster.height}")
    }

    // ---------------------------------------------------------------- 失败分支

    @Test
    @DisplayName("UNKNOWN 给 UnsupportedFormat，不抛异常")
    fun unknownFormatIsUnsupported() {
        assertEquals(
            RasterOutcome.UnsupportedFormat,
            rasterizer.rasterize(BarcodeFormat.UNKNOWN, "whatever", 1000, 300),
        )
    }

    /**
     * 每一条都对应 ZXing 一类真实的 writer 异常。收敛成一个不带原因的
     * [RasterOutcome.InvalidPayload] 是刻意的 —— 异常消息里嵌着码值片段，
     * 既不能记日志也不该解析（见 BarcodeRasterizer 的注释）。
     */
    @Test
    @DisplayName("各种畸形载荷都收敛成 InvalidPayload，绝不抛出去")
    fun malformedPayloadsBecomeInvalidPayload() {
        val cases = listOf(
            "EAN-13 校验位不对" to (BarcodeFormat.EAN_13 to "4006381333930"),
            "EAN-13 位数不对" to (BarcodeFormat.EAN_13 to "123"),
            "EAN-13 非数字" to (BarcodeFormat.EAN_13 to "400638133393X"),
            "ITF 奇数位" to (BarcodeFormat.ITF to "1234567"),
            // ⚠️ 起止符**不匹配**（有头无尾）才是 Codabar 的非法输入。
            // 两头都没有起止符是**合法**的 —— CodaBarWriter 会自动补上默认的 A…A，
            // 所以「123456789」渲染得出来。T-155 做手动输入校验时要知道这一点：
            // 「必须带起止符」不是 ZXing 的规则，想要的话得自己加。
            "Codabar 起止符不配对" to (BarcodeFormat.CODABAR to "A123456789"),
            // ⚠️ 小写字母是**合法**的：Code39Writer 撞到字符集外的字符时会自动切到
            // 「扩展全 ASCII 模式」。真正编不出来的是**非 ASCII** —— 德语变音符号
            // 正是这个 App 最可能碰上的那一类。
            "Code 39 非 ASCII 字符" to (BarcodeFormat.CODE_39 to "NCARDSÄ"),
            "Code 39 超长（>80）" to (BarcodeFormat.CODE_39 to "A".repeat(81)),
            "空载荷" to (BarcodeFormat.CODE_128 to ""),
            "QR 容量超限" to (BarcodeFormat.QR_CODE to "x".repeat(10_000)),
            "PDF417 容量超限" to (BarcodeFormat.PDF_417 to "x".repeat(10_000)),
        )
        cases.forEach { (why, case) ->
            val (format, payload) = case
            assertEquals(
                RasterOutcome.InvalidPayload,
                rasterizer.rasterize(format, payload, 1200, 600),
                "$why：期望 InvalidPayload",
            )
        }
    }

    /**
     * §10.1 的「不要生成小图再放大」在这一侧的落点：与其交出一张模块宽不足 2 px
     * 的糊图，不如告诉调用方「要多大」。
     */
    @Test
    @DisplayName("尺寸不够时给 TooSmall，且建议尺寸喂回去真的能成功")
    fun tooSmallSuggestsAWorkableSize() {
        val payload = BarcodeFixtures.of(BarcodeFormat.EAN_13).payload
        val tooSmall = rasterizer.rasterize(BarcodeFormat.EAN_13, payload, 50, 50) as RasterOutcome.TooSmall

        val retry = rasterizer.rasterize(
            BarcodeFormat.EAN_13,
            payload,
            tooSmall.minimumWidthPx,
            tooSmall.minimumHeightPx.coerceAtLeast(1),
        )
        assertTrue(retry is RasterOutcome.Success, "按建议尺寸重试仍然失败：$retry")
    }

    @Test
    @DisplayName("非正的目标尺寸也走 TooSmall，不会除以零")
    fun nonPositiveSizesAreRejected() {
        listOf(0 to 100, 100 to 0, -1 to -1).forEach { (w, h) ->
            val outcome = rasterizer.rasterize(BarcodeFormat.QR_CODE, "x", w, h)
            assertTrue(outcome is RasterOutcome.TooSmall, "$w x $h 应当给 TooSmall，实际 $outcome")
        }
    }

    @Test
    @DisplayName("模块宽至少 2 px（§10.1 图片录入那节用的就是这个数）")
    fun moduleWidthNeverDropsBelowTwoPixels() {
        BarcodeFormat.renderable.forEach { format ->
            val height = if (format.dimension == BarcodeDimension.ONE_D) 300 else 1200
            val raster = rasterOf(format, widthPx = 1200, heightPx = height)
            assertTrue(raster.moduleSizePx >= 2, "$format 的模块只有 ${raster.moduleSizePx} px")
        }
    }
}
