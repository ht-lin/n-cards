package de.ncards.feature.carddetail

import androidx.lifecycle.SavedStateHandle
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import dagger.hilt.android.lifecycle.HiltViewModel
import de.ncards.core.model.navigation.CardDetailRoute
import de.ncards.data.card.CardRepository
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.SharingStarted
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.combine
import kotlinx.coroutines.flow.flatMapLatest
import kotlinx.coroutines.flow.flow
import kotlinx.coroutines.flow.flowOf
import kotlinx.coroutines.flow.stateIn
import javax.inject.Inject
import kotlin.time.Duration.Companion.seconds

/**
 * 卡详情与全屏条码页共用的 ViewModel（T-154）。
 *
 * ============================================================================
 * ⚠️ 它有**两个宿主**，cardId 因此按 key 取而不是用 `toRoute<CardDetailRoute>()`
 * ============================================================================
 * - 详情页住在 NavHost 里：`SavedStateHandle` 由**路由参数**种，
 *   key 就是 `CardDetailRoute` 的属性名。
 * - 全屏条码页住在一个独立 `Activity` 里（§10.2）：`SavedStateHandle` 由
 *   **Intent extras** 种。那里根本没有路由，`toRoute<CardDetailRoute>()` 会直接抛。
 *
 * 两边都用 [CARD_ID_KEY] 这一个 key，于是同一个 ViewModel 在两个宿主下都取得到 id，
 * 而 `:app` 的 Activity 用的也是这个常量（`putExtra`）—— **全仓只有这一处定义**。
 *
 * ⚠️ 它的值必须与 `CardDetailRoute.cardId` 的**属性名**逐字相同。
 * 改那个属性名而不改这里，详情页会静默拿到 `null`（导航那一半坏掉，
 * 全屏那一半照常工作）—— `CardDetailRoute` 的 KDoc 也记了这条耦合。
 *
 * ============================================================================
 * §4.3 铁律一在这里的样子
 * ============================================================================
 * 本类**没有**任何「刷新」「重新拉取」的方法，`CardRepository` 上也没有。
 * 断网时这一屏不需要任何特殊分支，因为它压根不知道网络存在。
 */
@HiltViewModel
class CardDetailViewModel
    @Inject
    constructor(
        savedStateHandle: SavedStateHandle,
        repository: CardRepository,
    ) : ViewModel() {
        private val cardId: String = checkNotNull(savedStateHandle[CARD_ID_KEY]) {
            // 两个宿主都必须种这个 key（见类注释）。缺了它是一个装配错误，
            // 不是一种用户可能遇到的状态 —— 兜成 Missing 只会把它藏到
            // 「这张卡不在你的钱包里」后面，而那时用户的卡明明还在。
            "缺少 $CARD_ID_KEY：导航参数与 Intent extra 必须用同一个 key"
        }

        /**
         * 「我是谁」解析到了没有。
         *
         * ⚠️ 这不是从 `feature:wallet` 抄来凑数的 —— 没有它，[CardDetailUiState.Missing]
         * 会在**每一次冷启动的头几十毫秒**误报一次：`observeCard` 在用户未知时
         * 发的也是 `null`（与 `observeWallet` 发空列表是同一条约定），
         * 而那时说「这张卡已不在你的钱包里」是假话。
         *
         * 而全屏条码页正是**最容易**撞上这一帧的入口：T-254 的 Widget 点一下
         * 就是冷启动直奔这一页，令牌还没过完 Keystore。
         *
         * 反过来，几秒之后还是 `null` 就不再是「在加载」，那才是
         * [CardDetailError.CurrentUserUnknown]。
         */
        @OptIn(ExperimentalCoroutinesApi::class)
        private val userPresence: Flow<UserPresence> =
            repository.observeCurrentUserId().flatMapLatest { userId ->
                if (userId != null) {
                    flowOf(UserPresence.Known)
                } else {
                    flow {
                        emit(UserPresence.Resolving)
                        delay(USER_RESOLUTION_GRACE)
                        emit(UserPresence.Missing)
                    }
                }
            }

        val state: StateFlow<CardDetailUiState> =
            combine(
                repository.observeCard(cardId),
                userPresence,
            ) { card, presence ->
                when {
                    // ⚠️ 卡先于用户判定：已经拿到卡了就没什么好等的。
                    // 反过来写的话，用户 id 恰好在这一帧还没到的场合会闪一下 Loading。
                    card != null -> {
                        CardDetailUiState.Content(card)
                    }

                    presence == UserPresence.Resolving -> {
                        CardDetailUiState.Loading
                    }

                    presence == UserPresence.Missing -> {
                        CardDetailUiState.Error(CardDetailError.CurrentUserUnknown)
                    }

                    else -> {
                        CardDetailUiState.Missing
                    }
                }
            }.stateIn(
                scope = viewModelScope,
                // 旋屏不该让条码重新加载一遍 —— 而全屏条码页**刻意**不吃
                // configChanges（横竖屏的布局真的不一样），所以旋转就是一次重建。
                started = SharingStarted.WhileSubscribed(STOP_TIMEOUT_MILLIS),
                initialValue = CardDetailUiState.Loading,
            )

        /** 见 [userPresence]。与 `WalletViewModel` 里那个是同一套语义。 */
        private enum class UserPresence {
            Resolving,
            Known,
            Missing,
        }

        companion object {
            /**
             * 路由参数与 Intent extra 共用的 key。见类注释。
             *
             * 用属性引用而不是字面量 `"cardId"`：改了属性名编译期就会跟着改，
             * 而字面量会静默漂掉。
             */
            val CARD_ID_KEY: String = CardDetailRoute::cardId.name

            /** 与 `WalletViewModel` 的同名常量同一个理由、同一个数。 */
            private val USER_RESOLUTION_GRACE = 3.seconds

            private const val STOP_TIMEOUT_MILLIS = 5_000L
        }
    }
