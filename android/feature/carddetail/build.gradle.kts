// feature:carddetail —— 卡详情页 + 全屏条码页的界面本体（T-154）。
//
// ⚠️ **全屏条码页的 `Activity` 不在这里，它在 `:app`。**
// §10.2 要它是一个独立 Activity「便于设置窗口属性」，而窗口属性（亮度、常亮、
// FLAG_SECURE、manifest 条目）本来就是组装层的事；更硬的一条是 per-app locales：
// `AppCompatDelegate.setApplicationLocales` 在 API < 33 上只对 AppCompat 组件生效，
// 而 androidx.appcompat 按 libs.versions.toml 的明令**只给 :app**。
// 一个住在本模块的 ComponentActivity 会让这一页用系统语言而不是用户选的语言，
// 且没有任何报错。本模块导出的是 `FullscreenBarcodeRoute` 这个 Composable。
// 完整理由见 :app 的 FullscreenBarcodeActivity 类注释。
//
// 所以这里**不需要** androidx.activity.compose —— setContent 在 :app 那边。

plugins {
    id("ncards.android.feature")

    // 目的地路由（CardDetailRoute）是 @Serializable —— navigation-compose 的
    // 类型安全路由要求它（`composable<CardDetailRoute>` 内部走 `serializer<T>()`）。
    id("ncards.kotlin.serialization")
}

android {
    namespace = "de.ncards.feature.carddetail"
}

// `test` 与 `androidTest` 两个源集互相看不见，而两份假替身必须是**一份**：
// ViewModel 单测与 Compose UI 测试要对着同一批假卡与同一个假渲染器说话。
//
// ⚠️ 必须绕开 `android { sourceSets… }` 这个 Kotlin DSL 访问器：它在 AGP 9 上
// 配置期就崩（DefaultAndroidLibrarySourceSet_Decorated cannot be cast to
// AndroidLibrarySourceSet）。这条路 core:barcode 先趟出来、feature:wallet 照抄。
extensions.configure<com.android.build.api.dsl.LibraryExtension>("android") {
    sourceSets.getByName("test").kotlin.srcDir("src/sharedTest/kotlin")
    sourceSets.getByName("androidTest").kotlin.srcDir("src/sharedTest/kotlin")
}

dependencies {
    // §12.3：feature 一律经 Repository 拿数据，看不见 Room 的任何类型。
    implementation(project(":data:card"))

    // FakeCardRepository（T-155 起是全仓唯一的一份，住在 :data:card 的 testFixtures）。
    // ⚠️ 两条都要：ViewModel 单测在 test、Compose UI 测试在 androidTest，
    // 而两个源集互相看不见 —— 这正是它此前以 src/sharedTest 拷贝存在的原因。
    testImplementation(testFixtures(project(":data:card")))
    androidTestImplementation(testFixtures(project(":data:card")))

    // 条码渲染的唯一入口（T-152）。本卡是它的第一个真实消费者 ——
    // 在此之前 R8 把整条 ZXing 链当死代码剥掉了，dex 里一个字节都搜不到。
    implementation(project(":core:barcode"))

    // ⚠️ `ncards.android.feature` 只把 :core:testing 挂进 testImplementation。
    // 这里再挂一次到 androidTest：仪器测试要的是**同一批** CardFixtures ——
    // feature:wallet 的 WalletScreenTest 当初手抄了一个 `card(...)` 构造器，
    // 于是同一个 Card 在两个源集里各有一份默认值，而那正是「viewer 却带着
    // memberCount」这类假绿的温床（CardFixtures 的注释专门防的就是它）。
    androidTestImplementation(project(":core:testing"))
}
