package de.ncards.core.barcode.di

import android.graphics.Bitmap
import dagger.Module
import dagger.Provides
import dagger.hilt.InstallIn
import dagger.hilt.components.SingletonComponent
import de.ncards.core.barcode.cache.SizedLruCache
import de.ncards.core.barcode.render.AndroidBarcodeBitmapFactory
import de.ncards.core.barcode.render.BarcodeKey
import de.ncards.core.barcode.render.BarcodeRasterizer
import de.ncards.core.barcode.render.BarcodeRenderer
import de.ncards.core.barcode.render.DefaultBarcodeRenderer
import kotlinx.coroutines.Dispatchers
import javax.inject.Singleton

/**
 * 条码渲染的 DI 接线（T-152）。
 *
 * 整个模块不需要 `@ApplicationContext` —— 缓存容量按 `Runtime.maxMemory()` 算
 * （见 [barcodeCacheBudgetBytes]），所以这张图是 Context-free 的，
 * 每个协作者在纯 JVM 单测里都能直接 new 出来。
 */
@Module
@InstallIn(SingletonComponent::class)
internal object BarcodeModule {
    @Provides
    @Singleton
    fun provideBitmapCache(): SizedLruCache<BarcodeKey, Bitmap> =
        SizedLruCache(maxSizeBytes = barcodeCacheBudgetBytes()) { it.allocationByteCount }

    @Provides
    @Singleton
    fun provideBarcodeRenderer(cache: SizedLruCache<BarcodeKey, Bitmap>): BarcodeRenderer =
        DefaultBarcodeRenderer(
            rasterizer = BarcodeRasterizer(),
            cache = cache,
            bitmaps = AndroidBarcodeBitmapFactory,
            // §10.1 原文：「渲染在 Dispatchers.Default」。
            dispatcher = Dispatchers.Default,
        )
}

/**
 * 条码位图缓存的字节预算。
 *
 * **用 `Runtime.maxMemory()` 而不是 `ActivityManager.memoryClass`**：前者是纯 JVM，
 * 单测里有真值；后者要 `Context`，还会报**未计入 largeHeap** 的那个旧数字。
 *
 * 这个缓存的用途是**第二次**打开同一张卡时瞬间出图（widget → 详情 → 全屏，
 * 同一个码值三个尺寸；以及把手机从收银员面前收回来之后的 `onResume`），
 * 不是把整个钱包存起来。三个数因此都是按这个用途定的：
 *
 * - **地板 [CACHE_FLOOR_BYTES] 必须装得下至少一张全屏图。** 装不下的话
 *   [SizedLruCache.put] 会因为「单个值比预算还大」而直接不收 —— 缓存变成纯粹的
 *   死重，而且**没有任何症状**，只是每次都重算。
 *   1080p 屏上最大的一张是全屏 QR（方形，1080×1080×4 ≈ 4.45 MiB），
 *   地板取 5 MiB 刚好越过它。`BarcodeCacheBudgetTest` 钉住这条不变式。
 * - **天花板 [CACHE_CEILING_BYTES]** ≈ 两张全屏图，够覆盖上面那条访问路径。
 * - 中间按 1/[CACHE_FRACTION] 随堆走。§13.8 点名的验证设备是 Android 8 + 2 GB RAM，
 *   那一档的 `maxMemory` 通常是 96–192 MB，落在 6–12 MiB，正是想要的区间。
 *
 * **改这三个数之前先想清楚要服务哪个访问模式。**
 *
 * @param maxMemory 参数化只为可测。
 */
internal fun barcodeCacheBudgetBytes(maxMemory: Long = Runtime.getRuntime().maxMemory()): Int =
    (maxMemory / CACHE_FRACTION).coerceIn(CACHE_FLOOR_BYTES, CACHE_CEILING_BYTES).toInt()

private const val CACHE_FRACTION = 16L

/** 1080×1080 的 ARGB_8888 全屏 QR ≈ 4.45 MiB，地板必须在它之上。 */
private const val CACHE_FLOOR_BYTES = 5L * 1024 * 1024
private const val CACHE_CEILING_BYTES = 12L * 1024 * 1024
