package de.ncards.feature.wallet

import de.ncards.core.model.card.Card

/**
 * 钱包列表的状态（§10.4：每个 feature 一个 sealed interface，
 * **禁止**用多个独立布尔标志表达状态）。
 *
 * ============================================================================
 * ⚠️ 这里**真的**用了 `Loading` / `Content` / `Empty` / `Error` 四格
 * ============================================================================
 * `feature:onboarding` 刻意偏离了这四格，它的注释解释得很清楚：那四格
 * 「是给**列表页**设计的」，而注册流程是一个线性向导。
 *
 * 本卡就是那个列表页，所以四格原样落地。
 *
 * ============================================================================
 * ⚠️⚠️ [Empty] 与「搜索无结果」**不是**同一个状态
 * ============================================================================
 * 这是本文件唯一一个真正会被写错的地方。
 *
 * - [Empty]：钱包里**一张卡都没有**。出路是「添加第一张卡」——J1 的终点。
 * - [Content] 且 [Content.isNoSearchResult]：有卡，只是**这次搜索**没匹配上。
 *   出路是「清空搜索」。
 *
 * 把它们并成一格的后果：一个有 40 张卡的用户搜错一个字母，
 * 看到的是「你还没有任何卡，添加第一张吧」。那既是谎话，给的出路也是错的。
 *
 * 所以 [Content] 在搜索无结果时**仍然是 Content** —— `cards` 为空，
 * 但 `query` 非空、`totalCount` 大于零，三者合起来才说得清发生了什么。
 */
sealed interface WalletUiState {
    /**
     * 还不知道该显示什么。
     *
     * 两种情形共用这一格：Room 的第一帧还没到，以及**当前用户还不知道是谁**
     * （冷启动时令牌要过一趟 Keystore 才读得出来，见 `CurrentUserIdStore`）。
     * 两者对用户是同一件事 —— 「稍等」，所以不必分开。
     */
    data object Loading : WalletUiState

    /** 钱包里一张卡都没有。**不是**「搜索没结果」，见类注释。 */
    data object Empty : WalletUiState

    /**
     * 有卡。
     *
     * @param cards **已经按 [query] 过滤过**的那些。顺序是 Room 给的
     *   （置顶优先，然后是每成员私有的 `sort_order`）—— UI **不要**再排一次，
     *   否则拖拽完位置会自己跳回去。
     * @param query 当前搜索词。它住在这里而不是一个独立字段，
     *   正是 §10.4「禁止多个独立布尔标志」要的形状。
     * @param totalCount **未过滤**的总数。区分「搜索无结果」与「真的没有卡」靠它。
     */
    data class Content(
        val cards: List<Card>,
        val query: String,
        val totalCount: Int,
    ) : WalletUiState {
        /**
         * 计算属性而不是存字段 —— 存两份迟早漂移，而漂移时 UI 显示的是哪一份
         * 全看谁后写。与 `UsernameUiState` 的 `canSubmit` 同一个做法。
         */
        val isNoSearchResult: Boolean get() = cards.isEmpty() && query.isNotBlank()
    }

    /**
     * 出事了。
     *
     * ⚠️ 今天只有一种情形走得到这里（见 [WalletError]）。它仍然是一个独立的格，
     * 因为把错误折叠进 [Empty] 会让用户在「读不出你是谁」时看到
     * 「你还没有任何卡，添加第一张吧」—— 而他有卡，只是本机现在打不开。
     */
    data class Error(
        val reason: WalletError,
    ) : WalletUiState
}

/**
 * 钱包能出的错。
 *
 * ⚠️ 是 sealed 而不是一个字符串：§10.4 要求 ViewModel **不得** import Android
 * framework 类，所以它不能在这里 `getString`。文案由 UI 层的
 * `WalletError.message()` 映射（`WalletMessages.kt`），与
 * `feature:onboarding` 的 `OnboardingMessages.kt` 同一个做法。
 */
sealed interface WalletError {
    /**
     * 令牌在，但本机读不出「我是谁」。
     *
     * 这在正常路径上不会发生。它会发生在：T-151 存下的会话升级上来
     * （那时还没有 `auth_user_id` 这个 key），而补写它的那次 `GET /v1/me`
     * 又失败了（离线）。
     *
     * ⚠️ 对用户要说的**不是**「你没有卡」，而是「本机数据暂时读不出来，
     * 联网后会恢复」—— 他的卡还在服务端，也还在本机库里，只是查询发不出去。
     */
    data object CurrentUserUnknown : WalletError
}
