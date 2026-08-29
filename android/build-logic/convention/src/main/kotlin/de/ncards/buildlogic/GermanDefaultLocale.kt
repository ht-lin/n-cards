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
import org.gradle.api.tasks.TaskAction
import org.gradle.kotlin.dsl.named
import org.gradle.kotlin.dsl.register
import java.io.File

/**
 * T-008 验收标准第三条「德语为默认资源目录」的可执行断言。
 *
 * §11.1 原文：「默认资源目录放德语，因为主要市场是德国，且避免『英语兜底』在德语环境下漏翻」。
 * 也就是 `values/` = 德语、`values-en/` = 英语，**不存在 `values-de/`**。
 *
 * 这条为什么需要一个任务守着：它是全项目最反直觉、也最容易被「顺手修正」的约定。
 * 任何一个习惯了英语默认的人看到 `values/` 里是德语，第一反应都是把它挪到 `values-de/`
 * 再把英语搬进 `values/`。那一刻起，德语环境下每一条漏翻的文案都会静默显示英语 ——
 * 而 `MissingTranslation` 只查「默认语言有、翻译没有」，查不出这个方向的错。
 * lint 帮不上忙，所以要有这个任务。
 */
abstract class CheckGermanIsDefaultLocaleTask : DefaultTask() {
    @get:InputFiles
    @get:PathSensitive(PathSensitivity.RELATIVE)
    abstract val resourceDirectories: ConfigurableFileCollection

    @get:Internal
    abstract val modulePath: Property<String>

    @TaskAction
    fun check() {
        val problems = mutableListOf<String>()

        resourceDirectories.files.filter(File::isDirectory).forEach { resDir ->
            val germanQualified = resDir.resolve("values-de")
            if (germanQualified.isDirectory) {
                problems += buildString {
                    appendLine("发现 ${germanQualified.relativeTo(resDir.parentFile.parentFile.parentFile)}。")
                    appendLine("  §11.1：德语是**默认**语言，必须放在 values/ 里，不是 values-de/。")
                    appendLine("  建了 values-de/ 就意味着 values/ 变成了英语兜底 —— 德语环境下")
                    append("  每一条漏翻都会静默显示英语，而 MissingTranslation 查不出这个方向。")
                }
            }

            val defaultStrings = resDir.resolve("values/strings.xml")
            if (defaultStrings.isFile) {
                val text = defaultStrings.readText()
                if (!text.contains("tools:locale=\"de\"")) {
                    problems += buildString {
                        appendLine("${defaultStrings.name} 缺少 tools:locale=\"de\"（在 <resources> 根标签上）。")
                        appendLine("  不写的话 Android Studio 与 lint 会按英语对这些德语文案做拼写检查，")
                        append("  产出一堆假告警，然后所有人开始忽略这一类告警。")
                    }
                }
            }
        }

        if (problems.isNotEmpty()) {
            throw GradleException(
                problems.joinToString(
                    separator = "\n\n",
                    prefix = "${modulePath.get()} 的默认语言约定被破坏（§11.1）：\n\n",
                ),
            )
        }
    }
}

/**
 * 给一个 Android 模块挂上 [CheckGermanIsDefaultLocaleTask] 并接进 `check`。
 *
 * 逐模块注册（而不是在根项目扫全仓库）是为了让它与 Isolated Projects 兼容，
 * 也让失败信息直接指向出问题的模块。从根跑
 * `./gradlew checkGermanIsDefaultLocale` 依然会覆盖全部模块。
 */
internal fun Project.registerGermanDefaultLocaleCheck() {
    val resourceDirs: List<File> = layout.projectDirectory.dir("src").asFile
        .takeIf(File::isDirectory)
        ?.listFiles()
        ?.filter(File::isDirectory)
        ?.map { sourceSet -> sourceSet.resolve("res") }
        .orEmpty()

    val modulePathValue = path

    val checkTask = tasks.register<CheckGermanIsDefaultLocaleTask>("checkGermanIsDefaultLocale") {
        group = "verification"
        description = "断言德语是默认资源目录（§11.1 / T-008 验收标准）"
        modulePath.set(modulePathValue)
        resourceDirectories.setFrom(resourceDirs)
    }

    tasks.named("check") {
        dependsOn(checkTask)
    }
}
