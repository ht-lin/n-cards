package de.ncards.buildlogic

import org.gradle.api.Plugin
import org.gradle.api.Project
import org.gradle.kotlin.dsl.dependencies
import org.gradle.kotlin.dsl.project

/**
 * `feature:*` 的基线：library + Hilt + Compose + 一套每个 feature 都要的接线。
 *
 * 自动接上 `core:{model,common,designsystem,ui}` 与 lifecycle / navigation ——
 * feature 模块的 build.gradle.kts 因此通常只剩 `plugins {}` 与 `namespace`。
 * 这不只是省事：**它让「feature 该依赖什么」变成一个由 convention plugin 回答的问题**，
 * 而不是十个 feature 各自抄一遍、抄着抄着就有人顺手加了 `project(":feature:wallet")`。
 *
 * 那条真加进去了会被 [NcardsModuleGraphPlugin] 当场拦下（§12.3：feature 之间禁止互相依赖）。
 */
class NcardsAndroidFeaturePlugin : Plugin<Project> {
    override fun apply(target: Project) {
        with(target) {
            pluginManager.apply("ncards.android.library")
            pluginManager.apply("ncards.android.hilt")
            pluginManager.apply("ncards.android.compose")

            dependencies {
                add("implementation", project(":core:model"))
                add("implementation", project(":core:common"))
                add("implementation", project(":core:designsystem"))
                add("implementation", project(":core:ui"))

                // §4.3 的分层图：UI 经 collectAsStateWithLifecycle 消费 ViewModel 的
                // StateFlow<UiState>，别的路子（LiveData、直接 collect）都不要用。
                add("implementation", libs.findLibrary("androidx-lifecycle-runtime-compose").get())
                add("implementation", libs.findLibrary("androidx-lifecycle-viewmodel-compose").get())
                add("implementation", libs.findLibrary("androidx-hilt-navigation-compose").get())
                add("implementation", libs.findLibrary("androidx-navigation-compose").get())

                add("testImplementation", project(":core:testing"))
            }
        }
    }
}
