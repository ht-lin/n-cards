package de.ncards.feature.carddetail

import androidx.compose.foundation.Canvas
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.BoxWithConstraints
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.produceState
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.FilterQuality
import androidx.compose.ui.graphics.asImageBitmap
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.clearAndSetSemantics
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.IntOffset
import androidx.compose.ui.unit.IntSize
import androidx.compose.ui.unit.dp
import de.ncards.core.barcode.render.BarcodeRenderRequest
import de.ncards.core.barcode.render.BarcodeRenderResult
import de.ncards.core.barcode.render.BarcodeRenderer
import de.ncards.core.model.barcode.BarcodeDimension
import de.ncards.core.model.card.Card
import kotlin.math.min
import kotlin.math.roundToInt

/**
 * 白底黑码的那一块。详情页不用它（见 `CardDetailScreen` 的注释），全屏页用它。
 *
 * ============================================================================
 * ⚠️ 三条来自 `core:barcode` KDoc 的调用方义务，逐条落在这里
 * ============================================================================
 * 1. **尺寸必须是 View 的实际像素**（§10.1：不要生成小图再放大）。
 *    用 [BoxWithConstraints] 而不是 `onSizeChanged`：前者在**组合期**就给出尺寸，
 *    请求与布局在同一帧发出；后者要多等一整帧，而这一帧正落在
 *    §9.1 那条 800 ms 预算的关键路径上。
 * 2. **位图可能比请求的小**（二维码保符号宽高比、模块宽取整），
 *    所以**按 1:1 画再居中**，不用 `Image(..., ContentScale.Fit)` ——
 *    `Fit` 在位图偏小时会**放大**它，而任何非整数缩放都把「整数模块宽」
 *    这条唯一真正防糊的不变式毁掉，也就是扫码枪读不出的那种糊。
 * 3. **绝不 `recycle()`**：`SizedLruCache` 淘汰时都刻意不回收，因为 Compose
 *    可能还持有它（`Canvas: trying to use a recycled bitmap`）。
 *
 * ============================================================================
 * ⚠️ 一维码的高度要封顶
 * ============================================================================
 * 一维码的高度不携带信息，渲染器会自由拉伸到请求值 —— 横屏满屏地要就是
 * 一张 2400×800 的 ARGB_8888，7.3 MiB。而条码缓存的预算是
 * `maxMemory/16` 夹在 **5–12 MiB**：超过地板的单个值会让 `SizedLruCache.put`
 * **直接不收**，缓存变成死重，而且**没有任何症状**，只是每次都重算。
 * 何况一维码高过一两厘米就不再增加可读性。
 */
@Composable
internal fun BarcodeSurface(
    card: Card,
    renderer: BarcodeRenderer,
    onBarcodeDrawn: () -> Unit,
    modifier: Modifier = Modifier,
) {
    BoxWithConstraints(
        modifier = modifier.background(BarcodeWhite),
        contentAlignment = Alignment.Center,
    ) {
        val density = LocalDensity.current
        val boxWidthPx = with(density) { maxWidth.roundToPx() }
        val boxHeightPx = with(density) { maxHeight.roundToPx() }

        val requestWidthPx = boxWidthPx
        val requestHeightPx =
            if (card.barcodeFormat.dimension == BarcodeDimension.ONE_D) {
                min(min(boxHeightPx, (boxWidthPx * ONE_D_HEIGHT_OF_WIDTH).roundToInt()), ONE_D_MAX_HEIGHT_PX)
            } else {
                boxHeightPx
            }

        // 第一帧尺寸可能是 0（约束还没下来）。发一个 0×0 的请求只会白跑一趟，
        // 拿回一个 TooSmall(1, 1)，然后在真尺寸到达时再算一次。
        if (requestWidthPx <= 0 || requestHeightPx <= 0) {
            return@BoxWithConstraints
        }

        val result by produceState<BarcodeRenderResult?>(
            initialValue = null,
            card.barcodeFormat,
            card.barcodeValue,
            requestWidthPx,
            requestHeightPx,
        ) {
            value =
                renderer.render(
                    BarcodeRenderRequest(
                        format = card.barcodeFormat,
                        value = card.barcodeValue,
                        widthPx = requestWidthPx,
                        heightPx = requestHeightPx,
                    ),
                )
        }

        when (val current = result) {
            // 还在渲染。**刻意留白**，不放转圈：一个只出现二十毫秒的转圈是纯粹的
            // 抖动，而「画好了没有」这件事由 Activity 的 reportFullyDrawn 负责报告。
            null -> {
                Unit
            }

            is BarcodeRenderResult.Success -> {
                val bitmap = current.bitmap
                val image = bitmap.asImageBitmap()

                Canvas(
                    // 条码图是**装饰**：它承载的信息由下面那行码值文本承载，
                    // 而 TalkBack 念不出一张位图。与 `CardTile` 同一条理由。
                    modifier = Modifier.fillMaxSize().clearAndSetSemantics { },
                ) {
                    drawImage(
                        image = image,
                        dstOffset =
                            IntOffset(
                                x = ((size.width - bitmap.width) / 2f).roundToInt(),
                                y = ((size.height - bitmap.height) / 2f).roundToInt(),
                            ),
                        // ⚠️ dstSize == 位图自身尺寸：1:1，不缩放。见类注释第 2 条。
                        dstSize = IntSize(bitmap.width, bitmap.height),
                        filterQuality = FilterQuality.None,
                    )
                }

                LaunchedEffect(bitmap) { onBarcodeDrawn() }
            }

            BarcodeRenderResult.UnsupportedFormat -> {
                BarcodeFallback(message = stringResource(R.string.fullscreen_unsupported))
            }

            BarcodeRenderResult.InvalidPayload -> {
                // ⚠️ 不给原因，也不记日志。ZXing 的异常消息是没有文档的英文串，
                // 其中几条**嵌着码值片段**，而 check-sensitive-logs.sh 拦不住
                // `Timber.w(e)`（它只认标识符名）。
                BarcodeFallback(message = stringResource(R.string.fullscreen_invalid))
            }

            is BarcodeRenderResult.TooSmall -> {
                // ⚠️ `TooSmall` 带建议尺寸，就是为了让这里能回答「要多大」而不只是「不行」。
                // 把长短边对调一下装得下 → 那就是「转一下手机」；两边都装不下 → 屏幕就是太小。
                val fitsRotated =
                    current.minimumWidthPx <= boxHeightPx && current.minimumHeightPx <= boxWidthPx

                BarcodeFallback(
                    message =
                        stringResource(
                            if (fitsRotated) R.string.fullscreen_rotate_hint else R.string.fullscreen_too_small,
                        ),
                )
            }
        }
    }
}

/**
 * 画不出条码时那一块。
 *
 * ⚠️ 码值**仍然显示**（由调用方画在下面）—— §10.2 的硬要求是关于**码值**的，
 * 不是关于那张图的。`UnsupportedFormat` 的 KDoc 说的就是这件事：
 * 「UI 该给兜底样式 + 大字号可选中的码值，这里只是没有上半部分」。
 */
@Composable
private fun BarcodeFallback(message: String) {
    Column(
        modifier = Modifier.fillMaxSize().padding(FallbackPadding),
        verticalArrangement = Arrangement.Center,
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        Text(
            text = message,
            color = BarcodeBlack,
            textAlign = TextAlign.Center,
        )
    }
}

/**
 * 一维码高度取宽度的这个比例（再与 [ONE_D_MAX_HEIGHT_PX] 取小）。
 * 0.4 在竖屏上是一条舒服的条码，在横屏上把它压住不至于吃掉整块高度。
 */
private const val ONE_D_HEIGHT_OF_WIDTH = 0.4f

/** 见类注释「一维码的高度要封顶」：这个数把最坏情况摁在缓存地板（5 MiB）以下。 */
private const val ONE_D_MAX_HEIGHT_PX = 600

private val FallbackPadding = 24.dp
