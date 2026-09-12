// 扫描（ML Kit）与渲染（ZXing）的唯一入口（§10.1）。**渲染那一半由 T-152 交付**，
// 相机流扫描与静态图片解码由 T-156 / T-157 填进同一个模块 —— §10.1 明令
// 「识别结果必须经过与相机路径完全相同的校验与格式映射（core:barcode 的同一入口）」。
//
// ⚠️ 刻意**没有** ncards.android.compose：本模块产出 android.graphics.Bitmap，
// 不产出 Composable。两个消费方（T-154 的全屏页用 Compose、T-254 的 Glance widget
// 用 ImageProvider）唯一的公约数就是 Bitmap。见 ncards.android.compose 的类注释，
// 那里把 core:barcode 列在「不含 UI」那一组里。

plugins {
    id("ncards.android.library")
    id("ncards.android.hilt")
}

android {
    namespace = "de.ncards.core.barcode"

    // `test` 与 `androidTest` 两个源集互相看不见，而 BarcodeFixtures 必须是**一份** ——
    // 单测的 render→decode 往返与仪器测试导出的 PNG 要能对着同一批码值说话，
    // 各写一份的话它们就失去了共同的参照物。
    //
    // ⚠️ 用 `kotlin.srcDir(...)` 而不是 README「已知事项」里记的那个
    // `sourceSets.getByName("androidTest").assets.srcDir(...)` —— 后者在 AGP 9 上
    // 配置期就崩（DefaultAndroidLibrarySourceSet_Decorated cannot be cast to …）。
    // 这里走 `named { }` + kotlin 源目录，已验证可用。
}

// ⚠️ 必须绕开 `android { sourceSets… }` 这个 Kotlin DSL 访问器：它在 AGP 9 上
// 配置期就崩（DefaultAndroidLibrarySourceSet_Decorated cannot be cast to
// AndroidLibrarySourceSet），`getByName` 与 `named { }` 一样崩 ——
// android/README.md 的「已知事项」里记着这条，那里踩到的是 assets，这里是 kotlin。
// 显式按新 DSL 的类型取扩展就没有这个问题。
extensions.configure<com.android.build.api.dsl.LibraryExtension>("android") {
    sourceSets.getByName("test").kotlin.srcDir("src/sharedTest/kotlin")
    sourceSets.getByName("androidTest").kotlin.srcDir("src/sharedTest/kotlin")
}

dependencies {
    // api 而不是 implementation：BarcodeFormat 出现在本模块暴露给上游的签名上
    // （BarcodeRenderRequest.format）。与 core:network:impl 对 core:network:api
    // 用 api 是同一条理由。
    api(project(":core:model"))

    // §4.3 选型表：只引 com.google.zxing:core，**不引 zxing-android-embedded**
    // —— 后者自带一个 CaptureActivity 与整套相机代码，与 §10.1 的
    // CameraX + ML Kit 扫描路线正面冲突。
    implementation(libs.zxing.core)

    // 只为 @StringRes（BarcodeFormatTable 的展示名那一列）。
    implementation(libs.androidx.annotation)

    implementation(libs.kotlinx.coroutines.android)
    implementation(libs.timber)

    // ⚠️ compileOnly，不是 implementation。
    //
    // 这里**只**用 Barcode.FORMAT_* 那十几个 static final int。它们是编译期常量，
    // kotlinc 直接把值内联进字节码，所以运行期根本不需要这个 artifact ——
    // 而它的 POM 会拖 play-services-basement + vision-common 进来。
    //
    // 引常量而不是抄字面量，是为了让「ML Kit 的值」与 AAR 绑定：抄下来的数字会在
    // 某次升版后静默漂移，引常量则每次编译都跟着走，漂移不可能发生。
    // BarcodeFormatTableTest 另外把这十几个值钉成断言，升版时漂了会当场红。
    //
    // T-156 做相机扫描时把它换成 com.google.mlkit:barcode-scanning（bundled 模型，
    // §10.1 明确不要 play-services-mlkit-*），那时才是 implementation。
    compileOnly(libs.mlkit.barcode.scanning.common)

    // ZXing 的 reader 只在这里用：render → decode 往返，覆盖全部 13 种码制（验收标准）。
    // 主源集一个 reader 都不碰，所以这不会给 APK 添任何东西。
    testImplementation(libs.zxing.core)

    // BarcodeFormatTableTest 要逐条断言 Barcode.FORMAT_* 的值。
    // compileOnly 不传递到测试编译路径，所以这里得再写一次 —— 同样只要编译期，
    // 因为那些常量在两边都会被内联。
    testCompileOnly(libs.mlkit.barcode.scanning.common)
}
