package de.ncards.core.barcode.format

import androidx.annotation.StringRes
import com.google.mlkit.vision.barcode.common.Barcode
import de.ncards.core.barcode.R
import de.ncards.core.model.barcode.BarcodeFormat
import com.google.zxing.BarcodeFormat as ZxingFormat

/**
 * §10.1 的「ML Kit → ZXing → 展示名」映射表。**全仓唯一的 `when (format)` 在这里。**
 *
 * ============================================================================
 * 为什么是一张四列表，而不是三个各管一段的文件
 * ============================================================================
 * 三个文件各有一个 `when`，每个**自己都是穷举的** —— 将来加第 14 个码制时，
 * 漏改其中一个不会有任何编译错误，于是「扫得出来但显示成空白」这类 bug 就有了入口。
 *
 * 一张表则只有 [rowOf] 这一处 `when`：加一个枚举值 → 这里编译不过 →
 * 三列被迫同时补齐。§10.1 禁止的是「各处散落 `when`」，这个形状比字面照做更严格。
 *
 * 词汇表本身在 `core:model` 的 [BarcodeFormat]（那是 §10.1 要求的「单一枚举」），
 * 三列适配在这里（那是 §12.3 说的「格式映射唯一实现处」）。见 ADR-0023。
 *
 * ⚠️ **ML Kit 那一列引常量，不抄数字。** `Barcode.FORMAT_*` 是 `static final int`，
 * kotlinc 在编译期内联，所以 `compileOnly` 就够（运行期不需要那个 artifact，
 * 见 build.gradle.kts）。引常量而不是抄 `32` / `2048` 这些字面量，是为了让值与 AAR
 * 绑定：抄下来的数字会在某次升版后静默漂移，引常量则每次编译都跟着走。
 * 注意 ML Kit 写作 `FORMAT_PDF417`（没有下划线）而契约是 `PDF_417` ——
 * 这种命名漂移正是必须逐条写出来、并由 `BarcodeFormatTableTest` 钉住的原因。
 */
internal object BarcodeFormatTable {
    /**
     * 一个码制的四列。
     *
     * @property zxing ZXing 的 writer 常量。**只有 [BarcodeFormat.UNKNOWN] 是 null** ——
     *   `MultiFormatWriter` 恰好支持我们要的全部 13 种（已逐条核对 3.5.4 的 switch）。
     * @property mlKit ML Kit 的位标志。用于把扫描结果映射回领域词汇（T-156 / T-157）。
     * @property displayNameRes 展示名。住在本模块的 `strings.xml` 里，因为 `core:model`
     *   没有 `res/`（§11.1 要求所有用户可见字符串都在 strings.xml）。
     */
    internal data class Row(
        val format: BarcodeFormat,
        val zxing: ZxingFormat?,
        val mlKit: Int,
        @field:StringRes val displayNameRes: Int,
    )

    // detekt 的 CyclomaticComplexMethod 阈值是 15，14 个分支正卡在边界上。
    // 拆开只会得到「散落的 when」—— 那恰恰是 §10.1 禁止的东西，也是本对象存在的理由。
    @Suppress("CyclomaticComplexMethod")
    private fun rowOf(format: BarcodeFormat): Row =
        when (format) {
            BarcodeFormat.EAN_13 -> {
                Row(format, ZxingFormat.EAN_13, Barcode.FORMAT_EAN_13, R.string.barcode_format_ean_13)
            }

            BarcodeFormat.EAN_8 -> {
                Row(format, ZxingFormat.EAN_8, Barcode.FORMAT_EAN_8, R.string.barcode_format_ean_8)
            }

            BarcodeFormat.UPC_A -> {
                Row(format, ZxingFormat.UPC_A, Barcode.FORMAT_UPC_A, R.string.barcode_format_upc_a)
            }

            BarcodeFormat.UPC_E -> {
                Row(format, ZxingFormat.UPC_E, Barcode.FORMAT_UPC_E, R.string.barcode_format_upc_e)
            }

            BarcodeFormat.CODE_128 -> {
                Row(format, ZxingFormat.CODE_128, Barcode.FORMAT_CODE_128, R.string.barcode_format_code_128)
            }

            BarcodeFormat.CODE_39 -> {
                Row(format, ZxingFormat.CODE_39, Barcode.FORMAT_CODE_39, R.string.barcode_format_code_39)
            }

            BarcodeFormat.CODE_93 -> {
                Row(format, ZxingFormat.CODE_93, Barcode.FORMAT_CODE_93, R.string.barcode_format_code_93)
            }

            BarcodeFormat.ITF -> {
                Row(format, ZxingFormat.ITF, Barcode.FORMAT_ITF, R.string.barcode_format_itf)
            }

            BarcodeFormat.CODABAR -> {
                Row(format, ZxingFormat.CODABAR, Barcode.FORMAT_CODABAR, R.string.barcode_format_codabar)
            }

            BarcodeFormat.QR_CODE -> {
                Row(format, ZxingFormat.QR_CODE, Barcode.FORMAT_QR_CODE, R.string.barcode_format_qr_code)
            }

            BarcodeFormat.AZTEC -> {
                Row(format, ZxingFormat.AZTEC, Barcode.FORMAT_AZTEC, R.string.barcode_format_aztec)
            }

            BarcodeFormat.PDF_417 -> {
                Row(format, ZxingFormat.PDF_417, Barcode.FORMAT_PDF417, R.string.barcode_format_pdf_417)
            }

            BarcodeFormat.DATA_MATRIX -> {
                Row(format, ZxingFormat.DATA_MATRIX, Barcode.FORMAT_DATA_MATRIX, R.string.barcode_format_data_matrix)
            }

            BarcodeFormat.UNKNOWN -> {
                Row(format, zxing = null, Barcode.FORMAT_UNKNOWN, R.string.barcode_format_unknown)
            }
        }

    /** 每个枚举值一行，顺序与 `BarcodeFormat.entries` 一致。 */
    val rows: List<Row> = BarcodeFormat.entries.map(::rowOf)

    private val byFormat: Map<BarcodeFormat, Row> = rows.associateBy(Row::format)

    // FORMAT_ALL_FORMATS(0) 与 FORMAT_UNKNOWN(-1) 不是码制，不进反查表 ——
    // 否则 ML Kit 返回 0 时会被映射成某个具体码制。
    private val byMlKit: Map<Int, Row> = rows.filter { it.mlKit > 0 }.associateBy(Row::mlKit)

    fun rowFor(format: BarcodeFormat): Row = byFormat.getValue(format)

    /**
     * 把 ML Kit 的 `Barcode.getFormat()` 映射回领域词汇。认不出来给
     * [BarcodeFormat.UNKNOWN] —— ML Kit 升版可能返回我们不认识的新码制。
     *
     * 入参是 `Int` 而不是 ML Kit 的类型：ML Kit 的类型一个都不出现在对外签名上，
     * 这样 `compileOnly` 才成立。
     */
    fun formatForMlKit(mlKitFormat: Int): BarcodeFormat = byMlKit[mlKitFormat]?.format ?: BarcodeFormat.UNKNOWN
}
