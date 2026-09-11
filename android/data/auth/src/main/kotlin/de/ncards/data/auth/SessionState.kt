package de.ncards.data.auth

/**
 * 本机有没有一个可用的会话。`:app` 的 NavHost 与 T-151 的 ViewModel 观察它。
 *
 * ============================================================================
 * ⚠️ 为什么有 [Unknown] 这一格
 * ============================================================================
 * 不是防御性编程。令牌躺在 `SecretStore` 里，读出来要过一趟 Keystore —— 那是
 * I/O，不能在主线程的第一帧完成。把初始值写成 [SignedOut] 的后果是：
 * 每一次冷启动，已登录用户都会先被弹到登录页，再在几十毫秒后被弹回钱包。
 *
 * 所以 [Unknown] 期间 NavHost 显示 splash，**不跳任何地方**。
 *
 * 形状与后端 `Shared\Domain\Onboarding\OnboardingState` 的三态枚举同构
 * （ADR-0018 决策四）：那边也是因为 `bool` 会把第三格并进 `false` 才改的。
 */
sealed interface SessionState {
    /** 还没读过存储 —— 冷启动的第一帧。 */
    data object Unknown : SessionState

    /** 有 access + refresh token。**不**意味着它们还没过期，只意味着值得拿去用。 */
    data object SignedIn : SessionState

    data class SignedOut(
        val reason: SignedOutReason,
    ) : SessionState
}

/**
 * 为什么没有会话。T-151 要按它选德语文案，所以四格各自可辨认。
 *
 * ⚠️ 别把它们合并成一个 bool：[NeverSignedIn] 与 [SessionRevoked] 在 UI 上是
 * 「欢迎」与「你被登出了」两句完全不同的话，而 [KeyMaterialLost] 还要多说一句
 * 本机数据需要重新同步。
 */
enum class SignedOutReason {
    /** 从没登录过，或上一次登出之后还没再登录。 */
    NeverSignedIn,

    /** 用户自己点了登出。 */
    UserAction,

    /**
     * 服务端说这个会话没了：`401 token_invalid`。
     *
     * 三种情形共用这一格，因为服务端**刻意**让它们不可区分（后端
     * `RefreshTokenService::REJECTED` 的注释）：令牌被撤销、令牌重放被判定为被窃、
     * 以及账号已被删除（ADR-0018 决策四把它也做成了 401）。
     */
    SessionRevoked,

    /**
     * `SecretStore` 里存着密文，但 Keystore 的包裹密钥已经解不开了
     * （系统还原、锁屏凭据变更、ROM 行为）。
     *
     * 与 [SessionRevoked] 分开，是因为此时 SQLCipher 的 `db_passphrase` 多半
     * 也一起失效了 —— 用户要看到的是「本机数据需重新同步」，不是「你被登出了」。
     */
    KeyMaterialLost,
}
