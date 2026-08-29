package de.ncards.buildlogic

import com.android.build.api.dsl.CommonExtension
import org.gradle.api.Plugin
import org.gradle.api.Project
import org.gradle.kotlin.dsl.configure
import org.gradle.kotlin.dsl.dependencies

/**
 * Compose 接线（BOM + 编译器插件 + tooling）。
 *
 * ⚠️ 任务书没点名这个插件，它也**不该**被并进 `ncards.android.library`：
 * `core:{model,common,database,datastore,crypto,network,barcode,testing}` 与全部
 * `data:*` 都不含 UI —— 30 个模块里有 20 个不需要 Compose。给它们挂上等于
 * 白付一遍 Compose 编译器开销，且会让「这个模块有没有 UI」这件事从依赖图里看不出来。
 *
 * 需要 Compose 的模块：`app`、全部 `feature:*`、`widget`、
 * 以及 `core:{designsystem,ui}`（§12.3 里这两个就是放 Composable 的地方）。
 */
class NcardsAndroidComposePlugin : Plugin<Project> {
    override fun apply(target: Project) {
        with(target) {
            pluginManager.apply("org.jetbrains.kotlin.plugin.compose")

            // application 与 library 的扩展类型不同，但都是 CommonExtension 的子类型。
            extensions.configure<CommonExtension> {
                buildFeatures.compose = true
            }

            val bom = libs.findLibrary("androidx-compose-bom").get()
            dependencies {
                add("implementation", platform(bom))
                add("implementation", libs.findLibrary("androidx-compose-ui").get())
                add("implementation", libs.findLibrary("androidx-compose-ui-graphics").get())
                add("implementation", libs.findLibrary("androidx-compose-ui-tooling-preview").get())
                add("implementation", libs.findLibrary("androidx-compose-material3").get())

                // ui-tooling 只进 debug：它带着 Layout Inspector 的全部支撑代码，
                // 进 release 既涨包体（§9.1 的 APK ≤ 20 MB）又多一份可被翻的元数据。
                add("debugImplementation", platform(bom))
                add("debugImplementation", libs.findLibrary("androidx-compose-ui-tooling").get())

                add("androidTestImplementation", platform(bom))
                add("androidTestImplementation", libs.findLibrary("androidx-compose-ui-test-junit4").get())
                add("debugImplementation", libs.findLibrary("androidx-compose-ui-test-manifest").get())
            }
        }
    }
}
