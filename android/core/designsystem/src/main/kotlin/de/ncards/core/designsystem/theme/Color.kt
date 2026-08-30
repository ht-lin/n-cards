package de.ncards.core.designsystem.theme

import androidx.compose.ui.graphics.Color

/*
 * N-Cards 的颜色 token（§12.3 的 core:designsystem 交付物）。
 *
 * 这里只有**应用外壳**的配色。卡片调色板（用户给每张卡选的颜色）是 **Q7**，
 * 由设计交付、阻塞 T-153，**不要在这里先编几个色值顶上** —— §11.2 要求每个
 * 卡片色都逐个校验 ≥ 4.5:1 对比度，编出来的值必然过不了那一关，而届时
 * 已经有 UI 代码引用它们了。
 *
 * §11.2 的对比度要求对下面这些同样适用；改动任何一个色值都要重新校验。
 */

// ---- 主色：卡片钱包的品牌蓝 ------------------------------------------------
internal val NcardsBlue40 = Color(0xFF1B5E9C)
internal val NcardsBlue80 = Color(0xFFA6C8FF)
internal val NcardsBlue90 = Color(0xFFD5E3FF)
internal val NcardsBlue10 = Color(0xFF001B3D)

// ---- 次色：中性偏冷，用于卡片外的容器 --------------------------------------
internal val NcardsSlate40 = Color(0xFF545F70)
internal val NcardsSlate80 = Color(0xFFBCC7DB)
internal val NcardsSlate90 = Color(0xFFD8E3F8)
internal val NcardsSlate10 = Color(0xFF111C2B)

// ---- 强调色：共享状态徽章 --------------------------------------------------
// §11.2：不以颜色作为唯一信息载体 —— 共享徽章必须同时带图标或文字。
internal val NcardsTeal40 = Color(0xFF006A60)
internal val NcardsTeal80 = Color(0xFF53DBC9)

// ---- 错误 ------------------------------------------------------------------
internal val NcardsRed40 = Color(0xFFBA1A1A)
internal val NcardsRed80 = Color(0xFFFFB4AB)
internal val NcardsRed90 = Color(0xFFFFDAD6)
internal val NcardsRed10 = Color(0xFF410002)

// ---- 中性 ------------------------------------------------------------------
internal val NcardsNeutral99 = Color(0xFFFDFCFF)
internal val NcardsNeutral95 = Color(0xFFEFF1F8)
internal val NcardsNeutral10 = Color(0xFF1A1C1E)
internal val NcardsNeutral20 = Color(0xFF2F3033)
internal val NcardsNeutral90 = Color(0xFFE2E2E6)
