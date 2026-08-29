package de.ncards.core.designsystem.theme

import androidx.compose.material3.Typography
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.sp

/**
 * Typography（§12.3 的 core:designsystem 交付物）。
 *
 * 两条来自 §11 的约束，改这个文件时要一起守：
 *
 * - **字号一律用 `sp`**（§11.2：系统字体缩放到 200% 不得截断）。
 *   任何地方用 `dp` 表达文字大小，都会让放大字号的用户看到不变的小字。
 * - **行高必须显式给足**（§11.1：德语单词长，`Benachrichtigungseinstellungen`
 *   这种会换行）。所有按钮与标签必须允许换行，不要设固定高度。
 *
 * 一期用系统默认字族：不打包自定义字体，§9.1 的「APK ≤ 20 MB」里没有它的位置，
 * 而系统字族在德语的变音符号上表现本来就正确。
 */
internal val NcardsTypography = Typography(
    displaySmall = TextStyle(
        fontFamily = FontFamily.Default,
        fontWeight = FontWeight.Normal,
        fontSize = 36.sp,
        lineHeight = 44.sp,
    ),
    headlineMedium = TextStyle(
        fontFamily = FontFamily.Default,
        fontWeight = FontWeight.SemiBold,
        fontSize = 28.sp,
        lineHeight = 36.sp,
    ),
    headlineSmall = TextStyle(
        fontFamily = FontFamily.Default,
        fontWeight = FontWeight.SemiBold,
        fontSize = 24.sp,
        lineHeight = 32.sp,
    ),
    titleLarge = TextStyle(
        fontFamily = FontFamily.Default,
        fontWeight = FontWeight.SemiBold,
        fontSize = 22.sp,
        lineHeight = 28.sp,
    ),
    titleMedium = TextStyle(
        fontFamily = FontFamily.Default,
        fontWeight = FontWeight.Medium,
        fontSize = 16.sp,
        lineHeight = 24.sp,
    ),
    bodyLarge = TextStyle(
        fontFamily = FontFamily.Default,
        fontWeight = FontWeight.Normal,
        fontSize = 16.sp,
        lineHeight = 24.sp,
    ),
    bodyMedium = TextStyle(
        fontFamily = FontFamily.Default,
        fontWeight = FontWeight.Normal,
        fontSize = 14.sp,
        lineHeight = 20.sp,
    ),
    labelLarge = TextStyle(
        fontFamily = FontFamily.Default,
        fontWeight = FontWeight.Medium,
        fontSize = 14.sp,
        lineHeight = 20.sp,
    ),
)
