// feature:wallet —— 由 M1–M4 的对应任务填充（§12.3）。
//
// T-153 建起本模块：Room Flow 驱动的列表、搜索、置顶、拖拽排序、
// 颜色/首字母图标、空状态、sync_state 徽章。
//
// ⚠️ 这里只需要一行依赖：ncards.android.feature 已经自动接上
// core:{model,common,designsystem,ui} 与 lifecycle / navigation，
// 并把 :core:testing 挂进 testImplementation。

plugins {
    id("ncards.android.feature")
    // 目的地路由（WalletRoute）是 @Serializable —— navigation-compose 的
    // 类型安全路由要求它（`composable<WalletRoute>` 内部走 `serializer<T>()`）。
    id("ncards.kotlin.serialization")
}

android {
    namespace = "de.ncards.feature.wallet"
}

// `test` 与 `androidTest` 两个源集互相看不见，而 FakeCardRepository 必须是**一份**：
// ViewModel 单测与 Compose UI 测试要对着同一批假卡说话，各写一份的话它们会漂。
//
// ⚠️ 必须绕开 `android { sourceSets… }` 这个 Kotlin DSL 访问器：它在 AGP 9 上
// 配置期就崩（DefaultAndroidLibrarySourceSet_Decorated cannot be cast to
// AndroidLibrarySourceSet），`getByName` 与 `named { }` 一样崩。
// 显式按新 DSL 的类型取扩展就没有这个问题 —— 这条路 core:barcode 已经验过。
//
// （feature:onboarding 留着两份 FakeAuthRepository，是因为它落地时这个解法还没有。）
extensions.configure<com.android.build.api.dsl.LibraryExtension>("android") {
    sourceSets.getByName("test").kotlin.srcDir("src/sharedTest/kotlin")
    sourceSets.getByName("androidTest").kotlin.srcDir("src/sharedTest/kotlin")
}

dependencies {
    // §12.3：feature 一律经 Repository 拿数据，看不见 Room 的任何类型。
    implementation(project(":data:card"))
}
