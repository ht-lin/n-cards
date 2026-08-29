// 组装层：NavHost、Application、DI 根（§12.3）。
//
// 这是唯一允许依赖 feature:* / sync / widget 的模块 —— 跨 feature 的导航在这里
// 汇合，feature 之间因此不需要互相引用（§12.3 的第二条规则）。

plugins {
    id("ncards.android.application")
    id("ncards.android.compose")
    id("ncards.android.hilt")
}

android {
    namespace = "de.ncards"

    defaultConfig {
        applicationId = "de.ncards"
    }
}

dependencies {
    implementation(project(":core:model"))
    implementation(project(":core:common"))
    implementation(project(":core:designsystem"))
    implementation(project(":core:ui"))

    // DI 根要看得见各 data 模块的 Hilt Module 才能装配它们。
    implementation(project(":data:auth"))
    implementation(project(":data:card"))
    implementation(project(":data:friend"))
    implementation(project(":data:sharing"))
    implementation(project(":data:sync"))

    // §12.3：sync 只被 app 依赖（绑定 Worker 与 FcmService），feature 一律经 Repository。
    implementation(project(":sync"))
    implementation(project(":widget"))

    implementation(project(":feature:onboarding"))
    implementation(project(":feature:wallet"))
    implementation(project(":feature:carddetail"))
    implementation(project(":feature:cardedit"))
    implementation(project(":feature:scan"))
    implementation(project(":feature:imageimport"))
    implementation(project(":feature:sharing"))
    implementation(project(":feature:friends"))
    implementation(project(":feature:settings"))
    implementation(project(":feature:legal"))

    implementation(libs.androidx.core.ktx)
    implementation(libs.androidx.activity.compose)
    implementation(libs.androidx.lifecycle.runtime.ktx)
    implementation(libs.androidx.navigation.compose)
    implementation(libs.timber)
}
