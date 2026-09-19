package de.ncards.core.barcode.render

import com.google.zxing.EncodeHintType
import com.google.zxing.MultiFormatWriter
import com.google.zxing.WriterException
import com.google.zxing.common.BitMatrix
import com.google.zxing.datamatrix.encoder.SymbolShapeHint
import de.ncards.core.barcode.format.BarcodeFormatTable
import de.ncards.core.model.barcode.BarcodeDimension
import de.ncards.core.model.barcode.BarcodeFormat

/**
 * 把 `(码制, 码值, 目标尺寸)` 变成一张 ARGB 像素图。**纯 Kotlin，不碰 `android.*`。**
 *
 * ============================================================================
 * 为什么缩放是我们自己做的，而不是交给 ZXing
 * ============================================================================
 * 两个理由，都是实测出来的（T-152，zxing 3.5.4）：
 *
 * **一、静区语义有五套，其中两套压根不存在。** 详见 [QuietZone] 的注释。
 * Aztec 与 DataMatrix 完全忽略 `EncodeHintType.MARGIN`，ZXing 给它们的静区是 0。
 *
 * **二、装不下时 ZXing 静默返回比请求更大的矩阵。** 五个 writer 全是
 * `outputWidth = Math.max(width, fullWidth)` 的形状。于是 `BitMatrix` 的尺寸
 * 经常不等于请求尺寸：按请求尺寸开数组会越界，而当矩阵比 View 大时，
 * View 会做**非整数降采样** —— 那正是 §10.1「不要生成小图再放大，边缘模糊会导致
 * 扫码枪读不出」要防的事，只是方向相反。
 *
 * 所以这里只向 ZXing 要 1× 的裸模块矩阵（`encode(…, 0, 0, MARGIN = 0)`），
 * 静区、整数缩放、像素展开全部自己做。实测该探针对全部 13 种码制都返回
 * 恰好等于模块数的矩阵（EAN-13 → 95×1，QR → 25×25，Aztec → 19×19，
 * DataMatrix → 32×8，PDF417 → 120×24），零静区。
 *
 * ⚠️ **探针绝不要传 `height > width`。** `PDF417Writer` 里有一句
 * `if ((height > width) != (…)) rotateArray(…)`，会把横向的 PDF417 转成纵向。
 * `(0, 0)` 是安全的。
 */
internal class BarcodeRasterizer {
    /**
     * @param widthPx 目标宽度（像素）。一维码用它定模块宽；二维码与高度一起定缩放倍数。
     * @param heightPx 目标高度（像素）。一维码**自由拉伸**到这个高度（每一行都一样，
     *   高度不携带信息 —— T-154 的横屏放大靠的就是这一条）；二维码按符号本身的宽高比走。
     *
     * `ReturnCount` 的豁免：五个 return 对应五种互斥的结局
     * （画不出来 / 尺寸非法 / 载荷非法 / 太小 / 成功）。合并成一条只会得到一个嵌套五层的
     * 版本 —— 与 `ApiResult.execute` 的那条豁免同构。
     */
    @Suppress("ReturnCount")
    fun rasterize(
        format: BarcodeFormat,
        value: String,
        widthPx: Int,
        heightPx: Int,
    ): RasterOutcome {
        val zxingFormat = BarcodeFormatTable.rowFor(format).zxing
            ?: return RasterOutcome.UnsupportedFormat

        if (widthPx <= 0 || heightPx <= 0) {
            return RasterOutcome.TooSmall(minimumWidthPx = 1, minimumHeightPx = 1)
        }

        val modules = encodeModules(zxingFormat, value) ?: return RasterOutcome.InvalidPayload

        val quiet = QuietZone.modulesPerSideOf(format)
        val totalModulesW = modules.width + 2 * quiet
        val totalModulesH = modules.height + 2 * quiet

        // 一维码的高度不参与缩放：它只有一行模块，拉多高都不损失信息。
        val scale = if (format.dimension == BarcodeDimension.ONE_D) {
            widthPx / totalModulesW
        } else {
            minOf(widthPx / totalModulesW, heightPx / totalModulesH)
        }

        if (scale < MIN_MODULE_PX) {
            return RasterOutcome.TooSmall(
                minimumWidthPx = totalModulesW * MIN_MODULE_PX,
                minimumHeightPx = if (format.dimension == BarcodeDimension.ONE_D) {
                    1
                } else {
                    totalModulesH * MIN_MODULE_PX
                },
            )
        }

        val outWidth = totalModulesW * scale
        val outHeight = if (format.dimension == BarcodeDimension.ONE_D) heightPx else totalModulesH * scale

        return RasterOutcome.Success(expand(modules, scale, outWidth, outHeight))
    }

    /**
     * 向 ZXing 要 1× 的裸模块矩阵。
     *
     * ⚠️ **绝不要把 [WriterException] 等的 `message` 记进日志，也不要解析它。**
     * 多条消息里嵌着码值片段（Code 39 的 `non-encodable character: 'X'`、
     * Codabar 的 `Cannot encode : '…'` / `Invalid start/end guards: …`），
     * 而 `scripts/ci/check-sensitive-logs.sh` 是两段式的：它只拦
     * `barcodeValue` / `rawValue` 出现在日志调用那一行的情形，**拦不住 `Timber.w(e)`**。
     * 这是一条现有门禁在结构上看不见的 §7.3 / §14.4 泄漏。
     *
     * 也因此不按消息细分失败原因：那是没有文档、会随 ZXing 版本变的英文串。
     * 逐字段的原因与德语文案归 T-155，落点仍在本模块。
     *
     * 三类异常都要兜：`IllegalArgumentException`（绝大多数 —— EAN 校验位不符、
     * ITF 奇数位、Codabar 缺起止符、Aztec 超长…）、`WriterException`（QR 与 PDF417
     * 的容量超限，是**受检**异常）、`IllegalStateException`（QR/Aztec 的 null matrix）。
     */
    @Suppress("SwallowedException", "TooGenericExceptionCaught")
    internal fun encodeModules(
        zxingFormat: com.google.zxing.BarcodeFormat,
        value: String,
    ): BitMatrix? =
        try {
            MultiFormatWriter().encode(value, zxingFormat, 0, 0, hintsFor(zxingFormat))
        } catch (invalid: IllegalArgumentException) {
            null
        } catch (tooBig: WriterException) {
            null
        } catch (broken: IllegalStateException) {
            null
        } catch (unexpected: RuntimeException) {
            // ZXing 的 writer 家族没有一个共同的异常父类，而漏掉任何一个都会让
            // 「一张脏数据卡」变成「App 崩溃」。§4.3 不允许那样。
            null
        }

    /**
     * 给 writer 的 hints。
     *
     * `MARGIN = 0` 是探针的一半：静区由 [QuietZone] 自己加（那里写了为什么）。
     *
     * ⚠️ **DataMatrix 强制方形符号（`FORCE_SQUARE`）。** ZXing 默认允许矩形符号，
     * 而矩形的 DataMatrix **连 ZXing 自己的 reader 都读不出来**。T-152 实测
     * （载荷 `NCARDS-DM-1`，默认形状得到 32×8 的矩形符号）：
     *
     * ```
     * 矩形 32x8 ：模块 4 px → 能解；模块 12 px → 能解；模块 33 px → NotFoundException
     * 方形 16x16：模块 4 / 12 / 33 px → 全部能解
     * ```
     *
     * 也就是说**尺寸越大越读不出**，而全屏条码页正是最大的那一档 —— 这个缺陷会
     * 精确地只在真机全屏页上发作。矩形 DataMatrix（DMRE）本来也不是所有扫码枪都支持，
     * 方形才是通用形态。
     *
     * 强制方形**不改变码值**：用户扫进来的若是矩形 DataMatrix，我们存的是它的载荷，
     * 重新渲染成方形之后扫出来仍然是同一个字符串。
     */
    private fun hintsFor(zxingFormat: com.google.zxing.BarcodeFormat): Map<EncodeHintType, Any> =
        buildMap {
            put(EncodeHintType.MARGIN, 0)
            if (zxingFormat == com.google.zxing.BarcodeFormat.DATA_MATRIX) {
                put(EncodeHintType.DATA_MATRIX_SHAPE, SymbolShapeHint.FORCE_SQUARE)
            }
        }

    /**
     * 展开成像素：白底黑码、整数倍复制、余量居中。
     *
     * **强制白底黑码**（§10.1）就落在这两个常量上 —— 颜色不从 `MaterialTheme` 取，
     * 深色模式下的条码是扫码失败的经典原因。`core:designsystem` 的 Theme.kt
     * 里那条写给 T-152 的警告说的就是这件事。
     */
    private fun expand(
        modules: BitMatrix,
        scale: Int,
        outWidth: Int,
        outHeight: Int,
    ): BarcodeRaster {
        val pixels = IntArray(outWidth * outHeight) { WHITE }

        // 内容区左上角。一维码的高度是自由拉伸的，所以纵向从 0 铺到底；
        // 二维码则把静区与整数缩放的余量对称地摊在四周。
        val contentPxW = modules.width * scale
        val contentPxH = modules.height * scale
        val left = (outWidth - contentPxW) / 2
        val oneD = modules.height == 1
        val top = if (oneD) 0 else (outHeight - contentPxH) / 2
        val barHeight = if (oneD) outHeight else contentPxH

        for (my in 0 until modules.height) {
            for (mx in 0 until modules.width) {
                if (!modules.get(mx, my)) continue
                val x0 = left + mx * scale
                val y0 = if (oneD) 0 else top + my * scale
                val y1 = if (oneD) barHeight else y0 + scale
                for (y in y0 until y1) {
                    val rowStart = y * outWidth + x0
                    pixels.fill(BLACK, rowStart, rowStart + scale)
                }
            }
        }
        return BarcodeRaster(pixels = pixels, width = outWidth, height = outHeight, moduleSizePx = scale)
    }

    companion object {
        /**
         * 「ZXing 编不编得出来」这一个问题的答案，**不产出任何像素**（T-155）。
         *
         * `validateBarcodePayload` 的第二步用它兜底：声明式规则全过了之后再真的编一次，
         * 于是「本模块说合法 ⇒ 全屏条码页画得出来」成为结构性的，而不是靠两处代码
         * 各自写对。理由写在那个函数的注释里。
         *
         * ⚠️ 走的是与 [rasterize] **同一个** [encodeModules]，不是另写一次 try/catch ——
         * 两份的话，哪天 ZXing 多抛一类异常，校验器与渲染器会对同一个码值给出不同答案，
         * 而那正是这个函数存在的意义要防的事。
         *
         * 尺寸完全不参与：容量超限在 `encode` 那一步就抛了，与画多大无关。
         */
        fun canEncode(
            format: BarcodeFormat,
            value: String,
        ): Boolean {
            val zxingFormat = BarcodeFormatTable.rowFor(format).zxing ?: return false

            return BarcodeRasterizer().encodeModules(zxingFormat, value) != null
        }

        /** 不透明白。 */
        const val WHITE: Int = 0xFFFFFFFF.toInt()

        /** 不透明黑。 */
        const val BLACK: Int = 0xFF000000.toInt()

        /**
         * 一个模块至少要占几个像素。
         *
         * 2 px 不是拍的：§10.1 图片录入那一节自己用的就是这个数
         * （「长边降采样至 ≤ 2048 px：既能保证 1D 条码的最小模块宽 ≥ 2 px…」）。
         * 低于它就返回 [RasterOutcome.TooSmall]，而不是画一张糊的图交出去。
         */
        const val MIN_MODULE_PX: Int = 2
    }
}

/** [BarcodeRasterizer] 的结果。与对外的 `BarcodeRenderResult` 一一对应，只是不含 Bitmap。 */
internal sealed interface RasterOutcome {
    data class Success(
        val raster: BarcodeRaster,
    ) : RasterOutcome

    /** [BarcodeFormat.UNKNOWN] —— 服务端加了一个本版本不认识的码制（§13.6）。 */
    data object UnsupportedFormat : RasterOutcome

    /** 码值与码制不匹配（校验位、长度、字符集、容量…）。不细分原因，见 [BarcodeRasterizer]。 */
    data object InvalidPayload : RasterOutcome

    data class TooSmall(
        val minimumWidthPx: Int,
        val minimumHeightPx: Int,
    ) : RasterOutcome
}
