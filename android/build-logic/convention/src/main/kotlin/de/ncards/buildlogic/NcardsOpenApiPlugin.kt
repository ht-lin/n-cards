package de.ncards.buildlogic

import com.android.build.api.dsl.LibraryExtension
import com.fasterxml.jackson.databind.JsonNode
import com.fasterxml.jackson.databind.ObjectMapper
import com.fasterxml.jackson.databind.node.ObjectNode
import com.fasterxml.jackson.dataformat.yaml.YAMLFactory
import org.gradle.api.DefaultTask
import org.gradle.api.GradleException
import org.gradle.api.Plugin
import org.gradle.api.Project
import org.gradle.api.file.ConfigurableFileCollection
import org.gradle.api.file.DirectoryProperty
import org.gradle.api.file.FileCollection
import org.gradle.api.file.RegularFileProperty
import org.gradle.api.tasks.InputDirectory
import org.gradle.api.tasks.InputFile
import org.gradle.api.tasks.InputFiles
import org.gradle.api.tasks.JavaExec
import org.gradle.api.tasks.OutputDirectory
import org.gradle.api.tasks.OutputFile
import org.gradle.api.tasks.PathSensitive
import org.gradle.api.tasks.PathSensitivity
import org.gradle.api.tasks.TaskAction
import org.gradle.kotlin.dsl.configure
import org.gradle.kotlin.dsl.named
import org.gradle.kotlin.dsl.register
import java.io.File

/**
 * §13.1 第 4 条在构建里的落点：`core:network:api` 由 `openapi-generator` 从
 * `docs/api/openapi.yaml` 生成，**提交入库但禁止手改**，CI 重新生成并 diff。
 *
 * 只给 `:core:network:api` 用。注册四个任务：
 *
 * | 任务 | 谁跑 | 干什么 |
 * |---|---|---|
 * | `prepareOpenApiGeneratorInput` | 下面三个的前置 | 派生一份只给生成器吃的契约（见下） |
 * | `generateApiClient` | 改完契约的人 | 重新生成到提交入库的 `generated/` |
 * | `generateApiClientForVerification` | 上一个的对照组 | 生成到 `build/`，不碰工作区 |
 * | `checkApiClientUpToDate` | CI（挂在 `check` 上） | 比对两者，不一致即失败 |
 *
 * ## 为什么要派生一份生成器输入
 *
 * §13.1 第 5 条要求契约里**所有** object schema 都 `additionalProperties: true`
 * —— 那是**客户端前向兼容**的地基。但 `openapi-generator` 7.25.0 把
 * 「有 properties **又** 有 additionalProperties」的 schema 当成了 Map，后果有两条，
 * 都不是能忍的：
 *
 * 1. 每个模型继承 `kotlin.collections.HashMap<String, kotlin.Any>()()` ——
 *    `()()` 不是合法 Kotlin，15 个模型一个都编译不过。
 * 2. 嵌套模型（`Card` / `ProblemFieldError` / `User` / `Device`）被打上 `@Contextual`，
 *    而它们自己就是 `@Serializable`。轻则 kotlinx 序列化插件在后端阶段崩，
 *    重则运行时报「Serializer not found」。
 *
 * 生成器侧没有开关（`config-help -g kotlin` 与 `--openapi-normalizer` 都没有）。
 *
 * 所以 [PrepareOpenApiGeneratorInputTask] 递归剥掉 `additionalProperties: true`，
 * 把结果写进 `build/` 只喂给生成器。这对生成结果**没有任何语义损失**：客户端的
 * 前向兼容来自 `core:network:impl` 的 `Json { ignoreUnknownKeys = true }`（§3.10），
 * 从来不来自 schema 上的那个布尔。
 *
 * ⚠️ 契约本身**一个字都没动**。Spectral、后端的 `league/openapi-psr7-validator`、
 * 以及「只有一份 `code` 枚举」那三条测试看到的都还是 `docs/api/openapi.yaml` 原件。
 * 这正是 `docs/api/README.md` 结尾给 T-010 预留的那条出路的形态 ——
 * 派生产物只供生成器消费，**不要**反向改写契约。
 *
 * 跨文件 `$ref`（`components/schemas/Problem` → `./schemas/problem-details.schema.json`）
 * **实测能解**，所以派生时保持同样的相对目录结构，`$ref` 原样留着。
 *
 * ## 为什么派生成 JSON 而不是 YAML
 *
 * YAML 的标量类型是猜出来的。把 `responses` 的 `"200"` 这种键 round-trip 一遍，
 * 很容易变成整数 200 —— 而这类问题只会在生成产物里表现为「少了一个响应」，
 * 极难追。JSON 的键永远是字符串，没有这一类歧义。生成器两种都吃。
 *
 * ## 为什么是手写 JavaExec 而不是 `org.openapi.generator` 插件
 *
 * 仓库开着 `org.gradle.configuration-cache=true`（`gradle.properties`），而第三方
 * Gradle 插件的配置缓存兼容性是它自己的事 —— 一旦不兼容，出路只剩「关掉整个仓库的
 * 配置缓存」或「回来手写」。手写这一版的输入输出全部经 Provider 声明，task action
 * 里不碰 `Project`，天然兼容；和 [NcardsModuleGraphPlugin] /
 * [CheckGermanIsDefaultLocaleTask] 也是同一种做法。
 *
 * `openapi-generator-cli` 发布的是 shaded fat jar，POM 里只剩 testng / mockito 两条
 * 测试依赖 —— 所以 `isTransitive = false`。
 *
 * ## 为什么编译**不**自动触发生成
 *
 * 让 `compileKotlin` 依赖 `generateApiClient` 会让「产物提交入库」变成一句空话：
 * 本地每次构建都静默改写工作区，`checkApiClientUpToDate` 在本机永远是绿的，
 * 只有 CI 才发现不一致。缺产物就让编译失败，这是刻意的摩擦。
 */
class NcardsOpenApiPlugin : Plugin<Project> {
    override fun apply(target: Project) {
        with(target) {
            val generatorClasspath = configurations.detachedConfiguration(
                dependencies.create(libs.findLibrary("openapi-generator-cli").get().get()),
            ).apply {
                isTransitive = false
                isCanBeConsumed = false
            }

            // 契约在 Gradle 根（android/）之外 —— 仓库根是 monorepo 顶层。
            val contractDir = layout.settingsDirectory.dir(CONTRACT_DIR)
            val contractFile = contractDir.file("openapi.yaml")
            val contractSchemas = contractDir.dir("schemas")
            val generatorInputDir = layout.buildDirectory.dir(GENERATOR_INPUT_ROOT)

            val prepareInput = tasks.register<PrepareOpenApiGeneratorInputTask>("prepareOpenApiGeneratorInput") {
                group = OPENAPI_GROUP
                description = "派生一份剥掉 additionalProperties 的契约，只给 openapi-generator 吃"
                contract.set(contractFile)
                schemas.setFrom(fileTree(contractSchemas) { include("**/*.json") })
                outputDir.set(generatorInputDir)
            }

            // 生成产物落在 `generated/` 而不是 `src/main/kotlin/` —— 这不是审美选择：
            // ktlint 的排除（NcardsQualityPlugin）、Android Lint 的 <ignore>（lint.xml）
            // 与 .editorconfig 三处都是**按路径含 `/generated/`** 判断的。挪到别处，
            // 生成代码会当场被三道门禁咬住，而没人能改它。
            val generatedDir = layout.projectDirectory.dir(GENERATED_ROOT)
            val verifyDir = layout.buildDirectory.dir(VERIFY_ROOT)

            registerGeneratedSourceDir(generatedDir.asFile)

            val configFile = layout.projectDirectory.file(GENERATOR_CONFIG)
            val templateDir = layout.projectDirectory.dir(TEMPLATE_ROOT)
            val derivedSpec = generatorInputDir.map { dir -> dir.file(DERIVED_SPEC_NAME) }

            tasks.register<JavaExec>("generateApiClient") {
                group = OPENAPI_GROUP
                description = "从 docs/api/openapi.yaml 重新生成 core:network:api（产物提交入库）"
                dependsOn(prepareInput)
                configureGenerator(
                    generatorClasspath,
                    configFile.asFile,
                    templateDir.asFile,
                    derivedSpec.get().asFile,
                    generatedDir.asFile,
                )
                inputs.dir(generatorInputDir).withPathSensitivity(PathSensitivity.RELATIVE)
                inputs.dir(templateDir).withPathSensitivity(PathSensitivity.RELATIVE)
                inputs.file(configFile).withPathSensitivity(PathSensitivity.RELATIVE)
                // ⚠️ **不要**给它声明 `outputs.dir(generatedDir)`。
                //
                // 一旦声明，Gradle 就会发现 compileDebugKotlin / ktlint / detekt / lint
                // 全都在读这个目录却没声明依赖，于是 `Property has implicit dependency`
                // 直接让构建失败。而那条依赖正是本插件**刻意不建立**的（见类注释：
                // 编译自动触发生成会让「产物提交入库」变成一句空话）。
                //
                // 这与 `ktlintFormat` 改源码却不把源码声明成输出是同一种形态：
                // 面向人的动作任务，每次跑就真的跑一次。
                outputs.upToDateWhen { false }
            }

            val generateForVerification = tasks.register<JavaExec>("generateApiClientForVerification") {
                group = OPENAPI_GROUP
                description = "把生成结果放进 build/ 供比对，不碰工作区"
                dependsOn(prepareInput)
                val outDir = verifyDir.get().asFile
                configureGenerator(
                    generatorClasspath,
                    configFile.asFile,
                    templateDir.asFile,
                    derivedSpec.get().asFile,
                    outDir,
                )
                inputs.dir(generatorInputDir).withPathSensitivity(PathSensitivity.RELATIVE)
                inputs.dir(templateDir).withPathSensitivity(PathSensitivity.RELATIVE)
                inputs.file(configFile).withPathSensitivity(PathSensitivity.RELATIVE)
                outputs.dir(verifyDir)
                val ignoreFile = generatedDir.file(GENERATOR_IGNORE).asFile
                doFirst {
                    // 生成器不清空目标目录：契约删掉一个 schema 后，上一次留下的 .kt
                    // 会原地不动，于是「多出来的文件」这一侧永远发现不了删除。
                    outDir.deleteRecursively()
                    // .openapi-generator-ignore 决定了哪些文件**不**生成。对照组不带上
                    // 它，就会多吐一整套 Gradle 脚手架，然后 diff 永远红 ——
                    // 而那种红看起来像「产物过期了」，会把人引到完全错误的方向。
                    outDir.mkdirs()
                    ignoreFile.copyTo(outDir.resolve(GENERATOR_IGNORE), overwrite = true)
                }
            }

            val checkUpToDate = tasks.register<CheckGeneratedApiClientTask>("checkApiClientUpToDate") {
                group = "verification"
                description = "断言 core:network:api 与契约重新生成的结果一致（§13.1 第 4 条）"
                dependsOn(generateForVerification)
                committed.set(generatedDir)
                regenerated.set(verifyDir)
                report.set(layout.buildDirectory.file("reports/openapi/codegen-diff.txt"))
            }

            tasks.named("check") {
                dependsOn(checkUpToDate)
            }
        }
    }

    private companion object {
        const val OPENAPI_GROUP = "openapi"
        const val GENERATED_ROOT = "generated"
        const val VERIFY_ROOT = "openapi-verify"
        const val GENERATOR_INPUT_ROOT = "openapi-input"
        const val DERIVED_SPEC_NAME = "openapi.json"
        const val GENERATOR_CONFIG = "openapi-generator-config.yaml"
        const val GENERATOR_IGNORE = ".openapi-generator-ignore"
        const val TEMPLATE_ROOT = "templates"
        const val CONTRACT_DIR = "../docs/api"
    }
}

private const val GENERATOR_MAIN_CLASS = "org.openapitools.codegen.OpenAPIGenerator"

/**
 * 两个生成任务唯一的差别只有输出目录，其余一律共用 —— 差一个参数，
 * 「重新生成并 diff」就会比较两组不同配置的产物，而那种失败极难看懂。
 *
 * `-i` 覆盖配置文件里的 `inputSpec`：配置文件里写的是**真契约**的路径（那是给人读的，
 * 也是 `npm run lint:api` 的对象），实际喂给生成器的是 build/ 里的派生版。
 */
private fun JavaExec.configureGenerator(
    generatorClasspath: FileCollection,
    configFile: File,
    templateDir: File,
    inputSpec: File,
    outputDir: File,
) {
    classpath = generatorClasspath
    mainClass.set(GENERATOR_MAIN_CLASS)
    args(
        "generate",
        "-c", configFile.absolutePath,
        "-i", inputSpec.absolutePath,
        // -t 指向的是「生成器模板根」，即嵌入 jar 里 kotlin-client/ 的那一层。
        // 只放我们覆写的那两个 .mustache，其余自动回落到 jar 内置的版本。
        "-t", templateDir.resolve("kotlin-client").absolutePath,
        "-o", outputDir.absolutePath,
    )
}

/**
 * `generated/src/main/kotlin` 接进 main 源集。
 *
 * ⚠️ 走 build-logic 里的 `com.android.build.api.dsl.LibraryExtension` 而**不是**
 * build.gradle.kts 里的 `android { sourceSets... }`：后者在 AGP 9 上配置期崩
 * （`DefaultAndroidLibrarySourceSet_Decorated cannot be cast to ...`，
 * 详见 core/database/build.gradle.kts 的注释）。
 */
private fun Project.registerGeneratedSourceDir(generatedDir: File) {
    extensions.configure<LibraryExtension> {
        sourceSets.getByName("main").kotlin.srcDir(generatedDir.resolve("src/main/kotlin"))
    }
}

/**
 * 派生生成器输入：递归剥掉 `additionalProperties: true`，YAML → JSON。
 *
 * 理由见 [NcardsOpenApiPlugin] 的类注释。**只剥 `true`** —— `additionalProperties`
 * 写成 `false` 或一个 schema 时是真的在表达约束，那种要原样留着。
 *
 * 跨文件 `$ref` 是相对路径，所以 `schemas/` 下的文件按同样的相对位置一并派生，
 * `$ref` 本身不改写。
 */
abstract class PrepareOpenApiGeneratorInputTask : DefaultTask() {
    @get:InputFile
    @get:PathSensitive(PathSensitivity.RELATIVE)
    abstract val contract: RegularFileProperty

    @get:InputFiles
    @get:PathSensitive(PathSensitivity.RELATIVE)
    abstract val schemas: ConfigurableFileCollection

    @get:OutputDirectory
    abstract val outputDir: DirectoryProperty

    @TaskAction
    fun prepare() {
        val yaml = ObjectMapper(YAMLFactory())
        val json = ObjectMapper()
        val writer = json.writerWithDefaultPrettyPrinter()

        val target = outputDir.get().asFile
        target.deleteRecursively()
        target.mkdirs()

        val root = yaml.readTree(contract.get().asFile)
        stripPermissiveAdditionalProperties(root)
        target.resolve("openapi.json").writeText(writer.writeValueAsString(root))

        val schemasDir = target.resolve("schemas").apply { mkdirs() }
        schemas.files.forEach { schemaFile ->
            val node = json.readTree(schemaFile)
            stripPermissiveAdditionalProperties(node)
            // 文件名原样保留 —— openapi.yaml 里的 $ref 指的就是这个名字。
            schemasDir.resolve(schemaFile.name).writeText(writer.writeValueAsString(node))
        }
    }

    private fun stripPermissiveAdditionalProperties(node: JsonNode) {
        if (node is ObjectNode) {
            val additional = node.get(ADDITIONAL_PROPERTIES)
            val isPermissive = additional != null && additional.isBoolean && additional.booleanValue()
            // ⚠️ 只在**同时声明了 properties** 时才剥。
            //
            // 「properties + additionalProperties」才是被误判成 Map 的那一类。
            // 而 `Problem.current` / `Problem.debug` 是**真的**自由形态对象
            // （只有 `type: object` + `additionalProperties: true`，没有 properties）——
            // 把它们的 additionalProperties 也剥掉，生成的类型会从
            // `Map<String, Any>` 退化成裸 `Any`，那才是真的丢了信息。
            if (isPermissive && node.has(PROPERTIES)) {
                node.remove(ADDITIONAL_PROPERTIES)
            }
        }
        node.forEach(::stripPermissiveAdditionalProperties)
    }

    private companion object {
        const val ADDITIONAL_PROPERTIES = "additionalProperties"
        const val PROPERTIES = "properties"
    }
}

/**
 * 「改了契约却没重新生成」的检查（T-010 验收标准第 1 条）。
 *
 * 比对的是**两棵目录树**，不是 `git diff` —— 后者把「生成结果变了」和「工作区本来
 * 就脏」混成同一种失败，而且要求生成任务先污染工作区才能检查。
 */
abstract class CheckGeneratedApiClientTask : DefaultTask() {
    @get:InputDirectory
    @get:PathSensitive(PathSensitivity.RELATIVE)
    abstract val committed: DirectoryProperty

    @get:InputDirectory
    @get:PathSensitive(PathSensitivity.RELATIVE)
    abstract val regenerated: DirectoryProperty

    @get:OutputFile
    abstract val report: RegularFileProperty

    @TaskAction
    fun check() {
        val committedRoot = committed.get().asFile
        val regeneratedRoot = regenerated.get().asFile

        val committedFiles = committedRoot.relativeFilePaths()
        val regeneratedFiles = regeneratedRoot.relativeFilePaths()

        val missing = (regeneratedFiles - committedFiles).sorted()
        val stale = (committedFiles - regeneratedFiles).sorted()
        val differing = committedFiles.intersect(regeneratedFiles)
            .filter { relative ->
                val inRepo = committedRoot.resolve(relative).readBytes()
                val fresh = regeneratedRoot.resolve(relative).readBytes()
                !inRepo.contentEquals(fresh)
            }
            .sorted()

        val reportFile = report.get().asFile
        reportFile.parentFile.mkdirs()

        if (missing.isEmpty() && stale.isEmpty() && differing.isEmpty()) {
            reportFile.writeText("生成产物与契约一致（${committedFiles.size} 个文件）。\n")
            return
        }

        val detail = buildString {
            appendLine("core:network:api 与 docs/api/openapi.yaml 重新生成的结果不一致（§13.1 第 4 条）。")
            appendLine()
            if (missing.isNotEmpty()) {
                appendLine("  契约里有、仓库里没有（${missing.size}）：")
                missing.forEach { path -> appendLine("    + $path") }
            }
            if (stale.isNotEmpty()) {
                appendLine("  仓库里有、契约里已经没有（${stale.size}）：")
                stale.forEach { path -> appendLine("    - $path") }
            }
            if (differing.isNotEmpty()) {
                appendLine("  内容不同（${differing.size}）：")
                differing.forEach { path -> appendLine("    ~ $path") }
            }
            appendLine()
            appendLine("  出路只有一条：./gradlew :core:network:api:generateApiClient 然后提交产物。")
            append("  **不要**手改 generated/ 下的任何文件 —— 下一次生成会原样覆盖掉。")
        }

        reportFile.writeText(detail + "\n")
        throw GradleException(detail)
    }
}

private fun File.relativeFilePaths(): Set<String> =
    walkTopDown()
        .filter(File::isFile)
        .map { file -> file.relativeTo(this).invariantSeparatorsPath }
        .toSet()
