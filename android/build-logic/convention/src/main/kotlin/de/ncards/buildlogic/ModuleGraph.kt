package de.ncards.buildlogic

/**
 * §4.3 / §12.3 的四条模块依赖规则，落成一张可执行的规则表。
 *
 * ```
 * app → feature:* → data:* → core:*
 * feature:* 之间禁止互相依赖（跨 feature 导航经 app 的 NavHost + core:model 的路由定义）
 * core:* 不得依赖 data:* / feature:*
 * sync 可依赖 data:* 与 core:*，不得被 feature:* 直接依赖（feature 只经 Repository）
 * ```
 *
 * 规格书 §12.3 原文写的是「违反用 Gradle 的模块可见性 + 自定义 lint 规则检查」。
 * 两条都不成立：
 *
 * - Gradle **没有**模块可见性机制。`api` / `implementation` 管的是传递依赖要不要
 *   暴露给下游，挡不住任何人直接写 `implementation(project(":feature:scan"))`。
 * - 自定义 lint 规则要等到 `lintDebug` 任务才报错，而 T-008 的验收标准原文是
 *   「故意在两个 feature 间加依赖会**构建失败**」。
 *
 * 所以落成配置期检查：[NcardsModuleGraph.violationOf] 由 `ncards.module.graph`
 * 插件在 `afterEvaluate` 里对每条 project 依赖调用，违规直接 `error(...)`。
 * 效果是 `./gradlew help` 就红，比跑一遍 lint 快两个数量级。
 *
 * ⚠️ 这张表是**唯一**的真相源。要放宽某条规则，改这里并在 PR 里说明理由 ——
 * 不要在某个模块的 build.gradle.kts 里想办法绕过去。
 * [ModuleGraphTest] 会穷举验证这张表，并断言 settings.gradle.kts 里的每个模块
 * 都被某一行覆盖。
 */
object NcardsModuleGraph {
    /**
     * 每行 =「这类模块允许依赖哪些模块」。
     *
     * key 有两种形态：以 `:` 结尾的是**前缀**（`:core:` 匹配 `:core:network:impl`），
     * 不以 `:` 结尾的是**精确路径**（`:sync` 只匹配它自己）。
     * 匹配时取最长的那个 key，因此两种形态可以共存而不歧义。
     */
    private val ALLOWED: Map<String, List<String>> = mapOf(
        // 组装层：什么都能依赖。NavHost 在这里，跨 feature 导航因此不需要 feature 互相引用。
        ":app" to listOf(":feature:", ":data:", ":core:", ":sync", ":widget"),

        // feature 之间禁止互相依赖 —— 这条是本表存在的首要理由。
        // 也不得依赖 :sync：同步只经 Repository 暴露给 UI（§12.3）。
        ":feature:" to listOf(":core:", ":data:"),

        // widget 与 feature 同级：它也是 UI，也只经 Repository 拿数据。
        // §12.3 的规则表没有单独点名 widget，这一行是按同样的理由推出来的 ——
        // Glance widget 若能直接摸 :sync，「feature 不得依赖 sync」就成了一句空话
        // （绕一下就到了）。T-254 实现 widget 时若发现确实需要触发同步，
        // 正确的出路是在 data:sync 的 Repository 上开一个方法，不是改这一行。
        ":widget" to listOf(":core:", ":data:"),

        // data 只往下看 core。data 之间默认禁止，豁免见 [DATA_SIBLING_EXEMPTIONS]。
        ":data:" to listOf(":core:"),

        // 同步引擎可以依赖 data 与 core；没有人能依赖它，除了 :app
        // （在 DI 根里绑定 Worker 与 FcmService）。
        ":sync" to listOf(":core:", ":data:"),

        // core 只能依赖 core。这条挡的是最隐蔽的一种腐化：
        // core:ui 为了「就用一下那个 Repository」而依赖 data:card。
        ":core:" to listOf(":core:"),

        // Macrobenchmark 的被测应用。
        //
        // ⚠️ `targetProjectPath = ":app"` **确实**会产生一条真实的 project 依赖
        // （AGP 把它放进名为 `testedApks` 的 configuration），所以这一行不能是空表 ——
        // 起初以为它只是一个字符串指针，本插件当场把 `:benchmark -> :app` 拦了下来。
        // 允许的只有 `:app` 这一个：benchmark 模块不该直接引用任何 feature 或 data，
        // 它是**黑盒**测量启动与交互耗时的（§9.1），一旦能 import 生产代码，
        // 就会有人写出「直接调 Repository 预热一下再测」这种把基准变成谎言的代码。
        ":benchmark" to listOf(":app"),
    )

    /**
     * data 模块之间的显式豁免槽位。**目前为空。**
     *
     * §12.3 的四条规则没有直接规定 data 模块之间能不能互相依赖，这里选择「默认禁止 +
     * 显式开口」，与 `backend/deptrac.yaml` 给 `Sync\Infrastructure\Doctrine\SyncReadModel`
     * 留豁免槽位是同一个做法：跨模块引用不该靠「规则没提到所以可以」溜进来，
     * 而该是一条有名字、有理由、看得见的记录。
     *
     * 真要开口时，往这里加一条 `":data:sharing" to ":data:card"` 并在旁边写清为什么
     * 不能经 core:model 的共享类型解决。
     */
    private val DATA_SIBLING_EXEMPTIONS: Set<Pair<String, String>> = emptySet()

    /** 规则表里所有 key，供 [ModuleGraphTest] 的覆盖率断言使用。 */
    val ruleKeys: Set<String> get() = ALLOWED.keys

    /**
     * 找出 [path] 命中的规则行。取最长匹配，未覆盖返回 null。
     */
    fun ruleKeyFor(path: String): String? =
        ALLOWED.keys
            .filter { key -> if (key.endsWith(":")) path.startsWith(key) else path == key }
            .maxByOrNull { it.length }

    /**
     * [from] 依赖 [to] 是否违规。合规返回 null，违规返回给人看的错误文案。
     *
     * 两个入参都是 Gradle 的项目路径（`:core:network:impl` 这种形态）。
     */
    fun violationOf(from: String, to: String): String? {
        if (from == to) return null

        val fromKey = ruleKeyFor(from)
            ?: return uncoveredModuleMessage(from)
        if (ruleKeyFor(to) == null) return uncoveredModuleMessage(to)

        val allowed = ALLOWED.getValue(fromKey)
        val permitted = allowed.any { prefix ->
            if (prefix.endsWith(":")) to.startsWith(prefix) else to == prefix
        }
        if (permitted) return null

        if (fromKey == ":data:" && to.startsWith(":data:") && (from to to) in DATA_SIBLING_EXEMPTIONS) {
            return null
        }

        return violationMessage(from, to, fromKey, allowed)
    }

    private fun violationMessage(
        from: String,
        to: String,
        fromKey: String,
        allowed: List<String>,
    ): String = buildString {
        appendLine("模块依赖规则违规（§4.3 / §12.3）：$from  ->  $to")
        appendLine()
        appendLine("  规则表里 `$fromKey` 这一行允许的依赖：" + if (allowed.isEmpty()) "（无）" else allowed.joinToString())
        appendLine("  而 $to 不在其中。")
        appendLine()
        appendLine("  §12.3 的四条规则：")
        appendLine("    app → feature:* → data:* → core:*")
        appendLine("    feature:* 之间禁止互相依赖（跨 feature 导航经 app 的 NavHost + core:model 的路由定义）")
        appendLine("    core:* 不得依赖 data:* / feature:*")
        appendLine("    sync 可依赖 data:* 与 core:*，不得被 feature:* 直接依赖（feature 只经 Repository）")
        appendLine()
        appendLine("  两个 feature 要共享代码：把共享的部分下沉到 core:ui 或 core:model。")
        appendLine("  一个 feature 要另一个 feature 的数据：经 data:* 的 Repository，不要横向依赖。")
        appendLine("  跨 feature 导航：在 core:model 里定义路由，由 app 的 NavHost 接线。")
        appendLine()
        append("  确实需要放宽？改 build-logic 的 ModuleGraph.kt 并在 PR 里说明理由 —— ")
        append("那是这条规则唯一的真相源。")
    }

    private fun uncoveredModuleMessage(path: String): String = buildString {
        appendLine("模块 $path 没有被 ModuleGraph 的规则表覆盖。")
        appendLine()
        appendLine("  新建模块时要同时在 build-logic 的 ModuleGraph.kt 里让它匹配到一行规则，")
        appendLine("  否则它的依赖不受任何约束 —— 而这是「依赖规则静默失效」最现实的一条路径。")
        append("  ModuleGraphTest.testEveryIncludedModuleIsCoveredByARule() 也会因此变红。")
    }
}
