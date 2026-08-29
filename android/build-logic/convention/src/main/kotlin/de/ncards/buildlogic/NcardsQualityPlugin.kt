package de.ncards.buildlogic

import io.gitlab.arturbosch.detekt.Detekt
import io.gitlab.arturbosch.detekt.extensions.DetektExtension
import org.gradle.api.Plugin
import org.gradle.api.Project
import org.gradle.kotlin.dsl.configure
import org.gradle.kotlin.dsl.withType
import org.jlleitschuh.gradle.ktlint.KtlintExtension

/**
 * §13.3 的 Android 质量门禁里与源码风格相关的两条：`ktlintCheck` 0、`detekt` 0。
 *
 * ⚠️ 逐模块施加，而不是在根 build.gradle.kts 里 `subprojects { }`。
 * 那两个块会跨项目读写模型，与 Gradle 9.7 起 incubating 的 Isolated Projects 冲突，
 * 而 AGP 9 正在往那个方向推。从根跑 `./gradlew ktlintCheck` 依然覆盖全部模块 ——
 * Gradle 会把不带路径的任务名匹配到所有子项目上。
 */
class NcardsQualityPlugin : Plugin<Project> {
    override fun apply(target: Project) {
        with(target) {
            pluginManager.apply("org.jlleitschuh.gradle.ktlint")
            pluginManager.apply("io.gitlab.arturbosch.detekt")

            val ktlintVersion = libs.version("ktlintCli")
            val detektConfig = rootProject.file("detekt.yml")
            val repoBasePath = rootProject.projectDir.absolutePath

            extensions.configure<KtlintExtension> {
                version.set(ktlintVersion)
                android.set(true)
                // 生成代码不参与风格检查：core:network:api 整个是 openapi-generator
                // 的产物（§13.1 第 3 条：提交入库但禁止手改），Room / Hilt / Compose 的
                // KSP 产物同理。对它们报风格问题只会逼人去改不该改的文件。
                filter {
                    exclude { element -> element.file.path.contains("/generated/") }
                }
            }

            extensions.configure<DetektExtension> {
                config.setFrom(detektConfig)
                buildUponDefaultConfig = true
                parallel = true
                basePath = repoBasePath
            }

            tasks.withType<Detekt>().configureEach {
                // §13.3 原文是「0 weighted issues」。detekt.yml 里 build.maxIssues = 0，
                // 这里只把报告收窄成 CI 读得懂的两种。
                reports.html.required.set(true)
                reports.xml.required.set(true)
                reports.sarif.required.set(false)
                reports.md.required.set(false)
                jvmTarget = "17"
            }
        }
    }
}
