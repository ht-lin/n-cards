package de.ncards.core.barcode.render

import android.graphics.Bitmap
import de.ncards.core.barcode.cache.SizedLruCache
import de.ncards.core.model.barcode.BarcodeFormat
import kotlinx.coroutines.CoroutineDispatcher
import kotlinx.coroutines.withContext

/**
 * [BarcodeRenderer] 的实现：缓存 → 栅格化 → Bitmap。
 *
 * 三个协作者全部从构造参数注入，所以本类**自己也能被单元测试覆盖**
 * （假的 [BarcodeBitmapFactory] 返回 `mockk<Bitmap>`）—— 见
 * [BarcodeBitmapFactory] 注释里那段关于覆盖率门禁的说明。
 *
 * [dispatcher] 直接由 DI 传 `Dispatchers.Default`（§10.1 原文）而不是经
 * `core:common` 的 `DispatcherProvider`：那个模块至今是空壳，T-150 的
 * `DefaultAuthRepository` 也留了同样的注释。第三个消费者出现时一起提上去，
 * 而不是在这里单独把它建起来。
 */
internal class DefaultBarcodeRenderer(
    private val rasterizer: BarcodeRasterizer,
    private val cache: SizedLruCache<BarcodeKey, Bitmap>,
    private val bitmaps: BarcodeBitmapFactory,
    private val dispatcher: CoroutineDispatcher,
) : BarcodeRenderer {
    override suspend fun render(request: BarcodeRenderRequest): BarcodeRenderResult =
        // ⚠️ 缓存读取也放在 withContext **里面**。放外面的话命中时在调用者线程返回、
        // 未命中时在 Default 返回 —— 这种「有时同步有时异步」正是 Compose 侧
        // heisenbug 的来源。
        withContext(dispatcher) {
            val key = BarcodeKey(request.format, request.value, request.widthPx, request.heightPx)

            cache.get(key)?.let { return@withContext BarcodeRenderResult.Success(it) }

            val outcome = with(request) { rasterizer.rasterize(format, value, widthPx, heightPx) }
            when (outcome) {
                is RasterOutcome.Success -> {
                    val bitmap = bitmaps.create(outcome.raster)
                    // 只缓存成功。失败不进缓存 —— 否则 T-155 把一张卡的码值改对之后，
                    // 用户要等到进程重启才看得见修好的条码。
                    cache.put(key, bitmap)
                    BarcodeRenderResult.Success(bitmap)
                }

                RasterOutcome.UnsupportedFormat -> {
                    BarcodeRenderResult.UnsupportedFormat
                }

                RasterOutcome.InvalidPayload -> {
                    BarcodeRenderResult.InvalidPayload
                }

                is RasterOutcome.TooSmall -> {
                    BarcodeRenderResult.TooSmall(outcome.minimumWidthPx, outcome.minimumHeightPx)
                }
            }
        }
}

/**
 * 缓存键 —— 就是 §10.1 要求的 `format + value + size`。
 *
 * 取的是**请求**尺寸而不是产出尺寸：请求尺寸是调用方能复现的那一个
 * （产出尺寸对二维码会被取整缩小，拿它当键会导致永远命不中）。
 */
internal data class BarcodeKey(
    val format: BarcodeFormat,
    val value: String,
    val widthPx: Int,
    val heightPx: Int,
) {
    /** 与 [BarcodeRenderRequest.toString] 同理：码值不得进日志（§14.4）。 */
    override fun toString(): String = "BarcodeKey($format, ${value.length} chars, ${widthPx}x$heightPx)"
}
