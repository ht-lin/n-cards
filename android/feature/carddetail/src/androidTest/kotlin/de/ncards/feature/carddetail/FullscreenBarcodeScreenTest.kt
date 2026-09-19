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

        /*
         * ⚠️ 取**四个角**，不要取「正中间」。
         *
         * `pixels[pixels.size / 2]` 看着像中心点，其实 `width * height / 2` 落在哪儿
         * 要看高度的奇偶：偶数时是 `(row = h/2, col = 0)`，最左边一列；奇数时才是
         * `(row = (h-1)/2, col = w/2)`，正中央 —— 而正中央恰好是 `BarcodeFallback`
         * 那行居中兜底文案的所在（本测试里 renderer 返回 `UnsupportedFormat`），
         * 命中的是抗锯齿字形边缘的灰。T-154 就是这么在 api26（Pixel 2，内容高度为
         * 奇数）红、在 api34（Pixel 6）绿的 —— 同一段产品代码，两个结果。
         *
         * 四个角在任何渲染结果下都落在那个 `Box` 的背景上（`background(BarcodeWhite)`
         * 排在 `safeDrawingPadding()` 之前，背景铺满整个节点），而这条断言要守的
         * 本来就是背景。有人把 `BarcodeWhite` 换成 `surface`（`#FDFCFF`）照样抓得住。
         */
        mapOf(
            "左上" to 0,
            "右上" to image.width - 1,
            "左下" to (image.height - 1) * image.width,
            "右下" to image.width * image.height - 1,
        ).forEach { (corner, index) ->
            assertEquals(
                "全屏条码页在深色主题下也必须是纯白底（$corner）",
                Color.White.toArgb(),
                pixels[index],
            )
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

        /** 与 `BarcodeSurface` 里的 `ONE_D_MAX_HEIGHT_PX` 是同一个数。 */
        const val ONE_D_HEIGHT_CEILING = 600
    }
}
