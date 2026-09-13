package de.ncards.core.designsystem.theme

import androidx.compose.ui.graphics.Color
import de.ncards.core.model.card.CardColor
import org.junit.jupiter.api.Assertions.assertEquals
import org.junit.jupiter.api.Assertions.assertTrue
import org.junit.jupiter.api.DisplayName
import org.junit.jupiter.api.DynamicTest
import org.junit.jupiter.api.Test
import org.junit.jupiter.api.TestFactory
import kotlin.math.pow

/**
 * §11.2 的「文本对比度 ≥ 4.5:1；**卡片颜色调色板需逐个校验**」——
 * 这个文件就是「逐个校验」那四个字。
 *
 * ============================================================================
 * 它为什么是 Q7 闭环的凭据
 * ============================================================================
 * Q7 的决策人是设计，交付物是一组色值。工程侧先定了一组（见 [cardColorSchemeOf]），
 * 而这条测试让「设计后来换掉它们」变成一件安全的事：改完跑
 * `./gradlew :core:designsystem:test`，过不了的色值当场就知道。
 *
 * 没有它的话，`Color.kt` 里那句担忧是对的 —— 「编出来的值必然过不了那一关，
 * 而届时已经有 UI 代码引用它们了」。有了它，那一关在每次 PR 上自己跑。
 *
 * ⚠️ `:core:designsystem` 在 `COVERAGE_EXEMPT` 里（Kover 不统计它），
 * 所以这个文件**不是为了覆盖率**。删掉它不会有任何门禁变红 —— 但 §11.2
 * 的那一条就没有任何东西守着了。
 *
 * ============================================================================
 * 算法：WCAG 2.1 的相对亮度与对比度
 * ============================================================================
 * 逐字照 https://www.w3.org/TR/WCAG21/#dfn-relative-luminance 实现，
 * **不依赖任何库** —— Android 的 `ColorUtils.calculateContrast` 要 Robolectric
 * 或真机，而这是一段纯算术，跑在裸 JVM 上就够（本模块的单测因此不需要
 * Robolectric，与 `core:barcode` 把渲染往返放在裸 JVM 上是同一条理由）。
 */
@DisplayName("卡片调色板的对比度（§11.2 / Q7）")
class CardColorContrastTest {
    /**
     * 每一个 [CardColor] 一条独立的测试。
     *
     * 用 `@TestFactory` 而不是一个 `forEach` 的循环：循环在第一个失败的颜色上
     * 就停了，而换调色板时最想知道的恰恰是「**哪几个**过不了」。
     */
    @TestFactory
    @DisplayName("每一格的前景/背景对比度 ≥ 4.5:1")
    fun everyCardColorMeetsWcagAa(): List<DynamicTest> =
        CardColor.entries.map { color ->
            val scheme = cardColorSchemeOf(color)
            val ratio = contrastRatio(scheme.container, scheme.onContainer)

            DynamicTest.dynamicTest("${color.wireName} = %.2f:1".format(ratio)) {
                assertTrue(
                    ratio >= WCAG_AA_NORMAL_TEXT,
                    "${color.wireName} 的对比度是 %.2f:1，低于 §11.2 要求的 %.1f:1。".format(
                        ratio,
                        WCAG_AA_NORMAL_TEXT,
                    ) + "把 container 调暗或把 onContainer 调亮。",
                )
            }
        }

    /**
     * 调色板里不许有两格是同一个颜色。
     *
     * 复制粘贴一行忘了改色值，是这张表最现实的错误 —— 而它没有任何症状：
     * 编译过、对比度测试也过（同一个色当然过），只是用户从此有两张「一模一样的绿卡」，
     * 而颜色正是他用来区分它们的东西。
     */
    @Test
    @DisplayName("十一格互不重色")
    fun everyCardColorIsDistinct() {
        val containers = CardColor.entries.map { cardColorSchemeOf(it).container }

        assertEquals(
            CardColor.entries.size,
            containers.toSet().size,
            "调色板里有重复的色值：$containers",
        )
    }

    private companion object {
        /** WCAG 2.1 AA 对正文文字的门槛。大文本是 3:1，这里不用那条宽的。 */
        const val WCAG_AA_NORMAL_TEXT = 4.5

        /**
         * WCAG 的相对亮度。
         *
         * ⚠️ 那个 `0.03928` 的分段与 `2.4` 的幂是规范里写死的常量，
         * 不是「大约等于 sRGB 的 gamma」—— 别用 2.2 去近似它，
         * 那会让接近门槛的颜色算出一个过得了而实际过不了的比值。
         */
        fun relativeLuminance(color: Color): Double {
            fun channel(value: Float): Double {
                val c = value.toDouble()
                return if (c <= 0.03928) c / 12.92 else ((c + 0.055) / 1.055).pow(2.4)
            }

            return 0.2126 * channel(color.red) +
                0.7152 * channel(color.green) +
                0.0722 * channel(color.blue)
        }

        /** `(L_亮 + 0.05) / (L_暗 + 0.05)`，与前后景谁是谁无关。 */
        fun contrastRatio(
            a: Color,
            b: Color,
        ): Double {
            val la = relativeLuminance(a)
            val lb = relativeLuminance(b)

            return (maxOf(la, lb) + 0.05) / (minOf(la, lb) + 0.05)
        }
    }
}
