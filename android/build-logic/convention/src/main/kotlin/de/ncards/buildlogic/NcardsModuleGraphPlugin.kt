package de.ncards.buildlogic

import org.gradle.api.Plugin
import org.gradle.api.Project
import org.gradle.api.artifacts.ProjectDependency

/**
 * §4.3 / §12.3 四条模块依赖规则的强制点。规则表本身在 [NcardsModuleGraph]。
 *
 * ⚠️ **每个模块都必须应用本插件，没有例外。** 它做成独立插件而不是塞进
 * `ncards.android.library`，是因为有两类模块不走 library：
 * `:benchmark`（`com.android.test`）与 `:core:model`（纯 Kotlin `ncards.jvm.library`）。
 * 漏掉任何一个，那个模块的依赖就不受任何约束。
 * 覆盖率由 `ModuleGraphTest.testEveryIncludedModuleIsCoveredByARule()` 兜底。
 *
 * 三个落地细节，改动前请先读：
 *
 * 1. **`afterEvaluate` 而不是 `Configuration.withDependencies`。**
 *    后者要等依赖解析才触发，`./gradlew help` 不会红；前者让**任何**任务
 *    （含验收标准点名的 `assembleDebug`）在配置期就失败。
 *
 * 2. **只扫 `isCanBeDeclared` 的 configuration。** 那是开发者真正写
 *    `implementation(project(...))` 的地方。遍历全部 configuration 会过早实体化
 *    AGP 的可解析 configuration，拖慢配置期还可能触发 AGP 警告。
 *
 * 3. **用 `ProjectDependency.path`。** Gradle 9 已移除
 *    `ProjectDependency.getDependencyProject()`，照 AGP 8 时代的教程写会直接编译不过。
 */
class NcardsModuleGraphPlugin : Plugin<Project> {
    override fun apply(target: Project) {
        with(target) {
            // 先验一次：这个模块本身在规则表里有没有对应行。没有的话立刻报，
            // 而不是等到它第一次写依赖时才报。
            checkNotNull(NcardsModuleGraph.ruleKeyFor(path)) {
                "模块 $path 没有被 build-logic 的 ModuleGraph 规则表覆盖。\n" +
                    "  新建模块时要同时在 ModuleGraph.kt 里让它匹配到一行规则，\n" +
                    "  否则它的依赖不受任何约束。"
            }

            afterEvaluate {
                val violations = configurations
                    .filter { it.isCanBeDeclared }
                    .flatMap { configuration ->
                        configuration.dependencies
                            .filterIsInstance<ProjectDependency>()
                            .mapNotNull { dependency ->
                                NcardsModuleGraph.violationOf(path, dependency.path)
                                    ?.let { message -> configuration.name to message }
                            }
                    }
                    // 同一条依赖常常同时出现在多个 configuration 里
                    // （implementation + debugImplementation…），去重后只报一次。
                    .distinctBy { it.second }

                if (violations.isNotEmpty()) {
                    error(
                        violations.joinToString(
                            separator = "\n\n",
                            prefix = "\n",
                        ) { (configurationName, message) -> "[$configurationName] $message" },
                    )
                }
            }
        }
    }
}
