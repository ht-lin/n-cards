package de.ncards.buildlogic

import com.android.build.api.dsl.ApplicationExtension
import org.gradle.api.Plugin
import org.gradle.api.Project
import org.gradle.kotlin.dsl.configure
import org.gradle.kotlin.dsl.dependencies

/** `:app` —— 组装、NavHost、Application、DI 根。全仓库只有一个模块用它。 */
class NcardsAndroidApplicationPlugin : Plugin<Project> {
    override fun apply(target: Project) {
        with(target) {
            pluginManager.apply("com.android.application")
            pluginManager.apply("ncards.module.graph")
            pluginManager.apply("ncards.quality")

            extensions.configure<ApplicationExtension> {
                configureAndroidCommon(this)

                defaultConfig.targetSdk = libs.version("targetSdk").toInt()
                defaultConfig.versionCode = 1
                defaultConfig.versionName = "0.1.0"
                defaultConfig.testInstrumentationRunner = "androidx.test.runner.AndroidJUnitRunner"

                // §11.1：德语是**默认**语言（values/ 即德语），英语在 values-en/。
                // localeFilters 把打包进 APK 的语言收窄到这两种 —— AndroidX 会带进来
                // 几十种语言的资源，不收窄的话 §9.1 的「APK ≤ 20 MB」白白被吃掉一块。
                //
                // ⚠️ AGP 9 里这个属性在 ApplicationAndroidResources 上，且叫
                // localeFilters；AGP 8.7 之前是 defaultConfig.resourceConfigurations。
                androidResources.localeFilters.addAll(listOf("de", "en"))

                buildFeatures.buildConfig = true

                buildTypes.getByName("debug") {
                    applicationIdSuffix = ".debug"
                    isMinifyEnabled = false
                }

                buildTypes.getByName("release") {
                    // §7.3：release 必须开 R8 + 混淆 + 资源压缩。
                    // 在骨架期就打开，而不是等 T-451 —— 一个空 App 上开 R8 是零成本的，
                    // 等 30 个模块都长满代码了再第一次打开，等着的是一堆 keep 规则考古。
                    isMinifyEnabled = true
                    isShrinkResources = true
                    proguardFiles(
                        getDefaultProguardFile("proguard-android-optimize.txt"),
                        file("proguard-rules.pro"),
                    )
                    // 正式签名配置由 T-456（Play 上架）提供。这里不放任何密钥材料 ——
                    // .gitignore 里 *.keystore / *.jks 是一刀切的。
                }

                // Macrobenchmark 要跑在一个「像 release 但可被 profile」的变体上。
                // 现在接线十来行；等 T-453 再往已经长满的变体矩阵里补一个 buildType，
                // 会牵动届时全部 30 个模块的变体解析。
                buildTypes.create("benchmark") {
                    initWith(buildTypes.getByName("release"))
                    signingConfig = signingConfigs.getByName("debug")
                    matchingFallbacks.add("release")
                    isDebuggable = false
                    isMinifyEnabled = true
                    applicationIdSuffix = ".benchmark"
                }

                // app 的 lint 跑一遍就覆盖全部依赖模块，比 30 个模块各跑一遍快得多。
                // CI 因此只需要 `./gradlew :app:lintDebug`（见 .github/workflows/android.yml）。
                lint.checkDependencies = true
            }

            dependencies {
                add("testImplementation", libs.findLibrary("junit-jupiter").get())
                add("testImplementation", libs.findLibrary("mockk").get())
                add("testImplementation", libs.findLibrary("turbine").get())
                add("testRuntimeOnly", libs.findLibrary("junit-platform-launcher").get())
                add("androidTestImplementation", libs.findLibrary("androidx-test-junit").get())
                add("androidTestImplementation", libs.findLibrary("androidx-test-runner").get())
            }
        }
    }
}
