package de.ncards.core.barcode.render

import de.ncards.core.model.barcode.BarcodeDimension
import de.ncards.core.model.barcode.BarcodeFormat

/**
 * 静区（quiet zone）宽度，单位是**模块**，**每侧**。
 *
 * ============================================================================
 * ⚠️ 为什么静区是我们自己加的，而不是交给 ZXing 的 EncodeHintType.MARGIN
 * ============================================================================
 * 因为 ZXing 在这件事上有**五套互不相同的语义**。以下是对 zxing-3.5.4 源码与
 * 实际输出逐条实测的结果（T-152）：
 *
 * | 码制 | 读 MARGIN？ | 默认值 | 单位 |
 * |---|---|---|---|
 * | Code 128/39/93、ITF、Codabar | 是 | 10 | 模块，**两侧总和**（每侧 5） |
 * | EAN-13/8、UPC-A/E | 是 | **9** | 模块，**两侧总和**（每侧 4.5） |
 * | QR_CODE | 是 | 4 | 模块，**每侧** |
 * | PDF_417 | 是 | 30 | **输出像素**，每侧 |
 * | AZTEC、DATA_MATRIX | **否，hint 被完全忽略** | **0** | 根本没有静区 |
 *
 * 源码依据：
 * - `OneDimensionalCodeWriter.renderResult`：`fullWidth = code.length + sidesMargin`
 *   而 `leftPadding = (outputWidth - inputWidth * multiple) / 2` —— 传进去的值被**折半**。
 * - `UPCEANWriter.getDefaultMargin()` 覆写成 `return 9`，EAN13/EAN8/UPCA/UPCE 全继承它。
 * - `QRCodeWriter.renderResult`：`qrWidth = inputWidth + quietZone * 2` —— 每侧。
 * - `PDF417Writer`：`WHITE_SPACE = 30`，`new BitMatrix(w + 2 * margin, h + 2 * margin)`。
 * - `AztecWriter.encode` / `DataMatrixWriter.encode` 里 `EncodeHintType.MARGIN`
 *   **一次都没出现**（实测：加不加这个 hint，输出矩阵尺寸一模一样）。
 *
 * 后果是：照 §10.1 字面写 `MARGIN = 10`，得到的是一维码每侧 5 模块
 * （EAN/UPC 只有 4.5），而 Aztec 与 DataMatrix **一圈静区都没有**。
 * 后两者印在深色卡面上基本扫不出 —— 而这个错误在模拟器里、在单测里、
 * 在「看起来像个条码」的肉眼检查里**全都看不出来**，只会在收银台前发作。
 *
 * 所以 [BarcodeRasterizer] 只向 ZXing 要 1× 的裸模块矩阵
 * （`encode(…, 0, 0, MARGIN = 0)`），静区与缩放全部自己做，本表是唯一的数字来源。
 *
 * **静区不是保险，是必需品。** T-152 实测（DataMatrix，载荷 `NCARDS-DM-1`）：
 * 静区 0 模块时，ZXing 自己的 reader 在 4 / 12 / 33 px 三档模块宽下**全部**
 * 抛 `NotFoundException`；给到 1 模块就全部解得出。`BarcodeRoundTripTest`
 * 覆盖了全部 13 种码制，因此这张表里任何一个数被改小，那里会直接变红。
 */
internal object QuietZone {
    /**
     * 一维码：每侧 **11** 模块。
     *
     * §10.1 要求「至少 10 模块」；取 11 是因为 EAN-13 自己的标准要求左侧 11 模块，
     * 而 ZXing 会把静区左右对称地摊开。多出来的一个模块只是白边，没有任何代价。
     */
    private const val ONE_D_MODULES = 11

    /** QR 标准规定的 4 模块。ZXing 的默认值也是 4，这里只是把它写明。 */
    private const val QR_MODULES = 4

    /**
     * DataMatrix 标准要求 1 模块，Aztec 标准要求 0。两者都取 **2**：
     * ZXing 给的是 0，而这两种码经常被贴在有图案的卡面上，
     * 多一个模块的白边是这里最便宜的保险。
     */
    private const val TWO_D_OTHER_MODULES = 2

    /**
     * 每侧的静区模块数。
     *
     * [BarcodeFormat.UNKNOWN] 给 0 —— 它根本渲染不出来，取不到这一步。
     */
    fun modulesPerSideOf(format: BarcodeFormat): Int =
        when (format.dimension) {
            BarcodeDimension.ONE_D -> ONE_D_MODULES
            BarcodeDimension.TWO_D -> if (format == BarcodeFormat.QR_CODE) QR_MODULES else TWO_D_OTHER_MODULES
            BarcodeDimension.UNKNOWN -> 0
        }
}
