package de.ncards.buildlogic

import com.android.build.api.dsl.CommonExtension
import org.gradle.api.JavaVersion
import org.gradle.api.Project
import org.gradle.api.artifacts.VersionCatalog
import org.gradle.api.artifacts.VersionCatalogsExtension
import org.gradle.kotlin.dsl.configure
import org.gradle.kotlin.dsl.getByType
import org.jetbrains.kotlin.gradle.dsl.JvmTarget
import org.jetbrains.kotlin.gradle.dsl.KotlinJvmProjectExtension

/**
 * §12.3 的「唯一依赖声明处」对 convention plugin 同样生效：
 * build-logic/settings.gradle.kts 里 `from(files("../gradle/libs.versions.toml"))`，
 * 所以这里拿到的就是主构建那一份，没有第二个真相源。
 */
internal val Project.libs: VersionCatalog
    get() = extensions.getByType<VersionCatalogsExtension>().named("libs")

internal fun VersionCatalog.version(name: String): String =
    findVersion(name).orElseThrow {
        IllegalStateException("libs.versions.toml 里缺少 versions.$name")
    }.requiredVersion

/**
 * 全部 Android 模块（application / library / test）共享的配置。
 *
 * 两个写法上的坑，改动前请先读：
 *
 * 1. **AGP 9 起 [CommonExtension] 不再带类型参数。** AGP 8 时代的
 *    `CommonExtension<*, *, *, *, *, *>` 在这里编译不过，而网上多数 convention plugin
 *    教程（含 Now in Android 的历史版本）都还是旧形态。
 *
 * 2. **不要写 `extension.apply { ... }`。** [CommonExtension] 继承 `ExtensionAware`，
 *    那个 `apply` 会绑到 Gradle 的重载而不是 Kotlin 的作用域函数，于是块内几乎所有
 *    成员都变成 "Unresolved reference"，而报错信息完全不指向真正的原因。
 *    下面一律用显式成员访问，啰嗦但不会再踩。
 */
internal fun Project.configureAndroidCommon(extension: CommonExtension) {
    extension.compileSdk = libs.version("compileSdk").toInt()
    extension.compileSdkMinor = libs.version("compileSdkMinor").toInt()
    extension.defaultConfig.minSdk = libs.version("minSdk").toInt()

    extension.compileOptions.sourceCompatibility = JavaVersion.VERSION_17
    extension.compileOptions.targetCompatibility = JavaVersion.VERSION_17
    // minSdk 26 已经有 java.time 与 try-with-resources，不需要 desugaring。
    // §11.1 要求用 java.time 的 DateTimeFormatter 做本地化日期，这一条是前提。
    extension.compileOptions.isCoreLibraryDesugaringEnabled = false

    // 规则本身写在版本控制的 android/lint.xml 里（§13.3 要求配置文件入库）。
    extension.lint.lintConfig = rootProject.file("lint.xml")
    extension.lint.abortOnError = true
    // 只把 lint.xml 里显式提级的那几条当 error，不要一刀切 warningsAsErrors ——
    // AGP 每次升级都会带来一批新 warning，一刀切等于让升级 AGP 变成一次
    // 无法预估工作量的重构。要新增哪条，去 lint.xml 里点名。
    extension.lint.warningsAsErrors = false
    extension.lint.htmlReport = true
    extension.lint.xmlReport = true
    extension.lint.sarifReport = false
    extension.lint.checkReleaseBuilds = false

    extension.testOptions.unitTests.isIncludeAndroidResources = true
    // §13.4 的单测栈是 JUnit5 + MockK + Turbine。
    //
    // 这里**不需要** de.mannodermaus.android-junit5 插件：那个插件解决的是
    // **仪器测试**跑 JUnit5，而 §13.4 的仪器测试用的是 AndroidX Test（JUnit4 ——
    // Compose UI Test 与 TestListenableWorkerBuilder 都只支持 JUnit4）。
    // 单测走标准的 useJUnitPlatform() 就够，少一个第三方 Gradle 插件、
    // 少一处跟着 Kotlin 版本走的耦合。
    val moduleHasTests = hasKotlinTestSources()
    extension.testOptions.unitTests.all { test ->
        test.useJUnitPlatform()
        // Gradle 9 起，「有测试源集但一个测试都没发现」会让 Test 任务失败。
        // 那条保护是对的 —— 它抓的是「测试被误配置成一个都没跑」这种静默失效。
        //
        // 但 T-008 交付的 30 个模块里有 28 个是空壳，它们**确实**还没有测试。
        // 所以按模块开关，而不是全局关掉：只要 src/test 下出现第一个 .kt 文件，
        // 这个模块的保护就自动回来了。T-009 起各模块补测试时不需要记得改这里。
        test.failOnNoDiscoveredTests.set(moduleHasTests)
    }

    // §13.3 / §14.3 的仪器测试跑在 Gradle Managed Device 上（api 26 + api 34）。
    // 只在 main 合入流水线里跑 —— 理由见 ManagedDevices.kt 与 main.yml。
    configureManagedDevices(extension)

    extension.packaging.resources.excludes.add("/META-INF/{AL2.0,LGPL2.1}")
    extension.packaging.resources.excludes.add("/META-INF/LICENSE*")

    // T-008 验收标准第三条。挂在每个 Android 模块上，而不是只挂 :app ——
    // 德语文案将来会散在 feature:* 各自的 res 里。
    registerGermanDefaultLocaleCheck()

    // §11.1 在 Compose 代码里的强制点。lint 的 HardcodedText 只查 XML 布局属性，
    // 而 §4.3 的选型是「全 Compose，无 XML 布局」—— 那条规则对本项目是空转的。
    // 详见 ComposeHardcodedText.kt 的类注释（含实测证据）。
    registerComposeHardcodedTextCheck()
}

/**
 * 纯 Kotlin（JVM）模块的编译配置。
 *
 * ⚠️ Android 模块**没有**对应的函数，这是刻意的：**AGP 9 自带 Kotlin 支持**
 * （`enableKotlin`），应用 `org.jetbrains.kotlin.android` 会被它直接拒绝
 * （"The 'org.jetbrains.kotlin.android' plugin is no longer required for Kotlin
 * support since AGP 9.0"）。jvmTarget 由上面的 `compileOptions` 推导 ——
 * 已验证产物是 class file major version 61（Java 17）。
 * 网上 AGP 8 时代的 convention plugin 教程在这一点上全部过时。
 */
internal fun Project.configureKotlinJvm() {
    extensions.configure<KotlinJvmProjectExtension> {
        compilerOptions.jvmTarget.set(JvmTarget.JVM_17)
        jvmToolchain(JVM_TOOLCHAIN_VERSION)
    }
}

private const val JVM_TOOLCHAIN_VERSION = 17

/**
 * 这个模块的 `src/test` 下有没有真的 Kotlin 测试文件。
 *
 * 用来决定要不要开 Gradle 9 的 `failOnNoDiscoveredTests` —— 见
 * [configureAndroidCommon] 与 [NcardsJvmLibraryPlugin] 里的说明。
 */
internal fun Project.hasKotlinTestSources(): Boolean =
    projectDir.resolve("src/test")
        .walkTopDown()
        .any { file -> file.isFile && file.extension == "kt" }
