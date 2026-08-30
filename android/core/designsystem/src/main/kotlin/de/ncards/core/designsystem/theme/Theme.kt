package de.ncards.core.designsystem.theme

import android.os.Build
import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.darkColorScheme
import androidx.compose.material3.dynamicDarkColorScheme
import androidx.compose.material3.dynamicLightColorScheme
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.ui.platform.LocalContext

private val LightColors = lightColorScheme(
    primary = NcardsBlue40,
    onPrimary = NcardsNeutral99,
    primaryContainer = NcardsBlue90,
    onPrimaryContainer = NcardsBlue10,
    secondary = NcardsSlate40,
    onSecondary = NcardsNeutral99,
    secondaryContainer = NcardsSlate90,
    onSecondaryContainer = NcardsSlate10,
    tertiary = NcardsTeal40,
    onTertiary = NcardsNeutral99,
    error = NcardsRed40,
    onError = NcardsNeutral99,
    errorContainer = NcardsRed90,
    onErrorContainer = NcardsRed10,
    background = NcardsNeutral99,
    onBackground = NcardsNeutral10,
    surface = NcardsNeutral99,
    onSurface = NcardsNeutral10,
    surfaceVariant = NcardsNeutral95,
    onSurfaceVariant = NcardsNeutral20,
)

private val DarkColors = darkColorScheme(
    primary = NcardsBlue80,
    onPrimary = NcardsBlue10,
    primaryContainer = NcardsBlue40,
    onPrimaryContainer = NcardsBlue90,
    secondary = NcardsSlate80,
    onSecondary = NcardsSlate10,
    secondaryContainer = NcardsSlate40,
    onSecondaryContainer = NcardsSlate90,
    tertiary = NcardsTeal80,
    onTertiary = NcardsSlate10,
    error = NcardsRed80,
    onError = NcardsRed10,
    errorContainer = NcardsRed40,
    onErrorContainer = NcardsRed90,
    background = NcardsNeutral10,
    onBackground = NcardsNeutral90,
    surface = NcardsNeutral10,
    onSurface = NcardsNeutral90,
    surfaceVariant = NcardsNeutral20,
    onSurfaceVariant = NcardsNeutral90,
)

/**
 * N-Cards 的 Material 3 主题（§12.3 的 core:designsystem 交付物）。
 *
 * ⚠️ **全屏条码页不得使用本主题的深色配色。** §10.1 / T-152：条码必须强制
 * 白底黑码，无论 App 主题深浅 —— 深色模式下的条码是扫码失败的经典原因，
 * 而 §1.3 的 North Star 场景就是「收银台前必须一次读出」。那一页自己写死颜色，
 * 不要从 MaterialTheme 取。
 *
 * @param dynamicColor Android 12+ 的动态取色。默认开启；全屏条码页与 Widget
 *   必须显式关掉（同上）。
 */
@Composable
fun NcardsTheme(
    darkTheme: Boolean = isSystemInDarkTheme(),
    dynamicColor: Boolean = true,
    content: @Composable () -> Unit,
) {
    val context = LocalContext.current
    val dynamicAvailable = dynamicColor && Build.VERSION.SDK_INT >= Build.VERSION_CODES.S

    val colorScheme = when {
        dynamicAvailable && darkTheme -> dynamicDarkColorScheme(context)
        dynamicAvailable -> dynamicLightColorScheme(context)
        darkTheme -> DarkColors
        else -> LightColors
    }

    MaterialTheme(
        colorScheme = colorScheme,
        typography = NcardsTypography,
        content = content,
    )
}
