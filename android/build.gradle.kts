// 构建根。**刻意保持为空。**
//
// 这里没有 `subprojects { }` / `allprojects { }`，是一个决定而不是遗漏：
// 那两个块会跨项目读写模型，与 Gradle 9.7 起 incubating 的 Isolated Projects 冲突，
// 而 AGP 9 正在往那个方向推。ktlint / detekt / kover / 模块依赖规则一律由
// build-logic 的 convention plugin 逐模块施加 —— 每个模块必然经过
// ncards.android.{application,library} 或 ncards.jvm.library 其中之一（benchmark 显式应用），
// 覆盖率由 ModuleGraphTest 读 settings.gradle.kts 断言，不靠这里兜底。
//
// 从根跑 `./gradlew ktlintCheck` / `detekt` 依然能覆盖全部模块 —— Gradle 会把
// 不带路径的任务名匹配到所有子项目上。

plugins {
    // 只做版本声明，不在根项目应用。各模块经 convention plugin 引入。
    alias(libs.plugins.android.application) apply false
    alias(libs.plugins.android.library) apply false
    alias(libs.plugins.android.test) apply false
    // ⚠️ 这里**没有** org.jetbrains.kotlin.android：AGP 9 自带 Kotlin 支持，
    // 应用那个插件会被 AGP 直接拒绝。kotlin.jvm 仍然需要 —— core:model 是
    // 全仓库唯一的非 Android 模块（见 ncards.jvm.library）。
    alias(libs.plugins.kotlin.jvm) apply false
    alias(libs.plugins.kotlin.compose) apply false
    alias(libs.plugins.kotlin.serialization) apply false
    alias(libs.plugins.ksp) apply false
    alias(libs.plugins.hilt) apply false
    alias(libs.plugins.ktlint) apply false
    alias(libs.plugins.detekt) apply false
    alias(libs.plugins.kover) apply false
}
