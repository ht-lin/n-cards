package de.ncards.buildlogic

import java.io.File
import kotlin.test.Test
import kotlin.test.assertEquals
import kotlin.test.assertNotNull
import kotlin.test.assertNull
import kotlin.test.assertTrue
import kotlin.test.fail

/**
 * §4.3 / §12.3 四条规则的穷举验证。
 *
 * 与 `tools/module-graph-selftest.sh` 是**两件不同的事**，两个都要有：
 *
 * - 本测试验的是**规则表本身对不对**（毫秒级，穷举正反例）。
 * - 那个脚本验的是**规则真的被接到构建上了**（秒级，注入违规依赖跑真实构建）。
 *
 * 只有前者，规则可能是对的但插件没被应用；只有后者，只能证明被测的那几条被拦下了。
 */
class ModuleGraphTest {
    // ---------------------------------------------------------------- 规则 ①
    // app → feature:* → data:* → core:*

    @Test
    fun `app 可以依赖任何层`() {
        assertAllowed(":app", ":feature:wallet")
        assertAllowed(":app", ":data:card")
        assertAllowed(":app", ":core:designsystem")
        assertAllowed(":app", ":sync")
        assertAllowed(":app", ":widget")
    }

    @Test
    fun `feature 可以依赖 data 与 core`() {
        assertAllowed(":feature:wallet", ":data:card")
        assertAllowed(":feature:wallet", ":core:ui")
        assertAllowed(":feature:onboarding", ":core:model")
    }

    @Test
    fun `data 可以依赖 core`() {
        assertAllowed(":data:card", ":core:database")
        assertAllowed(":data:sync", ":core:network:impl")
    }

    @Test
    fun `没有人可以依赖 app`() {
        assertRejected(":feature:wallet", ":app")
        assertRejected(":data:card", ":app")
        assertRejected(":core:ui", ":app")
        assertRejected(":sync", ":app")
    }

    // ---------------------------------------------------------------- 规则 ②
    // feature:* 之间禁止互相依赖

    @Test
    fun `feature 之间禁止互相依赖`() {
        // T-008 验收标准点名的那一条。
        assertRejected(":feature:wallet", ":feature:scan")
        assertRejected(":feature:carddetail", ":feature:cardedit")
        assertRejected(":feature:friends", ":feature:sharing")
    }

    // ---------------------------------------------------------------- 规则 ③
    // core:* 不得依赖 data:* / feature:*

    @Test
    fun `core 不得依赖 data 或 feature`() {
        assertRejected(":core:ui", ":data:card")
        assertRejected(":core:designsystem", ":feature:wallet")
        assertRejected(":core:network:impl", ":data:auth")
    }

    @Test
    fun `core 之间可以互相依赖`() {
        assertAllowed(":core:ui", ":core:designsystem")
        assertAllowed(":core:network:impl", ":core:network:api")
        assertAllowed(":core:database", ":core:crypto")
    }

    // ---------------------------------------------------------------- 规则 ④
    // sync 可依赖 data:* 与 core:*，不得被 feature:* 直接依赖

    @Test
    fun `sync 可以依赖 data 与 core`() {
        assertAllowed(":sync", ":data:card")
        assertAllowed(":sync", ":core:database")
    }

    @Test
    fun `feature 不得直接依赖 sync`() {
        assertRejected(":feature:wallet", ":sync")
        assertRejected(":feature:settings", ":sync")
    }

    @Test
    fun `widget 与 feature 同级 也不得依赖 sync`() {
        assertAllowed(":widget", ":data:card")
        assertAllowed(":widget", ":core:designsystem")
        assertRejected(":widget", ":sync")
        assertRejected(":widget", ":feature:wallet")
    }

    // ---------------------------------------------------------------- 其他

    @Test
    fun `data 之间默认禁止 且豁免槽位当前为空`() {
        assertRejected(":data:sharing", ":data:card")
        assertRejected(":data:sync", ":data:auth")
    }

    @Test
    fun `benchmark 只能依赖 app`() {
        // targetProjectPath = ":app" 会产生一条真实的 testedApks 依赖，必须允许。
        assertAllowed(":benchmark", ":app")
        // 但 benchmark 是**黑盒**测量（§9.1），不得 import 生产代码 ——
        // 否则会出现「先直接调 Repository 预热再测」这种把基准变成谎言的写法。
        assertRejected(":benchmark", ":core:model")
        assertRejected(":benchmark", ":data:card")
        assertRejected(":benchmark", ":feature:wallet")
    }

    @Test
    fun `未知模块会被明确报出来 而不是静默放行`() {
        val message = NcardsModuleGraph.violationOf(":nope", ":core:model")
        assertNotNull(message, "规则表没覆盖的模块必须报错，不能静默放行")
        assertTrue(":nope" in message)
    }

    @Test
    fun `模块不与自己冲突`() {
        assertNull(NcardsModuleGraph.violationOf(":core:model", ":core:model"))
    }

    @Test
    fun `最长匹配 精确 key 不被前缀 key 抢走`() {
        // ":sync" 是精确 key，":data:" 是前缀 key。":data:sync" 必须命中后者。
        assertEquals(":data:", NcardsModuleGraph.ruleKeyFor(":data:sync"))
        assertEquals(":sync", NcardsModuleGraph.ruleKeyFor(":sync"))
    }

    // ---------------------------------------------------------------- 覆盖率

    /**
     * settings.gradle.kts 里的每个模块都必须被规则表的某一行覆盖。
     *
     * 这条守的是「新建了模块，但忘了往 ModuleGraph.kt 里加规则」——
     * 那个模块的依赖会不受任何约束，而 `deptrac analyse` 式的「当前没违规」
     * 检查永远发现不了。`NcardsModuleGraphPlugin` 在运行期也会对同一件事报错，
     * 但那要等到有人真的去构建那个模块；这条测试在 CI 的第一分钟就红。
     */
    @Test
    fun testEveryIncludedModuleIsCoveredByARule() {
        val settingsPath = System.getProperty("ncards.settingsFile")
            ?: fail("缺少系统属性 ncards.settingsFile，见 build-logic/convention/build.gradle.kts")
        val settings = File(settingsPath)
        assertTrue(settings.isFile, "找不到 $settingsPath")

        val includes = INCLUDE_REGEX.findAll(settings.readText())
            .map { it.groupValues[1] }
            .toList()

        assertTrue(includes.size >= 30, "只从 settings.gradle.kts 里解析出 ${includes.size} 个模块，正则大概率失效了")

        val uncovered = includes.filter { NcardsModuleGraph.ruleKeyFor(it) == null }
        assertTrue(
            uncovered.isEmpty(),
            "这些模块没有被 ModuleGraph 的规则表覆盖：$uncovered\n" +
                "  往 ModuleGraph.ALLOWED 里加一行（规则 key 现有：${NcardsModuleGraph.ruleKeys}）。",
        )
    }

    private fun assertAllowed(from: String, to: String) {
        val violation = NcardsModuleGraph.violationOf(from, to)
        assertNull(violation, "$from -> $to 本应允许，却被拦下了：\n$violation")
    }

    private fun assertRejected(from: String, to: String) {
        assertNotNull(
            NcardsModuleGraph.violationOf(from, to),
            "$from -> $to 本应被拦下，规则表却放行了 —— §12.3 的规则被放宽了？",
        )
    }

    private companion object {
        val INCLUDE_REGEX = Regex("""^\s*include\("(:[^"]+)"\)""", RegexOption.MULTILINE)
    }
}
