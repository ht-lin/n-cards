// Material 3 主题、颜色 token、Typography、基础组件（§12.3）。
//
// T-008 交付主题骨架；卡片调色板的具体色值是 Q7（设计交付，阻塞 T-153），
// 见 Color.kt 里的说明 —— 不要自己编色值，§11.2 要求逐个校验 4.5:1 对比度。

plugins {
    id("ncards.android.library")
    id("ncards.android.compose")
}

android {
    namespace = "de.ncards.core.designsystem"
}
