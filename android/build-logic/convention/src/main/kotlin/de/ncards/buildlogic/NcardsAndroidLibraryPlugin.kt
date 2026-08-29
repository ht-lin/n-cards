package de.ncards.buildlogic

import com.android.build.api.dsl.LibraryExtension
import org.gradle.api.Plugin
import org.gradle.api.Project
import org.gradle.kotlin.dsl.configure
import org.gradle.kotlin.dsl.dependencies

/**
 * `core:*` 与 `data:*` 的基线（`core:model` 除外 —— 它是纯 Kotlin，走
 * [NcardsJvmLibraryPlugin]）。
 *
 * 一并接线：§13.3 的 ktlint/detekt/Kover，§13.4 的 JUnit5 + MockK + Turbine 单测栈，
 * 以及 §12.3 的模块依赖规则。
 */
class NcardsAndroidLibraryPlugin : Plugin<Project> {
    override fun apply(target: Project) {
        with(target) {
            pluginManager.apply("com.android.library")
            pluginManager.apply("ncards.module.graph")
            pluginManager.apply("ncards.quality")
            pluginManager.apply("org.jetbrains.kotlinx.kover")
            configureKoverThreshold()

            extensions.configure<LibraryExtension> {
                configureAndroidCommon(this)

                defaultConfig.testInstrumentationRunner = "androidx.test.runner.AndroidJUnitRunner"

                // library 模块不需要 BuildConfig（默认已关）。显式写出来，免得有人
                // 为了塞一个常量把它打开，然后 20 个模块各生成一个空类。
                buildFeatures.buildConfig = false
            }

            dependencies {
                add("testImplementation", libs.findLibrary("junit-jupiter").get())
                add("testImplementation", libs.findLibrary("mockk").get())
                add("testImplementation", libs.findLibrary("turbine").get())
                add("testImplementation", libs.findLibrary("kotlinx-coroutines-test").get())
                // Gradle 9 起 JUnit Platform 需要显式的 launcher，否则会报
                // 「TestEngine with ID 'junit-jupiter' failed to discover tests」。
                add("testRuntimeOnly", libs.findLibrary("junit-platform-launcher").get())

                add("androidTestImplementation", libs.findLibrary("androidx-test-junit").get())
                add("androidTestImplementation", libs.findLibrary("androidx-test-runner").get())
            }
        }
    }
}
