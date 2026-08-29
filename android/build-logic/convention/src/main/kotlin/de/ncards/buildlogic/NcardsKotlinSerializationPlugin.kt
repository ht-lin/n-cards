package de.ncards.buildlogic

import org.gradle.api.Plugin
import org.gradle.api.Project
import org.gradle.kotlin.dsl.dependencies

/**
 * kotlinx.serialization（§4.3 的网络序列化选型）。
 *
 * ⚠️ §3.10 / §13.6 的硬要求：所有 `Json` 实例必须 `ignoreUnknownKeys = true`。
 * 离线优先意味着旧客户端长期存在，服务端加一个响应字段就撞崩老版本是不可接受的。
 * 那条配置的落点在 `core:network:impl`（T-010），本插件只负责把依赖与编译器插件接上。
 */
class NcardsKotlinSerializationPlugin : Plugin<Project> {
    override fun apply(target: Project) {
        with(target) {
            pluginManager.apply("org.jetbrains.kotlin.plugin.serialization")

            dependencies {
                add("implementation", libs.findLibrary("kotlinx-serialization-json").get())
            }
        }
    }
}
