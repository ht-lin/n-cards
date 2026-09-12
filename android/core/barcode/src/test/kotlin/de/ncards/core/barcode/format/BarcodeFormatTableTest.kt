package de.ncards.core.barcode.format

import com.google.mlkit.vision.barcode.common.Barcode
import de.ncards.core.model.barcode.BarcodeFormat
import org.junit.jupiter.api.Assertions.assertEquals
import org.junit.jupiter.api.Assertions.assertNotEquals
import org.junit.jupiter.api.Assertions.assertNotNull
import org.junit.jupiter.api.Assertions.assertNull
import org.junit.jupiter.api.DisplayName
import org.junit.jupiter.api.Test

@DisplayName("BarcodeFormatTable（§10.1 的三列映射）")
class BarcodeFormatTableTest {
    @Test
    @DisplayName("每个枚举值恰好一行 —— 挡住将来有人给那个 when 加 else")
    fun everyFormatHasExactlyOneRow() {
        assertEquals(BarcodeFormat.entries.size, BarcodeFormatTable.rows.size)
        assertEquals(BarcodeFormat.entries.toSet(), BarcodeFormatTable.rows.map { it.format }.toSet())
    }

    /**
     * ⚠️ **逐个断言，不要写成 `format.name == zxing.name` 的循环。**
     *
     * 那样写等于假设两边命名永远一致，而这正是会漂的地方 —— ML Kit 那一列就写作
     * `FORMAT_PDF417`（没有下划线）而契约是 `PDF_417`。手写一遍才能真的挡住
     * 「复制上一行忘了改常量」这种错。
     */
    @Test
    @DisplayName("ZXing 那一列逐个对上，UNKNOWN 没有 writer")
    fun zxingColumnIsCorrect() {
        fun zxingOf(format: BarcodeFormat) = BarcodeFormatTable.rowFor(format).zxing

        assertEquals(com.google.zxing.BarcodeFormat.EAN_13, zxingOf(BarcodeFormat.EAN_13))
        assertEquals(com.google.zxing.BarcodeFormat.EAN_8, zxingOf(BarcodeFormat.EAN_8))
        assertEquals(com.google.zxing.BarcodeFormat.UPC_A, zxingOf(BarcodeFormat.UPC_A))
        assertEquals(com.google.zxing.BarcodeFormat.UPC_E, zxingOf(BarcodeFormat.UPC_E))
        assertEquals(com.google.zxing.BarcodeFormat.CODE_128, zxingOf(BarcodeFormat.CODE_128))
        assertEquals(com.google.zxing.BarcodeFormat.CODE_39, zxingOf(BarcodeFormat.CODE_39))
        assertEquals(com.google.zxing.BarcodeFormat.CODE_93, zxingOf(BarcodeFormat.CODE_93))
        assertEquals(com.google.zxing.BarcodeFormat.ITF, zxingOf(BarcodeFormat.ITF))
        assertEquals(com.google.zxing.BarcodeFormat.CODABAR, zxingOf(BarcodeFormat.CODABAR))
        assertEquals(com.google.zxing.BarcodeFormat.QR_CODE, zxingOf(BarcodeFormat.QR_CODE))
        assertEquals(com.google.zxing.BarcodeFormat.AZTEC, zxingOf(BarcodeFormat.AZTEC))
        assertEquals(com.google.zxing.BarcodeFormat.PDF_417, zxingOf(BarcodeFormat.PDF_417))
        assertEquals(com.google.zxing.BarcodeFormat.DATA_MATRIX, zxingOf(BarcodeFormat.DATA_MATRIX))

        // MultiFormatWriter 没有 UNKNOWN 的 writer，null 是渲染器识别「画不出来」的依据。
        assertNull(zxingOf(BarcodeFormat.UNKNOWN))
        BarcodeFormat.renderable.forEach { assertNotNull(zxingOf(it), "$it 缺 ZXing 常量") }
    }

    @Test
    @DisplayName("ML Kit 那一列逐个对上（注意 FORMAT_PDF417 没有下划线）")
    fun mlKitColumnIsCorrect() {
        fun mlKitOf(format: BarcodeFormat) = BarcodeFormatTable.rowFor(format).mlKit

        assertEquals(Barcode.FORMAT_EAN_13, mlKitOf(BarcodeFormat.EAN_13))
        assertEquals(Barcode.FORMAT_EAN_8, mlKitOf(BarcodeFormat.EAN_8))
        assertEquals(Barcode.FORMAT_UPC_A, mlKitOf(BarcodeFormat.UPC_A))
        assertEquals(Barcode.FORMAT_UPC_E, mlKitOf(BarcodeFormat.UPC_E))
        assertEquals(Barcode.FORMAT_CODE_128, mlKitOf(BarcodeFormat.CODE_128))
        assertEquals(Barcode.FORMAT_CODE_39, mlKitOf(BarcodeFormat.CODE_39))
        assertEquals(Barcode.FORMAT_CODE_93, mlKitOf(BarcodeFormat.CODE_93))
        assertEquals(Barcode.FORMAT_ITF, mlKitOf(BarcodeFormat.ITF))
        assertEquals(Barcode.FORMAT_CODABAR, mlKitOf(BarcodeFormat.CODABAR))
        assertEquals(Barcode.FORMAT_QR_CODE, mlKitOf(BarcodeFormat.QR_CODE))
        assertEquals(Barcode.FORMAT_AZTEC, mlKitOf(BarcodeFormat.AZTEC))
        assertEquals(Barcode.FORMAT_PDF417, mlKitOf(BarcodeFormat.PDF_417))
        assertEquals(Barcode.FORMAT_DATA_MATRIX, mlKitOf(BarcodeFormat.DATA_MATRIX))
        assertEquals(Barcode.FORMAT_UNKNOWN, mlKitOf(BarcodeFormat.UNKNOWN))
    }

    @Test
    @DisplayName("十三个码制都能从 ML Kit 的值映射回来")
    fun mlKitRoundTripsEveryRenderableFormat() {
        BarcodeFormat.renderable.forEach { format ->
            val mlKit = BarcodeFormatTable.rowFor(format).mlKit
            assertEquals(format, barcodeFormatOfMlKit(mlKit), "ML Kit $mlKit 映射错了")
        }
    }

    /**
     * `FORMAT_ALL_FORMATS` 是 0、`FORMAT_UNKNOWN` 是 -1，两个都**不是码制**。
     * 它们要是进了反查表，ML Kit 返回 0 时就会被映射成某个具体码制 —— 一个
     * 「扫到了但说不清是什么」的结果会被当成一张 Code 128 卡存下来。
     */
    @Test
    @DisplayName("ALL_FORMATS / UNKNOWN / 没见过的值都映射成 UNKNOWN")
    fun unknownMlKitValuesMapToUnknown() {
        assertEquals(BarcodeFormat.UNKNOWN, barcodeFormatOfMlKit(Barcode.FORMAT_ALL_FORMATS))
        assertEquals(BarcodeFormat.UNKNOWN, barcodeFormatOfMlKit(Barcode.FORMAT_UNKNOWN))
        assertEquals(BarcodeFormat.UNKNOWN, barcodeFormatOfMlKit(0))
        assertEquals(BarcodeFormat.UNKNOWN, barcodeFormatOfMlKit(-1))
        assertEquals(BarcodeFormat.UNKNOWN, barcodeFormatOfMlKit(1 shl 20))
    }

    /** 缺资源时 `R.string.*` 解析成 0 —— 那会在界面上显示成一片空白而不是报错。 */
    @Test
    @DisplayName("十四个码制都有展示名资源，且互不相同")
    fun everyFormatHasItsOwnDisplayName() {
        BarcodeFormat.entries.forEach { format ->
            assertNotEquals(0, format.displayNameRes, "$format 没有展示名资源")
        }
        assertEquals(
            BarcodeFormat.entries.size,
            BarcodeFormat.entries
                .map { it.displayNameRes }
                .toSet()
                .size,
            "有两个码制共用了同一条展示名",
        )
    }
}
