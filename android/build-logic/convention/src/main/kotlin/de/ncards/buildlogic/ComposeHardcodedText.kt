package de.ncards.buildlogic

import org.gradle.api.DefaultTask
import org.gradle.api.GradleException
import org.gradle.api.Project
import org.gradle.api.file.ConfigurableFileCollection
import org.gradle.api.provider.Property
import org.gradle.api.tasks.InputFiles
import org.gradle.api.tasks.Internal
import org.gradle.api.tasks.PathSensitive
import org.gradle.api.tasks.PathSensitivity
import org.gradle.api.tasks.SkipWhenEmpty
import org.gradle.api.tasks.TaskAction
import org.gradle.kotlin.dsl.named
import org.gradle.kotlin.dsl.register
import java.io.File

/**
 * §11.1 的「所有用户可见字符串必须在 `strings.xml`」在 **Compose 代码**里的强制点。
 *
 * ⚠️ **为什么需要它：Android Lint 的 `HardcodedText` 对本项目是空转的。**
 *
 * §13.3 要求把 `HardcodedText` 提升为 error，`android/lint.xml` 也照做了。但那条规则
 * 检查的是 **XML 布局属性**（`android:text="Guten Tag"`），而 §4.3 的技术选型是
 * 「全 Compose，**无 XML 布局**」—— 于是它一次都不会触发。
 *
 * 这不是推测：T-008 落地时按验收标准做了反向验证 —— 往 `MainActivity` 里塞一个
 * `Text("hardcoded reverse check")` 再跑 `:app:lintDebug`，**结果是 BUILD SUCCESSFUL**。
 * 一条永远不会响的门禁比没有门禁更糟，因为它让人以为这件事有人管着。
 *
 * `lint.xml` 里那三条**保留**（Glance widget 与将来可能出现的 RemoteViews 仍是 XML，
 * §13.3 也点名要求），本任务是补上 Compose 那一半。
 *
 * ## 检查什么
 *
 * `src/main` 下的 Kotlin 源码里，这三种形态的字符串字面量：
 *
 * ```kotlin
 * Text("Guten Tag")                    // 位置参数
 * Text(text = "Guten Tag")             // 具名参数
 * Icon(contentDescription = "Neu")     // 无障碍文案同样要翻译（§11.2）
 * ```
 *
 * 正确写法一律是 `stringResource(R.string.…)`；装饰性图标传
 * `contentDescription = null`（本检查放行 `null`）。
 *
 * ## 已知的不精确
 *
 * 这是**文本级**检查，不做类型解析。它会漏掉自定义组件里换了参数名的情况，
 * 也可能误报非 UI 场景的 `text = "..."`。取舍是刻意的：一个 200 行的正则检查
 * 现在就能跑，而一个精确的自定义 Lint Detector 需要单独的模块、单独的测试与
 * 一份 AGP Lint API 的版本耦合 —— 那是 §11.2 打磨期（T-452）该做的投资，不是骨架期。
 * 误报时用 `stringResource` 改写，而不是往这里加豁免。
 */
abstract class CheckComposeHardcodedTextTask : DefaultTask() {
    @get:InputFiles
    @get:SkipWhenEmpty
    @get:PathSensitive(PathSensitivity.RELATIVE)
    abstract val sources: ConfigurableFileCollection

    @get:Internal
    abstract val modulePath: Property<String>

    @TaskAction
    fun check() {
        val findings = mutableListOf<String>()

        sources.files.filter { it.isFile && it.extension == "kt" }.forEach { file ->
            file.readLines().forEachIndexed { index, rawLine ->
                val line = rawLine.trim()
                if (line.startsWith("//") || line.startsWith("*")) return@forEachIndexed

                PATTERNS.forEach { (pattern, hint) ->
                    if (pattern.containsMatchIn(rawLine)) {
                        findings += "${file.path}:${index + 1}  $line\n      → $hint"
                    }
                }
            }
        }

        if (findings.isNotEmpty()) {
            throw GradleException(
                buildString {
                    appendLine("${modulePath.get()} 里有硬编码的用户可见文案（§11.1）：")
                    appendLine()
                    findings.forEach { appendLine("  $it") }
                    appendLine()
                    appendLine("  所有用户可见字符串必须在 strings.xml —— 德语进 values/，英语进 values-en/。")
                    appendLine("  硬编码的文案永远不会被翻译，而且 MissingTranslation 也查不到它。")
                    append("  装饰性图标用 contentDescription = null（本检查放行 null）。")
                },
            )
        }
    }

    private companion object {
        val PATTERNS = listOf(
            Regex("""\bText\s*\(\s*"""") to "改用 Text(stringResource(R.string.…))",
            Regex("""\btext\s*=\s*"""") to "改用 text = stringResource(R.string.…)",
            Regex("""\bcontentDescription\s*=\s*"""") to
                "改用 contentDescription = stringResource(R.string.…)；装饰性图标传 null",
        )
    }
}

/**
 * 给一个 Android 模块挂上 [CheckComposeHardcodedTextTask] 并接进 `check`。
 *
 * 只扫 `src/main` —— 测试代码里的字面量是夹具，不是用户可见文案。
 */
internal fun Project.registerComposeHardcodedTextCheck() {
    val mainKotlin = projectDir.resolve("src/main")
        .walkTopDown()
        .filter { file: File -> file.isFile && file.extension == "kt" }
        .toList()

    val modulePathValue = path

    val checkTask = tasks.register<CheckComposeHardcodedTextTask>("checkComposeHardcodedText") {
        group = "verification"
        description = "Compose 代码里不得有硬编码的用户可见文案（§11.1）"
        modulePath.set(modulePathValue)
        sources.setFrom(mainKotlin)
    }

    tasks.named("check") {
        dependsOn(checkTask)
    }
}
