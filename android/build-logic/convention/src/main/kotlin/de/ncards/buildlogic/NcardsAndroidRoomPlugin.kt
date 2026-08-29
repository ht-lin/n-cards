package de.ncards.buildlogic

import com.google.devtools.ksp.gradle.KspExtension
import org.gradle.api.Plugin
import org.gradle.api.Project
import org.gradle.kotlin.dsl.configure
import org.gradle.kotlin.dsl.dependencies

/**
 * Room（§4.3）。实际建表、SQLCipher 与 passphrase 供给是 T-009，本插件只把
 * 编译期接线摆好。
 *
 * `room.schemaLocation` 指向模块内的 `schemas/`，且**必须入库**：
 * §13.5 的 expand–contract 迁移规范要求每个迁移能 `up`/`down` 往返，
 * Room 的 `MigrationTestHelper` 正是拿这些 JSON 当基准。没有它们，
 * T-009 之后的每一次 schema 变更都只能靠人眼 review。
 */
class NcardsAndroidRoomPlugin : Plugin<Project> {
    override fun apply(target: Project) {
        with(target) {
            pluginManager.apply("com.google.devtools.ksp")

            extensions.configure<KspExtension> {
                arg("room.schemaLocation", "$projectDir/schemas")
                // 增量处理与 Kotlin 产物：Room 2.6+ 的默认，显式写出来免得被当成可调项。
                arg("room.incremental", "true")
                arg("room.generateKotlin", "true")
            }

            dependencies {
                add("implementation", libs.findLibrary("room-runtime").get())
                add("implementation", libs.findLibrary("room-ktx").get())
                add("ksp", libs.findLibrary("room-compiler").get())
            }
        }
    }
}
