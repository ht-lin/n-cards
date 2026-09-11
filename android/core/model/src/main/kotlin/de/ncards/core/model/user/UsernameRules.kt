package de.ncards.core.model.user

import java.util.Locale

/**
 * §3.8 的 username 规则在客户端这一侧的落点。**只有归一化与字符集/长度两件事。**
 *
 * ============================================================================
 * ⚠️ 这里**故意没有**保留词表
 * ============================================================================
 * 服务端那份在 `backend/config/packages/ncards_username.yaml`，而 §17.5 的 Q9
 * 至今是未决项（「Q9 起草完成、待产品确认」）。抄一份进来会得到两个真相源，
 * 而产品定稿那天没有任何东西会提醒我们同步。
 *
 * 不抄的代价是**零**：ADR-0017 决策三把 `422 username_invalid` 明确排除在
 * 「10 次总计」之外 —— 被服务端判为保留词**不消耗**任何次数。
 * 反过来抄错一个词的代价是具体的：客户端误拒一个合法名字，用户没有任何自助出路
 * （username 不可变，且 T-108 的拦截器会挡住注销路径）。
 *
 * 同理，[validate] 只用来 gate「确认」按钮的可点状态，不是第二道强制点。
 * 真正的强制点在服务端的 `Identity\Domain\ValueObject\Username`。
 *
 * ============================================================================
 * ⚠️ [normalize] 里的 `Locale.ROOT` 不能省
 * ============================================================================
 * §3.8 原文：「**不得**用系统 locale —— 土耳其语的 `I→ı` 会破坏匹配」。
 * 无参 `lowercase()` 用的是默认 locale，在土耳其语设备上 `ANNA_B` 会变成
 * `anna_b` 之外的东西，于是用户在自己手机上设的名字和别人搜到的不是同一个。
 *
 * 服务端那边是 `strtolower(trim($raw))`（PHP 8.2 起只映射 ASCII A–Z，
 * 恰好就是 `Locale.ROOT` 的语义）。两侧必须是同一个函数。
 */
object UsernameRules {
    /** §3.8 / §7.5：3–20 个字符。 */
    const val MIN_LENGTH: Int = 3

    /** §3.8 / §7.5：3–20 个字符。 */
    const val MAX_LENGTH: Int = 20

    /** 字符集本身，**不含**长度 —— 长度分开判，好让 UI 说得出是哪一条不满足。 */
    private val ALLOWED_CHARACTERS = Regex("^[a-z0-9_]*$")

    /**
     * `trim` + `toLowerCase(Locale.ROOT)`，与服务端逐字同义（§3.8）。
     *
     * UI 必须把结果**实时显示**出来：用户输 `Anna_B ` 就要当场看到 `anna_b`，
     * 否则他按下的「确认」提交的是一个他没见过的名字，而这个操作不可逆（§16 R13）。
     */
    fun normalize(raw: String): String = raw.trim().lowercase(Locale.ROOT)

    /**
     * 归一化之后还剩什么问题。合规返回 `null`。
     *
     * 顺序是刻意的：先长度后字符集。一个空输入报「太短」比报「含非法字符」有用。
     */
    fun validate(raw: String): UsernameProblem? {
        val normalized = normalize(raw)
        return when {
            normalized.length < MIN_LENGTH -> UsernameProblem.TooShort
            normalized.length > MAX_LENGTH -> UsernameProblem.TooLong
            !ALLOWED_CHARACTERS.matches(normalized) -> UsernameProblem.IllegalCharacters
            else -> null
        }
    }
}

/**
 * [UsernameRules.validate] 的三种结果。
 *
 * 一问题一类型（与 `ApiError` 同一个做法）：UI 的 `when` 漏一条就编译不过，
 * 而「输入时实时显示字符集规则」（§16 R13）要求这三条各有各的德语文案。
 */
sealed interface UsernameProblem {
    /** 少于 [UsernameRules.MIN_LENGTH] 个字符。 */
    data object TooShort : UsernameProblem

    /** 多于 [UsernameRules.MAX_LENGTH] 个字符。 */
    data object TooLong : UsernameProblem

    /** 归一化之后仍有 `[a-z0-9_]` 之外的字符（空格、连字符、变音符号、emoji……）。 */
    data object IllegalCharacters : UsernameProblem
}
