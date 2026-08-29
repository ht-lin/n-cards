package de.ncards.buildlogic

import org.gradle.api.Plugin
import org.gradle.api.Project
import org.gradle.kotlin.dsl.dependencies

/** Hilt（§4.3 的 DI 选型）。KSP 而不是 kapt —— kapt 在 Kotlin 2.x 上已是维护模式。 */
class NcardsAndroidHiltPlugin : Plugin<Project> {
    override fun apply(target: Project) {
        with(target) {
            pluginManager.apply("com.google.devtools.ksp")
            pluginManager.apply("com.google.dagger.hilt.android")

            dependencies {
                add("implementation", libs.findLibrary("hilt-android").get())
                add("ksp", libs.findLibrary("hilt-compiler").get())
            }
        }
    }
}
