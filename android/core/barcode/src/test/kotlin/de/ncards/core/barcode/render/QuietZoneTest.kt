package de.ncards.core.barcode.render

import de.ncards.core.model.barcode.BarcodeDimension
import de.ncards.core.model.barcode.BarcodeFormat
import org.junit.jupiter.api.Assertions.assertEquals
import org.junit.jupiter.api.Assertions.assertTrue
import org.junit.jupiter.api.DisplayName
import org.junit.jupiter.api.Test

@DisplayName("QuietZone（静区表）")
class QuietZoneTest {
    /**
     * §10.1：「1D 条码：`margin` 至少 10 模块宽（静区），否则部分扫码枪拒读。」
     *
     * 这条测试把那句规格变成可执行的断言。注意 ZXing 自己给不到这个数 ——
     * 一维 writer 的默认值是两侧总和 10（每侧 5），EAN/UPC 家族更是只有 9（每侧 4.5）。
     * 静区由我们自己加，正是因为这个。
     */
    @Test
    @DisplayName("九种一维码每侧至少 10 模块（§10.1）")
    fun oneDimensionalFormatsMeetTheSpecMinimum() {
        val oneD = BarcodeFormat.renderable.filter { it.dimension == BarcodeDimension.ONE_D }
        assertEquals(9, oneD.size)
        oneD.forEach { format ->
            assertTrue(
                QuietZone.modulesPerSideOf(format) >= 10,
                "$format 的静区是 ${QuietZone.modulesPerSideOf(format)} 模块，低于 §10.1 要求的 10",
            )
        }
    }

    @Test
    @DisplayName("四种二维码各有静区，且都不为 0")
    fun twoDimensionalFormatsAllHaveAQuietZone() {
        val twoD = BarcodeFormat.renderable.filter { it.dimension == BarcodeDimension.TWO_D }
        assertEquals(4, twoD.size)
        // ⚠️ ZXing 给 Aztec 与 DataMatrix 的静区是 0，而实测静区为 0 时它自己的
        // reader 一个都解不出来。这条断言守的就是那个洞。
        twoD.forEach { format ->
            assertTrue(QuietZone.modulesPerSideOf(format) > 0, "$format 没有静区")
        }
        assertEquals(4, QuietZone.modulesPerSideOf(BarcodeFormat.QR_CODE))
    }

    @Test
    @DisplayName("表对全部 14 个枚举值都有答案")
    fun tableCoversEveryFormat() {
        BarcodeFormat.entries.forEach { QuietZone.modulesPerSideOf(it) }
        // UNKNOWN 画不出来，取不到静区这一步。
        assertEquals(0, QuietZone.modulesPerSideOf(BarcodeFormat.UNKNOWN))
    }
}
