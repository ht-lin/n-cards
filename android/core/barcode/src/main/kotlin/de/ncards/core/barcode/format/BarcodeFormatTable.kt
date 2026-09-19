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
 * 为什么是一张表，而不是几个各管一段的文件
 * ============================================================================
 * 三个文件各有一个 `when`，每个**自己都是穷举的** —— 将来加第 14 个码制时，
 * 漏改其中一个不会有任何编译错误，于是「扫得出来但显示成空白」这类 bug 就有了入口。
 *
 * 一张表则只有 [rowOf] 这一处 `when`：加一个枚举值 → 这里编译不过 →
 * **每一列**都被迫同时补齐（T-155 加上载荷规则之后是五列）。§10.1 禁止的是「各处散落 `when`」，这个形状比字面照做更严格。
 *
 * 词汇表本身在 `core:model` 的 [BarcodeFormat]（那是 §10.1 要求的「单一枚举」），
 * 各列适配在这里（那是 §12.3 说的「格式映射唯一实现处」）。见 ADR-0023。
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
     * 一个码制的五列。
     *
     * @property zxing ZXing 的 writer 常量。**只有 [BarcodeFormat.UNKNOWN] 是 null** ——
     *   `MultiFormatWriter` 恰好支持我们要的全部 13 种（已逐条核对 3.5.4 的 switch）。
     * @property mlKit ML Kit 的位标志。用于把扫描结果映射回领域词汇（T-156 / T-157）。
     * @property displayNameRes 展示名。住在本模块的 `strings.xml` 里，因为 `core:model`
     *   没有 `res/`（§11.1 要求所有用户可见字符串都在 strings.xml）。
     * @property payload 载荷规则（T-155）。T-152 的落地记录把「逐字段的载荷校验与德语文案」
     *   点名交给 T-155 并要求「**落点仍在 `core:barcode`**，不要在 `feature:cardedit` 里
     *   另起一套」。做成本表的**第五列**而不是一个新的 `when (format)`，
     *   是因为后者会正面推翻 ADR-0023 —— 那条决策的全部价值就是「全仓只有这一处
     *   `when (format)`，加第 14 个码制时各列被迫同时补齐」。加一列让那条性质更强，
     *   加一个 `when` 则让它作废。
     */
    internal data class Row(
        val format: BarcodeFormat,
        val zxing: ZxingFormat?,
        val mlKit: Int,
        @field:StringRes val displayNameRes: Int,
        val payload: PayloadRule,
    )

    // 三条豁免，理由是同一条：**这是一张表，不是一段逻辑。**
    // 拆开它只会得到「散落的 when」，而那恰恰是 §10.1 禁止的东西，也是本对象存在的理由。
    //
    // - CyclomaticComplexMethod：阈值 15，14 个分支正卡在边界上。
    // - LongMethod：阈值 60，而 14 行 × 每行五列写不进 60 行。与 Composable 那条
    //   豁免同构（detekt.yml 里写着「函数体是一棵声明式的树，不是一串语句」）。
    // - MagicNumber：EAN-13 的 `13` **就是**这个码制的定义。抽成
    //   `EAN_13_FULL_LENGTH = 13` 只会在表和常量区之间多一次跳转，读的人还得回来核对
    //   —— 而这张表的全部价值就是一眼能横着读完一行。
    @Suppress("CyclomaticComplexMethod", "LongMethod", "MagicNumber")
    private fun rowOf(format: BarcodeFormat): Row =
        when (format) {
            BarcodeFormat.EAN_13 -> {
                Row(
                    format,
                    ZxingFormat.EAN_13,
                    Barcode.FORMAT_EAN_13,
                    R.string.barcode_format_ean_13,
                    PayloadRule(
                        PayloadCharset.DIGITS,
                        exactLengths = setOf(12, 13),
                        checksum = PayloadChecksum.UPC_EAN_MOD_10,
                    ),
                )
            }

            BarcodeFormat.EAN_8 -> {
                Row(
                    format,
                    ZxingFormat.EAN_8,
                    Barcode.FORMAT_EAN_8,
                    R.string.barcode_format_ean_8,
                    PayloadRule(
                        PayloadCharset.DIGITS,
                        exactLengths = setOf(7, 8),
                        checksum = PayloadChecksum.UPC_EAN_MOD_10,
                    ),
                )
            }

            BarcodeFormat.UPC_A -> {
                Row(
                    format,
                    ZxingFormat.UPC_A,
                    Barcode.FORMAT_UPC_A,
                    R.string.barcode_format_upc_a,
                    PayloadRule(
                        PayloadCharset.DIGITS,
                        exactLengths = setOf(11, 12),
                        checksum = PayloadChecksum.UPC_EAN_MOD_10,
                    ),
                )
            }

            BarcodeFormat.UPC_E -> {
                Row(
                    format,
                    ZxingFormat.UPC_E,
                    Barcode.FORMAT_UPC_E,
                    R.string.barcode_format_upc_e,
                    PayloadRule(
                        PayloadCharset.DIGITS,
                        exactLengths = setOf(7, 8),
                        checksum = PayloadChecksum.UPC_EAN_MOD_10,
                    ),
                )
            }

            BarcodeFormat.CODE_128 -> {
                Row(
                    format,
                    ZxingFormat.CODE_128,
                    Barcode.FORMAT_CODE_128,
                    R.string.barcode_format_code_128,
                    PayloadRule(PayloadCharset.ASCII),
                )
            }

            BarcodeFormat.CODE_39 -> {
                Row(
                    format,
                    ZxingFormat.CODE_39,
                    Barcode.FORMAT_CODE_39,
                    R.string.barcode_format_code_39,
                    PayloadRule(PayloadCharset.ASCII, maxLength = ZXING_LINEAR_MAX_LENGTH),
                )
            }

            BarcodeFormat.CODE_93 -> {
                Row(
                    format,
                    ZxingFormat.CODE_93,
                    Barcode.FORMAT_CODE_93,
                    R.string.barcode_format_code_93,
                    PayloadRule(PayloadCharset.ASCII, maxLength = ZXING_LINEAR_MAX_LENGTH),
                )
            }

            BarcodeFormat.ITF -> {
                Row(
                    format,
                    ZxingFormat.ITF,
                    Barcode.FORMAT_ITF,
                    R.string.barcode_format_itf,
                    PayloadRule(PayloadCharset.DIGITS, maxLength = ZXING_LINEAR_MAX_LENGTH, requiresEvenLength = true),
                )
            }

            BarcodeFormat.CODABAR -> {
                Row(
                    format,
                    ZxingFormat.CODABAR,
                    Barcode.FORMAT_CODABAR,
                    R.string.barcode_format_codabar,
                    PayloadRule(PayloadCharset.CODABAR),
                )
            }

            BarcodeFormat.QR_CODE -> {
                Row(
                    format,
                    ZxingFormat.QR_CODE,
                    Barcode.FORMAT_QR_CODE,
                    R.string.barcode_format_qr_code,
                    PayloadRule(PayloadCharset.ANY),
                )
            }

            BarcodeFormat.AZTEC -> {
                Row(
                    format,
                    ZxingFormat.AZTEC,
                    Barcode.FORMAT_AZTEC,
                    R.string.barcode_format_aztec,
                    PayloadRule(PayloadCharset.ANY),
                )
            }

            BarcodeFormat.PDF_417 -> {
                Row(
                    format,
                    ZxingFormat.PDF_417,
                    Barcode.FORMAT_PDF417,
                    R.string.barcode_format_pdf_417,
                    PayloadRule(PayloadCharset.ANY),
                )
            }

            BarcodeFormat.DATA_MATRIX -> {
                Row(
                    format,
                    ZxingFormat.DATA_MATRIX,
                    Barcode.FORMAT_DATA_MATRIX,
                    R.string.barcode_format_data_matrix,
                    PayloadRule(PayloadCharset.ANY),
                )
            }

            BarcodeFormat.UNKNOWN -> {
                Row(
                    format,
                    zxing = null,
                    Barcode.FORMAT_UNKNOWN,
                    R.string.barcode_format_unknown,
                    PayloadRule(PayloadCharset.NONE),
                )
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

    /**
     * `Code39Writer` / `Code93Writer` / `ITFWriter` 的长度上限（zxing 3.5.4 实测）。
     *
     * ⚠️ **这一条只对那三个成立，不是「一维码都是 80」。** 实测下来
     * `Code128Writer` 与 `CodaBarWriter` **没有**长度上限（探到 600 仍然编得出来），
     * 所以它们的 [PayloadRule.maxLength] 是 `null`，只受 §7.5 的 1024 字节配额约束
     * （那一条在 `core:model` 的 `CardDraft.problems()` 里判）。
     *
     * 第一版是照着「一维 writer 家族都写着 between 1 and 80」写的，
     * 四个 writer 一视同仁 —— 而 `BarcodePayloadValidatorTest` 的一致性性质测试
     * 当场把 Code 128 那条否了。这正是那条测试存在的理由：
     * 这个数是**从源码里读出来的**，不是 ZXing 承诺的 API。
     *
     * 它同时是升版回归测试：ZXing 哪天改了这三个的上限，那条测试会红。
     */
    const val ZXING_LINEAR_MAX_LENGTH = 80
}
