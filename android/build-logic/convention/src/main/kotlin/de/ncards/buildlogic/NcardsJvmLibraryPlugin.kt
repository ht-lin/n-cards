package de.ncards.buildlogic

import org.gradle.api.Plugin
import org.gradle.api.Project
import org.gradle.api.tasks.testing.Test
import org.gradle.kotlin.dsl.dependencies
import org.gradle.kotlin.dsl.withType

/**
 * 纯 Kotlin（JVM）模块。目前只有 `core:model`。
 *
 * ⚠️ 任务书没点名这个插件。它存在是因为 §12.3 对 `core/model` 的注释是
 * 「**纯 Kotlin**：Card, Member, Friend, BarcodeFormat, SyncState」——
 * 做成 `kotlin("jvm")` 模块，就把那句注释变成了**结构性约束**：
 * 它连 `android.*` 都 import 不到，而不是靠自觉。
 *
 * 附带的好处是它的单测跑在裸 JVM 上，不经 AGP 也不需要 Robolectric ——
 * §13.4 里「BarcodeFormat 映射」那类测试是 core:model 的主要测试面，
 * 它们本来也不该需要一个 Android 运行时。
 */
class NcardsJvmLibraryPlugin : Plugin<Project> {
    override fun apply(target: Project) {
        with(target) {
            pluginManager.apply("org.jetbrains.kotlin.jvm")
            pluginManager.apply("ncards.module.graph")
            pluginManager.apply("ncards.quality")
            pluginManager.apply("org.jetbrains.kotlinx.kover")
            configureKoverThreshold()

            configureKotlinJvm()

            val moduleHasTests = hasKotlinTestSources()
            tasks.withType<Test>().configureEach {
                useJUnitPlatform()
                // 与 AndroidCommon 里同一条理由：骨架期 core:model 还没有测试，
                // 出现第一个 src/test/**.kt 时保护自动回来。
                failOnNoDiscoveredTests.set(moduleHasTests)
            }

            dependencies {
                add("testImplementation", libs.findLibrary("junit-jupiter").get())
                add("testImplementation", libs.findLibrary("mockk").get())
                add("testImplementation", libs.findLibrary("turbine").get())
                add("testImplementation", libs.findLibrary("kotlinx-coroutines-test").get())
                add("testRuntimeOnly", libs.findLibrary("junit-platform-launcher").get())
            }
        }
    }
}
