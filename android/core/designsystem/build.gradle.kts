// Material 3 主题、颜色 token、Typography、基础组件（§12.3）。
//
// T-008 交付主题骨架。T-153 补上卡片调色板（Q7）：键在 core:model 的 CardColor，
// 色值在 CardColors.kt，§11.2 那句「逐个校验 ≥ 4.5:1」由 CardColorContrastTest
// 变成一条会跑的断言 —— 换色值时它会当场说过不过得了。

plugins {
    id("ncards.android.library")
    id("ncards.android.compose")
}

android {
    namespace = "de.ncards.core.designsystem"
}

dependencies {
    // cardColorSchemeOf(CardColor) 的入参出现在本模块暴露给上游的签名上，
    // 所以是 api 而不是 implementation —— 与 core:barcode 对 core:model 同理。
    api(project(":core:model"))
}
