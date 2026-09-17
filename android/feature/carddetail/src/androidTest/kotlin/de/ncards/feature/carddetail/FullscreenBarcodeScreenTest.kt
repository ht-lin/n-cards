package de.ncards.feature.carddetail

import android.graphics.Bitmap
import androidx.activity.ComponentActivity
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.size
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.toArgb
import androidx.compose.ui.semantics.SemanticsProperties
import androidx.compose.ui.semantics.getOrNull
import androidx.compose.ui.test.assertIsDisplayed
import androidx.compose.ui.test.captureToImage
import androidx.compose.ui.test.junit4.createAndroidComposeRule
import androidx.compose.ui.test.onNodeWithTag
import androidx.compose.ui.unit.dp
import androidx.test.ext.junit.runners.AndroidJUnit4
import de.ncards.core.barcode.render.BarcodeRenderResult
import de.ncards.core.designsystem.theme.NcardsTheme
import de.ncards.core.model.barcode.BarcodeFormat
import de.ncards.core.testing.CardFixtures
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertTrue
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith
import android.graphics.Color as AndroidColor

/**
 * 全屏条码页的 Compose UI 测试。
 *
 * 三件事在这里守着，每一件单测都够不着：
 * 1. **码值在三种失败态下仍然显示** —— §10.2 的硬要求是关于码值的，不是关于那张图的。
 * 2. **码值节点带 `contentDescription`，而且那不是原样的码值** —— 否则 TTS 会把
 *    它念成一个天文数字，「口述给收银员」这个功能等于没做。
 * 3. **深色主题下背景仍然是纯白** —— 这一条只有截图断言证得了。
 */
@RunWith(AndroidJUnit4::class)
class FullscreenBarcodeScreenTest {
    @get:Rule
    val composeTestRule = createAndroidComposeRule<ComponentActivity>()

    private val renderer = FakeBarcodeRenderer()

    private fun setContent(
        state: CardDetailUiState,
        darkTheme: Boolean = false,
    ) {
        composeTestRule.setContent {
            NcardsTheme(darkTheme = darkTheme, dynamicColor = false) {
                FullscreenBarcodeScreen(
                    state = state,
                    renderer = renderer,
                    onClose = {},
                    onBarcodeDrawn = {},
                )
            }
        }
    }

    @Test
    fun showsTheValueAndCloseAction() {
        renderer.returns(BarcodeRenderResult.UnsupportedFormat)
        setContent(CardDetailUiState.Content(CardFixtures.card()))

        composeTestRule.onNodeWithTag(FULLSCREEN_VALUE_TAG).assertIsDisplayed()
        composeTestRule.onNodeWithTag(FULLSCREEN_CLOSE_TAG).assertIsDisplayed()
    }

    /*
     * ⚠️ 本项目里「画不出条码」有三种结局，三种都**必须**保留码值 ——
     * 那是用户在收银台前唯一还能用的东西（念给收银员）。
     *
     * 三条各写一个测试而不是一个循环：`ComposeTestRule.setContent` 一个测试里
     * 只能调一次（第二次会抛 "Content has already been set"），
     * 而三条失败态各自是独立的断言，合在一起也不会更好读。
     */

    @Test
    fun valueSurvivesUnsupportedFormat() {
        renderer.returns(BarcodeRenderResult.UnsupportedFormat)
        setContent(CardDetailUiState.Content(CardFixtures.card()))

        composeTestRule.onNodeWithTag(FULLSCREEN_VALUE_TAG).assertIsDisplayed()
    }

    @Test
    fun valueSurvivesInvalidPayload() {
        renderer.returns(BarcodeRenderResult.InvalidPayload)
        setContent(CardDetailUiState.Content(CardFixtures.card()))

        composeTestRule.onNodeWithTag(FULLSCREEN_VALUE_TAG).assertIsDisplayed()
    }

    @Test
    fun valueSurvivesTooSmall() {
        renderer.returns(BarcodeRenderResult.TooSmall(minimumWidthPx = 4000, minimumHeightPx = 4000))
        setContent(CardDetailUiState.Content(CardFixtures.card()))

        composeTestRule.onNodeWithTag(FULLSCREEN_VALUE_TAG).assertIsDisplayed()
    }

    /**
     * ⚠️ 这一条是 §10.2 那句「带 `contentDescription`，使 TalkBack 用户可以让
     * 系统朗读、**口述给收银员**」的可执行形态。
     *
     * 断言两件事：它存在，而且它**不等于**原样的码值 —— 后者是最容易写出来
     * 也最没用的版本（TTS 会念成一个天文数字）。
     */
    @Test
    fun valueCarriesASpokenContentDescription() {
        renderer.returns(BarcodeRenderResult.UnsupportedFormat)
        val card = CardFixtures.card(barcodeValue = "4012345678901")
        setContent(CardDetailUiState.Content(card))

        val description =
            composeTestRule
                .onNodeWithTag(FULLSCREEN_VALUE_TAG)
                .fetchSemanticsNode()
                .config
                .getOrNull(SemanticsProperties.ContentDescription)
                ?.firstOrNull()

        assertNotNull("码值节点必须有 contentDescription（§10.2 硬要求）", description)
        assertTrue(
            "contentDescription 不能是原样的码值——TTS 会把它念成一个大数",
            description != card.barcodeValue,
        )
        assertTrue("朗读串里应当逐个数字", description!!.contains("4, 0, 1"))
    }

    /**
     * ⚠️ `NcardsTheme` 的 KDoc 明令这一页不得使用深色配色（深色模式下的条码是
     * 扫码失败的经典原因，§10.1 / §1.3）。这条断言是那句话唯一的可执行形态 ——
     * 编译器与 lint 都拦不住有人把 `Color.White` 换成
     * `MaterialTheme.colorScheme.surface`（浅色下它是 `#FDFCFF`，也不是纯白）。
     */
    @Test
    fun backgroundStaysPureWhiteEvenInDarkTheme() {
        renderer.returns(BarcodeRenderResult.UnsupportedFormat)

        composeTestRule.setContent {
            NcardsTheme(darkTheme = true, dynamicColor = false) {
                Box(modifier = Modifier.fillMaxSize()) {
                    FullscreenBarcodeScreen(
                        state = CardDetailUiState.Content(CardFixtures.card()),
                        renderer = renderer,
                        onClose = {},
                        onBarcodeDrawn = {},
                    )
                }
            }
        }

        val image = composeTestRule.onNodeWithTag(FULLSCREEN_TAG).captureToImage()
        val pixels = IntArray(image.width * image.height)
        image.readPixels(pixels)

        fun pixelAt(
            x: Int,
            y: Int,
        ) = pixels[y * image.width + x]

        /*
         * ⚠️ 取**四角**，不取 `pixels[pixels.size / 2]`。
         *
         * 那个下标不是图像中心：宽度为偶数时，是**高度的奇偶**决定它落在哪 ——
         * 高度为偶得到 (0, h/2)（左边缘，必白），高度为奇得到 (w/2, h/2)（正中央，
         * 而这个测试把渲染器打成 UnsupportedFormat，正中央画的就是那段居中的兜底文案）。
         * 于是同一份代码在 api34（edge-to-edge，safeDrawing 吃掉 inset 后高度为偶）绿、
         * 在 api26 红：Pixel 2 非 edge-to-edge，节点实测 **1080×1731**，奇数。
         * 而红出来的值是 `#B4B4B4` —— 黑字抗锯齿的灰，与「底色是不是纯白」毫无关系。
         *
         * 四角在任何状态下都是静区本身：标题行有 16dp 横向内边距，兜底文案有
         * 24dp，条码按位图自身尺寸居中画。
         */
        val corners =
            mapOf(
                "左上" to pixelAt(CORNER_INSET, CORNER_INSET),
                "右上" to pixelAt(image.width - 1 - CORNER_INSET, CORNER_INSET),
                "左下" to pixelAt(CORNER_INSET, image.height - 1 - CORNER_INSET),
                "右下" to pixelAt(image.width - 1 - CORNER_INSET, image.height - 1 - CORNER_INSET),
            )

        corners.forEach { (corner, pixel) ->
            assertEquals("全屏条码页在深色主题下也必须是纯白底（$corner）", Color.White.toArgb(), pixel)
        }
    }

    /**
     * 渲染成功那一格：位图**按 1:1 画**，`onBarcodeDrawn` 在它真的被组合出来
     * 之后触发 —— 那是 §9.1「条码完全渲染」唯一诚实的定义，也是
     * `FullscreenBarcodeActivity` 报 `reportFullyDrawn` 的那个信号。
     */
    @Test
    fun reportsWhenTheBarcodeIsActuallyDrawn() {
        val bitmap =
            Bitmap.createBitmap(120, 40, Bitmap.Config.ARGB_8888).apply {
                eraseColor(AndroidColor.BLACK)
            }
        renderer.returns(BarcodeRenderResult.Success(bitmap))

        var drawn = false
        composeTestRule.setContent {
            NcardsTheme(darkTheme = false, dynamicColor = false) {
                Box(modifier = Modifier.size(PREVIEW_BOX)) {
                    FullscreenBarcodeScreen(
                        state = CardDetailUiState.Content(CardFixtures.card()),
                        renderer = renderer,
                        onClose = {},
                        onBarcodeDrawn = { drawn = true },
                    )
                }
            }
        }

        composeTestRule.waitUntil(WAIT_MILLIS) { drawn }
        assertTrue(drawn)
    }

    /**
     * 尺寸必须是 View 的**实际像素**（§10.1：不要生成小图再放大）。
     * 顺带钉住一维码的高度封顶 —— 不封顶的话横屏满屏那一张会越过条码缓存的
     * 5 MiB 地板，`SizedLruCache.put` 直接不收，而且没有任何症状。
     */
    @Test
    fun requestsTheRealPixelSizeAndCapsOneDimensionalHeight() {
        renderer.returns(BarcodeRenderResult.UnsupportedFormat)
        setContent(CardDetailUiState.Content(CardFixtures.card(barcodeFormat = BarcodeFormat.EAN_13)))

        composeTestRule.waitUntil(WAIT_MILLIS) { renderer.lastRequest != null }

        val request = renderer.lastRequest!!
        assertTrue("宽度必须是真实像素", request.widthPx > 0)
        assertTrue("一维码高度要封顶", request.heightPx in 1..ONE_D_HEIGHT_CEILING)
    }

    private companion object {
        val PREVIEW_BOX = 400.dp
        const val WAIT_MILLIS = 5_000L

        /**
         * 四角各内缩两像素：躲开节点边界上那一列可能被相邻内容抗锯齿沾到的像素，
         * 又远不到任何文字或条码能画进来的地方。
         */
        const val CORNER_INSET = 2

        /** 与 `BarcodeSurface` 里的 `ONE_D_MAX_HEIGHT_PX` 是同一个数。 */
        const val ONE_D_HEIGHT_CEILING = 600
    }
}
