package de.ncards.core.model.user

import org.junit.jupiter.api.Assertions.assertEquals
import org.junit.jupiter.api.Assertions.assertNull
import org.junit.jupiter.api.DisplayName
import org.junit.jupiter.api.Test
import org.junit.jupiter.api.parallel.Execution
import org.junit.jupiter.api.parallel.ExecutionMode
import java.util.Locale

@DisplayName("UsernameRules")
@Execution(ExecutionMode.SAME_THREAD)
class UsernameRulesTest {
    @Test
    @DisplayName("归一化：trim + 小写，与服务端 strtolower(trim(...)) 同义")
    fun normalizesLikeTheServer() {
        assertEquals("anna_b", UsernameRules.normalize("Anna_B "))
        assertEquals("anna_b", UsernameRules.normalize("  ANNA_B"))
        assertEquals("anna_b", UsernameRules.normalize("anna_b"))
    }

    /**
     * ⚠️ §3.8 点名的那个坑。
     *
     * 土耳其语 locale 下，无参 `lowercase()` 把 `I` 映成 `ı`（U+0131）——
     * 于是 `ANNA_I` 在土耳其人的手机上归一化成 `anna_ı`，而服务端算的是 `anna_i`：
     * 同一个人用同一个名字，两边永远对不上。
     *
     * 本用例把默认 locale 真的切到土耳其语再跑，所以 `Locale.ROOT` 被谁顺手删掉
     * 都会当场变红。`@Execution(SAME_THREAD)` 是因为默认 locale 是进程级状态。
     */
    @Test
    @DisplayName("土耳其语 locale 下 I 仍然映成 i（Locale.ROOT 不可省）")
    fun normalizationIsLocaleIndependent() {
        val original = Locale.getDefault()
        try {
            Locale.setDefault(Locale.forLanguageTag("tr-TR"))

            assertEquals("anna_i", UsernameRules.normalize("ANNA_I"))
        } finally {
            Locale.setDefault(original)
        }
    }

    @Test
    @DisplayName("合规的名字没有问题")
    fun acceptsValidNames() {
        assertNull(UsernameRules.validate("abc"))
        assertNull(UsernameRules.validate("anna_b"))
        assertNull(UsernameRules.validate("a_1"))
        assertNull(UsernameRules.validate("_".repeat(UsernameRules.MAX_LENGTH)))
        // 归一化之后才判，所以这两个也是合规的。
        assertNull(UsernameRules.validate("  Anna_B  "))
        assertNull(UsernameRules.validate("ABC"))
    }

    @Test
    @DisplayName("长度边界：2 太短、3 可以、20 可以、21 太长")
    fun enforcesLengthBounds() {
        assertEquals(UsernameProblem.TooShort, UsernameRules.validate("ab"))
        assertNull(UsernameRules.validate("abc"))
        assertNull(UsernameRules.validate("a".repeat(UsernameRules.MAX_LENGTH)))
        assertEquals(UsernameProblem.TooLong, UsernameRules.validate("a".repeat(UsernameRules.MAX_LENGTH + 1)))
    }

    @Test
    @DisplayName("空输入报「太短」而不是「非法字符」")
    fun emptyInputIsTooShort() {
        assertEquals(UsernameProblem.TooShort, UsernameRules.validate(""))
        assertEquals(UsernameProblem.TooShort, UsernameRules.validate("   "))
    }

    /**
     * §3.8 的字符集理由：排除同形异义字（西里尔 `а` vs 拉丁 `a`）、空格与 emoji，
     * 避免「看起来一样但不是同一个人」的社工攻击。
     */
    @Test
    @DisplayName("字符集：空格、连字符、变音符号、西里尔同形字、emoji 全部被拒")
    fun rejectsCharactersOutsideTheAllowedSet() {
        listOf(
            "anna b",
            "anna-b",
            "anna.b",
            "annä_b",
            "аnna_b", // 首字母是西里尔的 U+0430
            "anna_😀",
            "n-cards",
        ).forEach { candidate ->
            assertEquals(
                UsernameProblem.IllegalCharacters,
                UsernameRules.validate(candidate),
                "「$candidate」本该因字符集被拒",
            )
        }
    }

    /**
     * ⚠️ 保留词**故意**不在客户端判（见 [UsernameRules] 的类注释）。
     *
     * 这条用例存在是为了让「以后有人顺手把 12 个词抄进来」这件事当场变红 ——
     * 抄进来的那一刻，Q9（§17.5，产品未定案）就有了第二个真相源，
     * 而它与服务端漂移的代价是用户被永久挡在 onboarding 之外。
     * 服务端拒保留词是 `422 username_invalid`，ADR-0017 已把它排除在 10 次计数之外，
     * 所以放过去不花任何东西。
     */
    @Test
    @DisplayName("保留词在客户端一律放行，交给服务端判")
    fun doesNotDuplicateTheReservedWordList() {
        // backend/config/packages/ncards_username.yaml 的 12 个词，逐字抄在这里
        // **只是为了断言它们被放行** —— 生产代码里没有这张表，这是刻意的。
        listOf(
            "admin",
            "support",
            "ncards",
            "help",
            "root",
            "system",
            "info",
            "kontakt",
            "datenschutz",
            "impressum",
            "hilfe",
            "konto",
        ).forEach { reserved ->
            assertNull(UsernameRules.validate(reserved), "「$reserved」不该在客户端被拒")
        }
    }
}
