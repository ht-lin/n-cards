package de.ncards.feature.carddetail

import de.ncards.core.model.card.Card

/**
 * 卡详情与全屏条码页共用的状态（§10.4：每个 feature 一个 sealed interface，
 * **禁止**用多个独立布尔标志表达状态）。
 *
 * ============================================================================
 * ⚠️ [Missing] 与 [Error] 不是同一件事
 * ============================================================================
 * 这是本文件唯一一个真正会被写错的地方，与 `WalletUiState` 里
 * 「`Empty` 不是『搜索无结果』」是同一类错误。
 *
 * - [Missing]：这张卡**不在你的钱包里了**。owner 把它删了、你被移出成员、
 *   或者解除好友把共享卡一并收走 —— 全都是**正常结局**，而且完全可能发生在
 *   详情页正开着的时候（一次下行同步就够了）。出路是「返回」。
 * - [Error]：本机出了故障（今天只有一种：令牌在但读不出「我是谁」）。
 *   出路是等联网/重试。
 *
 * 并成一格的后果：一个卡刚被 owner 删掉的用户，看到的是「出错了」——
 * 而什么都没错，他只是失去了这张卡。§16 R12 点名这类「卡凭空消失」的场景
 * **必须**让用户分得清是不是 Bug，把它说成一个错误正好是那条风险要防的事。
 *
 * 这里没有 `Empty` 那一格：一张卡的详情页没有「空」这种状态，
 * 卡要么在要么不在。§10.4 的四格是给**列表页**设计的 —— `feature:onboarding`
 * 也照同样的理由偏离过，它的注释解释了为什么向导不适用。
 */
sealed interface CardDetailUiState {
    /**
     * 还不知道该显示什么。
     *
     * 两种情形共用这一格：Room 的第一帧还没到，以及**当前用户还不知道是谁**
     * （冷启动时令牌要过一趟 Keystore 才读得出来）。对用户是同一件事 ——「稍等」。
     *
     * ⚠️ 它也是 [Missing] 的**宽限期**：`observeCard` 在用户未知时发的也是 `null`，
     * 而那时说「这张卡已不在你的钱包里」是假话。见 `CardDetailViewModel`。
     */
    data object Loading : CardDetailUiState

    /** 卡在。 */
    data class Content(
        val card: Card,
    ) : CardDetailUiState

    /** 卡已经不在这台设备的钱包里了。**不是**错误，见类注释。 */
    data object Missing : CardDetailUiState

    /** 出事了。 */
    data class Error(
        val reason: CardDetailError,
    ) : CardDetailUiState
}

/**
 * 详情页能出的错。
 *
 * ⚠️ 是 sealed 而不是一个字符串：§10.4 要求 ViewModel **不得** import Android
 * framework 类，所以它不能在那里 `getString`。文案由 UI 层的
 * `CardDetailError.message()` 映射（`CardDetailMessages.kt`），
 * 与 `feature:wallet` 的 `WalletMessages.kt` 同一个做法。
 */
sealed interface CardDetailError {
    /**
     * 令牌在，但本机读不出「我是谁」。
     *
     * 与 `WalletError.CurrentUserUnknown` 是同一件事、同一套文案口径：
     * 要说的**不是**「这张卡没了」，而是「本机数据暂时读不出来，联网后会恢复」。
     */
    data object CurrentUserUnknown : CardDetailError
}
