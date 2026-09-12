package de.ncards.core.barcode.render

import android.graphics.Bitmap
import de.ncards.core.barcode.BarcodeFixtures
import de.ncards.core.barcode.cache.SizedLruCache
import de.ncards.core.model.barcode.BarcodeFormat
import io.mockk.mockk
import kotlinx.coroutines.CoroutineName
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.test.StandardTestDispatcher
import kotlinx.coroutines.test.runTest
import kotlinx.coroutines.withContext
import org.junit.jupiter.api.Assertions.assertEquals
import org.junit.jupiter.api.Assertions.assertNotEquals
import org.junit.jupiter.api.Assertions.assertSame
import org.junit.jupiter.api.Assertions.assertTrue
import org.junit.jupiter.api.DisplayName
import org.junit.jupiter.api.Test
import kotlin.coroutines.coroutineContext

/**
 * 本类之所以测得动，是因为三个协作者全部从构造参数注入 —— 唯一碰 `android.*` 的
 * [AndroidBarcodeBitmapFactory] 被替换成下面这个假货，于是从缓存到分支收敛
 * 整条路径都落在覆盖率门禁的视野里。
 */
@OptIn(ExperimentalCoroutinesApi::class)
@DisplayName("DefaultBarcodeRenderer")
class DefaultBarcodeRendererTest {
    /** 记账用的假 factory：每次都产出一个新的 Bitmap 替身，并数自己被调了几次。 */
    private class CountingBitmapFactory : BarcodeBitmapFactory {
        var calls = 0
            private set

        override fun create(raster: BarcodeRaster): Bitmap {
            calls++
            return mockk(relaxed = true)
        }
    }

    private val ean13 = BarcodeFixtures.of(BarcodeFormat.EAN_13)

    private fun request(
        value: String = ean13.payload,
        widthPx: Int = 1000,
        heightPx: Int = 300,
    ) = BarcodeRenderRequest(BarcodeFormat.EAN_13, value, widthPx, heightPx)

    private fun renderer(
        factory: BarcodeBitmapFactory,
        dispatcher: kotlinx.coroutines.CoroutineDispatcher,
        cache: SizedLruCache<BarcodeKey, Bitmap> = SizedLruCache(maxSizeBytes = 1_000_000) { 1 },
    ) = DefaultBarcodeRenderer(BarcodeRasterizer(), cache, factory, dispatcher)

    @Test
    @DisplayName("同一个请求第二次直接命中缓存，不再栅格化")
    fun secondIdenticalRequestHitsTheCache() =
        runTest {
            val factory = CountingBitmapFactory()
            val renderer = renderer(factory, StandardTestDispatcher(testScheduler))

            val first = renderer.render(request()) as BarcodeRenderResult.Success
            val second = renderer.render(request()) as BarcodeRenderResult.Success

            assertSame(first.bitmap, second.bitmap, "两次应当是同一张图")
            assertEquals(1, factory.calls, "第二次不该再生成一张新的 Bitmap")
        }

    /** §10.1 要求 key 里含尺寸 —— 少了它，全屏页会拿到列表缩略图那张小的。 */
    @Test
    @DisplayName("只有尺寸不同也算两条缓存记录")
    fun sizeIsPartOfTheCacheKey() =
        runTest {
            val factory = CountingBitmapFactory()
            val renderer = renderer(factory, StandardTestDispatcher(testScheduler))

            val small = renderer.render(request(widthPx = 400)) as BarcodeRenderResult.Success
            val large = renderer.render(request(widthPx = 1000)) as BarcodeRenderResult.Success

            assertNotEquals(small.bitmap, large.bitmap)
            assertEquals(2, factory.calls)
        }

    @Test
    @DisplayName("只有码值不同也算两条缓存记录")
    fun valueIsPartOfTheCacheKey() =
        runTest {
            val factory = CountingBitmapFactory()
            val renderer = renderer(factory, StandardTestDispatcher(testScheduler))

            renderer.render(request())
            renderer.render(request(value = BarcodeFixtures.of(BarcodeFormat.EAN_13).payload.dropLast(1) + "0"))

            // 第二个码值校验位不对 → InvalidPayload，不会再生成 Bitmap；
            // 关键是它没有错误地命中第一条记录。
            assertEquals(1, factory.calls)
        }

    /**
     * 失败不进缓存。否则 T-155 把一张卡的码值改对之后，用户要等到进程重启
     * 才看得见修好的条码 —— 而这种 bug 只会在「改完立刻回到详情页」时出现。
     */
    @Test
    @DisplayName("失败结果不入缓存：改对之后立刻就能画出来")
    fun failuresAreNotCached() =
        runTest {
            val factory = CountingBitmapFactory()
            val cache = SizedLruCache<BarcodeKey, Bitmap>(maxSizeBytes = 1_000_000) { 1 }
            val renderer = renderer(factory, StandardTestDispatcher(testScheduler), cache)

            val broken = renderer.render(request(value = "4006381333930"))
            assertEquals(BarcodeRenderResult.InvalidPayload, broken)
            assertEquals(0, cache.count)

            assertTrue(renderer.render(request()) is BarcodeRenderResult.Success)
        }

    @Test
    @DisplayName("UNKNOWN 与尺寸不足分别收敛成 UnsupportedFormat / TooSmall")
    fun mapsRasterOutcomesToRenderResults() =
        runTest {
            val renderer = renderer(CountingBitmapFactory(), StandardTestDispatcher(testScheduler))

            assertEquals(
                BarcodeRenderResult.UnsupportedFormat,
                renderer.render(BarcodeRenderRequest(BarcodeFormat.UNKNOWN, "x", 1000, 300)),
            )

            val tooSmall = renderer.render(request(widthPx = 30, heightPx = 30))
            assertTrue(tooSmall is BarcodeRenderResult.TooSmall, "实际是 $tooSmall")
            assertTrue((tooSmall as BarcodeRenderResult.TooSmall).minimumWidthPx > 30)
        }

    /** 数一数 `withContext` 到底有没有真的派发过。 */
    private class CountingDispatcher(
        private val delegate: kotlinx.coroutines.CoroutineDispatcher,
    ) : kotlinx.coroutines.CoroutineDispatcher() {
        var dispatches = 0
            private set

        override fun dispatch(
            context: kotlin.coroutines.CoroutineContext,
            block: Runnable,
        ) {
            dispatches++
            delegate.dispatch(context, block)
        }
    }

    /**
     * §10.1：「渲染在 `Dispatchers.Default`」。
     *
     * 断言的是**真的派发到了注入的 dispatcher 上**：如果哪天有人把 `withContext`
     * 删掉，编码就会在调用者线程（多半是 Main）上跑 —— 一张全屏 PDF417 足以让界面掉帧，
     * 而那种掉帧在模拟器上未必看得出来。
     */
    @Test
    @DisplayName("栅格化被派发到注入的 dispatcher 上，事后回到调用者上下文")
    fun rendersOnTheInjectedDispatcher() =
        runTest {
            val dispatcher = CountingDispatcher(StandardTestDispatcher(testScheduler))
            val renderer = renderer(CountingBitmapFactory(), dispatcher)
            val marker = CoroutineName("caller")

            withContext(marker) {
                renderer.render(request())
                // withContext(dispatcher) 结束后必须回到调用者的上下文
                assertEquals(marker, coroutineContext[CoroutineName])
            }

            assertTrue(dispatcher.dispatches > 0, "render() 根本没有切到注入的 dispatcher 上")
        }

    /**
     * 缓存命中也必须走 `withContext`。命中时同步返回、未命中时异步返回的话，
     * 调用方在 Compose 侧会遇到「有时这一帧就有图、有时下一帧才有」的不确定行为 ——
     * 那种 heisenbug 最难查。
     */
    @Test
    @DisplayName("缓存命中同样经过 dispatcher，不走同步捷径")
    fun cacheHitsAlsoGoThroughTheDispatcher() =
        runTest {
            val dispatcher = CountingDispatcher(StandardTestDispatcher(testScheduler))
            val renderer = renderer(CountingBitmapFactory(), dispatcher)

            renderer.render(request())
            val afterFirst = dispatcher.dispatches
            renderer.render(request())

            assertTrue(dispatcher.dispatches > afterFirst, "命中缓存那次没有经过 dispatcher")
        }
}
