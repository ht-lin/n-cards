package de.ncards.feature.onboarding

import de.ncards.core.model.settings.AppLanguage
import java.util.UUID

/**
 * 注册流程前半段（语言/隐私说明 → 邮箱 → 6 位码）的全部状态。
 *
 * ============================================================================
 * §10.4 的读法
 * ============================================================================
 * 规约原文是「每个 feature 一个 `UiState` sealed interface（`Loading`/`Content`/
 * `Empty`/`Error`），**禁止**用多个独立布尔标志表达状态」。那条规则真正禁的是
 * 后半句 —— 这里**一个布尔都没有**：「正在做什么」由 sealed 的 [progress] 表达，
 * 「能不能重发」由 [resendInSeconds] 这个数表达，「有没有活跃挑战」由
 * [challengeId] 是不是 null 表达。
 *
 * 前半句的四格（Loading/Content/Empty/Error）是给**列表页**设计的，
 * 而这是一个线性向导：三屏共享同一份输入，用户在它们之间来回走时那份输入必须活着。
 * 把它拆成 `Welcome | Email | Code` 三个互斥的 sealed 分支，
 * 结果是每一屏都要写「如果当前状态不是我这一格怎么办」，而导航切换的那一帧
 * 永远命中那个分支 —— 用一个假问题换来一个真 bug。
 *
 * 真正分格的是 username 设定页：它是独立目的地、独立 ViewModel，
 * 见 [de.ncards.feature.onboarding.username.UsernameUiState]。
 */
data class OnboardingUiState(
    /** 界面与验证码邮件的语言。第一屏选，之后跟着走。 */
    val language: AppLanguage,
    /**
     * 邮箱原文。**不做任何归一化** —— 服务端算的是
     * `HMAC-SHA256(lower(trim(email)), pepper)`，客户端再实现一遍只会多一个
     * 必须与它逐字一致的地方（`DefaultAuthRepository.requestOtp` 的注释同此）。
     */
    val emailInput: String = "",
    /** 用户输入的 6 位码。 */
    val codeInput: String = "",
    /** 当前挑战。null = 还没请求过码，或上一个已经作废。 */
    val challengeId: UUID? = null,
    /**
     * 还要等几秒才能重发。
     *
     * ⚠️ 初值取自 `POST /auth/otp/request` 的 202 响应
     * （`OtpChallenge.resendAfterSeconds`），**不写死 60**。那个数是服务端
     * §7.1 的「重发间隔」，改它不该要求发一版客户端。
     */
    val resendInSeconds: Int = 0,
    val progress: OnboardingProgress = OnboardingProgress.Idle,
) {
    /** 6 位数字（契约 `OtpVerification.code` 的 `^\d{6}$`）。 */
    val codeComplete: Boolean get() = codeInput.length == CODE_LENGTH && codeInput.all(Char::isDigit)

    /**
     * 本地只做「看起来像个邮箱吗」这一档。
     *
     * ⚠️ **刻意不用严格的 RFC 5322 正则。** 这里判严了的后果是把一个真实可用的
     * 地址挡在门外，而用户没有任何出路（他没有第二个注册入口）；判松了的代价
     * 只是白发一次请求 —— 服务端会回 `400 validation_failed`，而那条路径是有出口的。
     */
    val emailLooksValid: Boolean
        get() =
            emailInput.trim().let { candidate ->
                candidate.length in 3..MAX_EMAIL_LENGTH &&
                    candidate.count { it == '@' } == 1 &&
                    !candidate.startsWith('@') &&
                    !candidate.endsWith('@') &&
                    !candidate.contains(' ')
            }

    companion object {
        /** §7.1：6 位数字。 */
        const val CODE_LENGTH = 6

        /** 契约 `OtpRequest.email` 的 `maxLength`。 */
        const val MAX_EMAIL_LENGTH = 254
    }
}

/**
 * 「这一屏现在在做什么」。三格，没有布尔。
 */
sealed interface OnboardingProgress {
    /** 等着用户操作。 */
    data object Idle : OnboardingProgress

    /** 有一个请求在飞 —— 按钮该禁用并显示进度。 */
    data object Busy : OnboardingProgress

    /** 上一次操作失败了。[error] 决定显示哪一句本地化文案。 */
    data class Failed(
        val error: OnboardingError,
    ) : OnboardingProgress
}

/**
 * 注册流程会让用户看到的全部错误。**一个错误一格**，理由同 `ApiError`：
 * `when` 漏一格就编译不过，而每一格在 `strings.xml` 里有一句自己的德语。
 *
 * ⚠️ 这里**不是** `ApiError` 的拷贝。映射发生在 ViewModel 里，
 * 因为「§6.1 的错误码」与「用户该看到哪句话」不是一一对应的关系：
 * 五种 401 在契约里就是**逐字相同**的一个响应（ADR-0014 注 3），
 * 所以它们在这里也只能是一格 [CodeRejected]。
 */
sealed interface OnboardingError {
    /** 本地预校验：这串东西不像个邮箱。没发出去任何请求。 */
    data object EmailLooksInvalid : OnboardingError

    /**
     * `401` —— 码错 / 过期 / 已消费 / 次数耗尽，四种在契约里**不可区分**。
     *
     * ⚠️ 别试图分开显示。服务端**刻意**让五种拒绝形状逐字相同、耗时相同
     * （§6.3.1 注 3），编出来的区分只能是猜的，而猜错比笼统更糟。
     * 文案要做的是把用户导向唯一的出路：重发一个码。
     */
    data object CodeRejected : OnboardingError

    /**
     * `429` —— 每邮箱 1/min、5/h、10/day（§7.5）。
     *
     * ⚠️ **不自动重试**。这三个窗口挡的是「用 N-Cards 的域名给别人的收件箱发信」，
     * 自动重试等于替攻击者按住按钮。照 [retryAfterSeconds] 显示倒计时即可。
     */
    data class TooManyRequests(
        val retryAfterSeconds: Long?,
    ) : OnboardingError

    /** 连不上。离线优先的常态，文案不要像出了大事。 */
    data object Offline : OnboardingError

    /** `503` —— 维护窗口，或限流器无法判定（ADR-0005）。 */
    data object ServiceUnavailable : OnboardingError

    /** `426` —— 低于 `min_supported_client`。强制升级墙本身归 T-158。 */
    data object ClientTooOld : OnboardingError

    /** 其余一切。文案是「稍后再试」，同时该上报 Sentry（T-451）。 */
    data object Unexpected : OnboardingError
}
