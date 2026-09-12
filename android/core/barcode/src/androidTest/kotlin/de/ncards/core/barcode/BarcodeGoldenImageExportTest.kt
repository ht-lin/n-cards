package de.ncards.core.barcode

import android.graphics.Bitmap
import android.graphics.Color
import androidx.test.ext.junit.runners.AndroidJUnit4
import androidx.test.platform.app.InstrumentationRegistry
import de.ncards.core.barcode.cache.SizedLruCache
import de.ncards.core.barcode.render.AndroidBarcodeBitmapFactory
import de.ncards.core.barcode.render.BarcodeRasterizer
import de.ncards.core.barcode.render.BarcodeRenderRequest
import de.ncards.core.barcode.render.BarcodeRenderResult
import de.ncards.core.barcode.render.BarcodeRenderer
import de.ncards.core.barcode.render.DefaultBarcodeRenderer
import de.ncards.core.barcode.render.RasterOutcome
import de.ncards.core.model.barcode.BarcodeDimension
import de.ncards.core.model.barcode.BarcodeFormat
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.runBlocking
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test
import org.junit.runner.RunWith
import java.io.File

/**
 * 验收标准的后半句是「真机上用扫码枪/另一台手机实测能读出 EAN-13 与 QR」。
 * T-154 之前没有任何界面会显示条码，**但真机上能证明的那一部分不需要界面 ——
 * 只需要真屏幕上的一张真图**。
 *
 * 这条测试做两件事：
 *
 * 1. **是一条真断言**：在真设备上走一遍 `Bitmap.createBitmap`（单测里那一步是
 *    被假 factory 替掉的），断言尺寸与白底。
 * 2. **导出 PNG** 到应用的外部文件目录，供 `adb pull` 之后在屏幕上实扫。
 *    手工步骤写在 android/README.md 的「条码渲染」一节。
 *
 * Kover 不统计 androidTest，所以这条对 70% 门禁没有任何影响 —— 它不是用来
 * 凑覆盖率的。GMD 只在 main 合入后跑（§14.3），不拖慢 PR。
 *
 * ⚠️ 载荷一律用 [BarcodeFixtures] 的定值，**绝不使用任何用户数据**。
 */
@RunWith(AndroidJUnit4::class)
class BarcodeGoldenImageExportTest {
    private val rasterizer = BarcodeRasterizer()

    @Test
    fun exportsEveryFormatAsPngAndRendersBlackOnWhite() {
        val context = InstrumentationRegistry.getInstrumentation().targetContext
        val metrics = context.resources.displayMetrics
        val widthPx = metrics.widthPixels
        val outputDir = File(context.getExternalFilesDir(null), "barcode-golden").apply { mkdirs() }

        BarcodeFixtures.all.forEach { fixture ->
            val heightPx = if (fixture.format.dimension == BarcodeDimension.ONE_D) {
                widthPx / 2
            } else {
                metrics.heightPixels
            }

            val outcome = rasterizer.rasterize(fixture.format, fixture.payload, widthPx, heightPx)
            assertTrue("${fixture.format} 在 ${widthPx}x$heightPx 上渲染失败：$outcome", outcome is RasterOutcome.Success)

            val bitmap = AndroidBarcodeBitmapFactory.create((outcome as RasterOutcome.Success).raster)

            assertTrue("${fixture.format} 尺寸为 0", bitmap.width > 0 && bitmap.height > 0)
            // 强制白底黑码（§10.1）：四角必为白，且与主题无关 —— 这一页从来不问主题。
            listOf(
                0 to 0,
                bitmap.width - 1 to 0,
                0 to bitmap.height - 1,
                bitmap.width - 1 to bitmap.height - 1,
            ).forEach { (x, y) ->
                assertEquals("${fixture.format} 的角像素不是白的", Color.WHITE, bitmap.getPixel(x, y))
            }

            File(outputDir, "${fixture.format.name.lowercase()}.png").outputStream().use { out ->
                bitmap.compress(Bitmap.CompressFormat.PNG, 100, out)
            }
            bitmap.recycle()
        }

        // 让失败日志与 adb pull 都能直接看到路径。
        assertEquals(BarcodeFormat.renderable.size, outputDir.listFiles().orEmpty().size)
    }

    /**
     * 真机上跑一遍完整的 [BarcodeRenderer]（含缓存与真的 `Bitmap`）。
     *
     * 单测里 `allocationByteCount` 是假的（factory 被替掉了），所以「按字节淘汰」
     * 这条路径只有在真机上才走得到真数字。
     */
    @Test
    fun rendererReturnsTheSameBitmapForARepeatedRequest() =
        runBlocking {
            val renderer = DefaultBarcodeRenderer(
                rasterizer = rasterizer,
                cache = SizedLruCache(maxSizeBytes = 8 * 1024 * 1024) { it.allocationByteCount },
                bitmaps = AndroidBarcodeBitmapFactory,
                dispatcher = Dispatchers.Default,
            )
            val request = BarcodeRenderRequest(
                BarcodeFormat.EAN_13,
                BarcodeFixtures.of(BarcodeFormat.EAN_13).payload,
                widthPx = 1000,
                heightPx = 300,
            )

            val first = renderer.render(request) as BarcodeRenderResult.Success
            val second = renderer.render(request) as BarcodeRenderResult.Success

            assertTrue("缓存没有命中", first.bitmap === second.bitmap)
        }
}
