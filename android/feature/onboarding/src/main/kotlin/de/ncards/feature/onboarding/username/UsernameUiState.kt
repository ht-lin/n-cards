package de.ncards.feature.onboarding.username

import de.ncards.core.model.user.UsernameProblem
import de.ncards.core.model.user.UsernameRules

/**
 * username 设定页的状态。
 *
 * [normalized] 与 [localProblem] 是**算出来的**，不是存下来的 ——
 * 存下来就会有「输入变了但预览还是旧的」这一类不可能靠测试穷尽的漂移，
 * 而这一页的结果不可逆（§16 R13）。
 */
data class UsernameUiState(
    /** 输入框里的原文。 */
    val input: String = "",
    val progress: UsernameProgress = UsernameProgress.Idle,
) {
    /**
     * 真正会被提交的字符串。
     *
     * ⚠️ **必须实时显示给用户。** 他输 `Anna_B ` 而提交的是 `anna_b`，
     * 而这个操作设定之后无法修改 —— 让他在按下「确认」之前就看到最终形态，
     * 是 §16 R13「输入时实时显示字符集规则」的一半。
     */
    val normalized: String get() = UsernameRules.normalize(input)

    /**
     * 本地预校验的结果。**只用来 gate 按钮**，不是第二道强制点。
     *
     * ⚠️ 它只判字符集与长度。保留词交给服务端 —— 理由见 [UsernameRules] 的类注释
     * （Q9 未定案 + `username_invalid` 不消耗 10 次计数）。
     */
    val localProblem: UsernameProblem? get() = if (input.isEmpty()) null else UsernameRules.validate(input)

    /** 能不能提交。空输入时按钮是灰的，但不显示错误 —— 还没开始输就报错是噪音。 */
    val canSubmit: Boolean
        get() = input.isNotEmpty() && UsernameRules.validate(input) == null && progress != UsernameProgress.Busy
}

/** 「这一页现在在做什么」。三格，没有布尔。 */
sealed interface UsernameProgress {
    data object Idle : UsernameProgress

    data object Busy : UsernameProgress

    /** 服务端拒了。[reason] 决定显示哪一句，以及**还能不能重试**。 */
    data class Rejected(
        val reason: UsernameRejection,
    ) : UsernameProgress
}

/**
 * 设定 username 失败的全部形状。
 *
 * ⚠️ 契约 `POST /me/username` 自己就带了一张「UI 该做什么」的表，
 * 而 ADR-0017 决策二逐字点名了 Android：对 429 与 422 的处置分别是
 * 「自动退避重试」与「终局错误，展示给用户」，**选错的后果是用户看着一个
 * 永远转圈的按钮而不是一句能理解的话**。所以 [AttemptsExhausted] 与
 * [RateLimited] 必须是两格。
 */
sealed interface UsernameRejection {
    /** `409 username_taken` —— 名字被占了。**消耗**一次 10 次计数。 */
    data object Taken : UsernameRejection

    /**
     * `422 username_invalid` —— 长度 / 字符集 / **保留词**。
     *
     * [problem] 是服务端字段错误能对上本地那三格时的细化；对不上（多半是保留词，
     * 本地根本不判）就是 null，显示一句通用的「这个名字不能用」。
     *
     * ⚠️ 文案**不得**回声是哪个保留词命中了 —— 那等于把黑名单发出去。
     *
     * **不消耗**计数（ADR-0017 决策三）。
     */
    data class Invalid(
        val problem: UsernameProblem?,
    ) : UsernameRejection

    /**
     * `422 limit_exceeded` —— 一生 10 次用完了（§7.5 / ADR-0017）。
     *
     * ⚠️ **终局。** 不许出现「稍后重试」或者一个还能再按的按钮：这个计数
     * 落在 `users.username_attempts` 上，永不恢复。用户唯一的出路是联系支持。
     */
    data object AttemptsExhausted : UsernameRejection

    /** `429` —— 按 [retryAfterSeconds] 退避。与 [AttemptsExhausted] 不是一回事。 */
    data class RateLimited(
        val retryAfterSeconds: Long?,
    ) : UsernameRejection

    /** 连不上。 */
    data object Offline : UsernameRejection

    /** `503` —— 维护窗口或限流器不可判定。 */
    data object ServiceUnavailable : UsernameRejection

    /** 其余。上报 Sentry（T-451）。 */
    data object Unexpected : UsernameRejection
}

/** 一次性事件。 */
sealed interface UsernameEvent {
    /**
     * 注册完成 → 钱包。
     *
     * 两条路都会走到这里：正常设定成功，以及 `409 username_immutable`
     * ——后者说明本机状态过期了（这个账号早就设过名字），重新拉一次
     * `GET /me` 确认之后同样是进钱包。
     */
    data object Completed : UsernameEvent
}
