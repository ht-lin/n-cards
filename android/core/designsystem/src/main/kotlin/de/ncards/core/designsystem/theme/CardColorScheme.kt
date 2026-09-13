package de.ncards.core.designsystem.theme

import androidx.compose.runtime.Immutable
import androidx.compose.ui.graphics.Color
import de.ncards.core.model.card.CardColor

/**
 * 卡片调色板的**色值**（Q7 / T-153）。键在 `core:model` 的
 * [CardColor]，这里只回答「那个键是哪个颜色」。
 *
 * ============================================================================
 * ⚠️ Q7 的状态：工程侧闭环，待设计复核
 * ============================================================================
 * §17.5 把 Q7 的决策人写作「设计」，而设计没有交付。T-153 不能停下等 ——
 * 「颜色/首字母图标」是本卡的交付物。
 *
 * 所以色值由工程侧定，并且把 §11.2 那句「卡片颜色调色板需**逐个校验** ≥ 4.5:1」
 * 做成了一条**自动化断言**（`CardColorContrastTest`）。设计后来换色值时，
 * 那条测试会当场告诉他新值过不过得了无障碍门禁 —— 这比一份 PDF 上的色卡可靠。
 *
 * `Color.kt` 里那句「不要在这里先编几个色值顶上」的担忧是
 * 「编出来的值必然过不了那一关，而届时已经有 UI 代码引用它们了」。
 * 那个担忧被化解的方式不是「我编得准」，而是**那一关现在自己会跑**。
 *
 * ============================================================================
 * 三条设计约束，每一条都来自规格
 * ============================================================================
 * 1. **不从 `MaterialTheme.colorScheme` 派生。** [NcardsTheme] 默认
 *    `dynamicColor = true`，Android 12+ 上整个配色跟着用户壁纸走。派生出来的
 *    卡片色每台设备都不一样，对比度断言当场失去意义 —— 断言在 CI 上量的是
 *    开发机的值，用户手机上是另一组。所以这些是**独立的固定色**。
 *
 * 2. **深浅主题用同一组值。** 卡片色是卡的**身份**（用户靠颜色一眼认出
 *    「绿的那张是 REWE」），不是主题的一部分；跟着主题翻转会让同一张卡
 *    早晚看起来是两张。饱和深底 + 白字在浅色与深色背景上都成立，
 *    所以一组值够用，也就只有一组值需要校验。
 *
 * 3. **颜色永远不是唯一的信息载体**（§11.2）。卡片磁贴上除了颜色还有
 *    首字母与标题；`sync_state` 徽章另带图标或文字。色弱用户不会因为
 *    两张卡的颜色分不清而认错卡。
 *
 * ⚠️ 改任何一个色值都要重跑 `:core:designsystem:test`。
 */
@Immutable
data class CardColorScheme(
    /** 磁贴底色。 */
    val container: Color,
    /** 画在 [container] 上的首字母与图标。对比度 ≥ 4.5:1 由测试保证。 */
    val onContainer: Color,
)

/**
 * 全部十一格的前景色。
 *
 * 统一是纯白：这让「逐个校验」变成十一次一元检查而不是十一次二元搭配，
 * 而且白字在饱和深底上是收银台那种斜视角、高眩光环境下最稳的组合
 * （§1.3 的 North Star 场景）。
 */
private val CardOnContainer = Color(0xFFFFFFFF)

/**
 * 键 → 色值。**全仓唯一的一处 `when (color)`**，与
 * `core:barcode` 的 `BarcodeFormatTable` 同一个形状（ADR-0023）：
 * 加第十二个色会在这里编译失败，逼人补齐色值，而不是悄悄走进某个 `else` 分支。
 *
 * ⚠️ 所以**不要给它加 `else`**。
 */
fun cardColorSchemeOf(color: CardColor): CardColorScheme =
    CardColorScheme(
        container =
            when (color) {
                CardColor.BLUE -> CardBlue
                CardColor.INDIGO -> CardIndigo
                CardColor.TEAL -> CardTeal
                CardColor.GREEN -> CardGreen
                CardColor.OLIVE -> CardOlive
                CardColor.ORANGE -> CardOrange
                CardColor.RED -> CardRed
                CardColor.PINK -> CardPink
                CardColor.PURPLE -> CardPurple
                CardColor.BROWN -> CardBrown
                CardColor.SLATE -> CardSlate
            },
        onContainer = CardOnContainer,
    )

/*
 * 十一格的底色。
 *
 * 括号里是 `CardColorContrastTest` 实测的对比度（对白字），全部 ≥ 4.5:1 ——
 * 但**不要**照着这里的数字去信，去跑那条测试：注释会过期，断言不会。
 */
private val CardBlue = Color(0xFF1565C0) // 5.75:1
private val CardIndigo = Color(0xFF283593) // 10.39:1
private val CardTeal = Color(0xFF00695C) // 6.61:1
private val CardGreen = Color(0xFF2E7D32) // 5.13:1
private val CardOlive = Color(0xFF33691E) // 6.60:1
private val CardOrange = Color(0xFFBF360C) // 5.60:1
private val CardRed = Color(0xFFC62828) // 5.62:1
private val CardPink = Color(0xFFAD1457) // 6.97:1
private val CardPurple = Color(0xFF6A1B9A) // 9.39:1
private val CardBrown = Color(0xFF4E342E) // 11.32:1
private val CardSlate = Color(0xFF37474F) // 9.65:1
