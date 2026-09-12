package de.ncards.core.barcode

import de.ncards.core.model.barcode.BarcodeFormat

/**
 * 每个码制一条**合法**的定值载荷。单测与仪器测试共用这一份 —— 两边各写一份的话，
 * 「相机路径与图片路径必须给出同一个结果」这类断言就失去了共同的参照物。
 *
 * ⚠️ 全部是**造出来的示例值**，不是任何真实用户的码值。
 *
 * @property decodesTo 解回来时期望的文本。多数与 [payload] 相同；不同的那几条
 *   在下面逐条写明原因 —— 那些差异是 ZXing 的 reader 行为，不是我们的 bug。
 */
internal data class BarcodeFixture(
    val format: BarcodeFormat,
    val payload: String,
    val decodesTo: String = payload,
)

internal object BarcodeFixtures {
    val all: List<BarcodeFixture> = listOf(
        // EAN/UPC 家族：校验位必须正确，否则 writer 直接抛 IllegalArgumentException
        // （"Contents do not pass checksum"）。4006381333931 是一个公开的德国商品码示例。
        BarcodeFixture(BarcodeFormat.EAN_13, "4006381333931"),
        BarcodeFixture(BarcodeFormat.EAN_8, "96385074"),
        BarcodeFixture(BarcodeFormat.UPC_A, "036000291452"),
        BarcodeFixture(BarcodeFormat.UPC_E, "01234565"),
        BarcodeFixture(BarcodeFormat.CODE_128, "NCARDS-12345"),
        // Code 39 的默认字符集不含小写。
        BarcodeFixture(BarcodeFormat.CODE_39, "NCARDS123"),
        BarcodeFixture(BarcodeFormat.CODE_93, "NCARDS123"),
        // ITF 是「交叉二五码」，位数**必须是偶数**，否则 writer 抛
        // "The length of the input should be even"。
        BarcodeFixture(BarcodeFormat.ITF, "12345670"),
        // Codabar 的起止符是 A-D，必须成对出现在首尾。
        BarcodeFixture(BarcodeFormat.CODABAR, "A123456789B"),
        BarcodeFixture(BarcodeFormat.QR_CODE, "https://n-cards.example/demo"),
        BarcodeFixture(BarcodeFormat.AZTEC, "NCARDS-AZTEC-1"),
        BarcodeFixture(BarcodeFormat.PDF_417, "NCARDS-PDF417-1"),
        BarcodeFixture(BarcodeFormat.DATA_MATRIX, "NCARDS-DM-1"),
    )

    init {
        // 少一个码制就说明 fixture 表跟丢了枚举 —— 那会让「全部码制都覆盖到」变成一句空话。
        require(all.map(BarcodeFixture::format).toSet() == BarcodeFormat.renderable.toSet()) {
            "BarcodeFixtures 必须覆盖全部 ${BarcodeFormat.renderable.size} 个可渲染码制"
        }
    }

    fun of(format: BarcodeFormat): BarcodeFixture = all.first { it.format == format }
}
