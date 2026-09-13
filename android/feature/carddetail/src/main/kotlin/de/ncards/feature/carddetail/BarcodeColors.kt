package de.ncards.feature.carddetail

import androidx.compose.ui.graphics.Color

/*
 * 全屏条码页的两个颜色。**写死，不从 MaterialTheme 取。**
 *
 * `core:designsystem` 的 `NcardsTheme` KDoc 逐字要求这一条：
 *
 * > ⚠️ **全屏条码页不得使用本主题的深色配色。** §10.1 / T-152：条码必须强制
 * > 白底黑码，无论 App 主题深浅 —— 深色模式下的条码是扫码失败的经典原因，
 * > 而 §1.3 的 North Star 场景就是「收银台前必须一次读出」。那一页自己写死颜色，
 * > 不要从 MaterialTheme 取。
 *
 * ⚠️ **`MaterialTheme.colorScheme.surface` 也不行，哪怕在浅色主题下。**
 * 浅色配色里它是 `NcardsNeutral99` = `#FDFCFF`，`onSurface` 是 `#1A1C1E` ——
 * 两个都不是纯色。条码周围那圈静区必须是**纯白**，否则它就不是静区；
 * 而 `NcardsTheme` 默认还开着 `dynamicColor`，Android 12+ 上整套配色跟着壁纸走。
 *
 * 所以全屏页做两件事：`NcardsTheme(darkTheme = false, dynamicColor = false)`
 * 拿排版与组件默认值，条码那一块的颜色用这里的两个常量。缺任何一件都不够。
 */

/** 条码底色。纯白，不是 `surface`。 */
internal val BarcodeWhite = Color(0xFFFFFFFF)

/** 条码与码值的前景。纯黑，不是 `onSurface`。 */
internal val BarcodeBlack = Color(0xFF000000)
