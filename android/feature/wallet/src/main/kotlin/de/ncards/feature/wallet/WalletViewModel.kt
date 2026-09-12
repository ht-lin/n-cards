package de.ncards.feature.wallet

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import dagger.hilt.android.lifecycle.HiltViewModel
import de.ncards.core.model.card.matches
import de.ncards.data.card.CardRepository
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.SharingStarted
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.combine
import kotlinx.coroutines.flow.flatMapLatest
import kotlinx.coroutines.flow.flow
import kotlinx.coroutines.flow.flowOf
import kotlinx.coroutines.flow.stateIn
import kotlinx.coroutines.launch
import javax.inject.Inject
import kotlin.time.Duration.Companion.seconds

/**
 * 钱包列表的 ViewModel。
 *
 * ============================================================================
 * §4.3 铁律一在这里的样子
 * ============================================================================
 * 「UI **永远**从 Room 的 `Flow` 读取，**绝不**直接消费网络响应渲染」——
 * 所以本类**没有**任何「刷新」「重新拉取」的方法，`CardRepository` 上也没有。
 * 断网时这一屏不需要任何特殊分支，因为它压根不知道网络存在。
 *
 * ============================================================================
 * ⚠️ 搜索为什么用 `combine` 而不是把查询词传进 Repository
 * ============================================================================
 * 传进去的话，每敲一个字母都要 `flatMapLatest` 重订阅一次 Room ——
 * 重跑一次带两个 JOIN 和一个派生子查询的 SQL，中间还会经过一帧空列表
 * （用户看到的是搜索时列表在闪）。
 *
 * `combine` 让 Room 那条流只订阅**一次**，过滤是纯 Kotlin 的
 * `Card.matches(query)`。500 张卡（§7.5 的上限）的 `contains` 不值得为它重跑 SQL。
 * 顺带把德语变音符号的大小写折叠做对了 —— SQLite 的 `LIKE` 做不到（见 `CardSearch`）。
 */
@HiltViewModel
class WalletViewModel
    @Inject
    constructor(
        private val repository: CardRepository,
    ) : ViewModel() {
        private val query = MutableStateFlow("")

        /**
         * 「我是谁」解析到了没有。
         *
         * ⚠️ 为什么需要一个**带超时**的中间状态：`CurrentUserIdStore.userId` 的
         * `null` 有两种含义（还没读过存储 / 读过了但确实没有），而接口刻意不区分它们。
         * 对钱包来说这两者一开始确实是同一件事（都显示 Loading），
         * 但**不会一直是**：读一次存储是几毫秒的事，几秒之后还是 null
         * 就不再是「在加载」，而是真的出了问题。
         *
         * 没有这个超时的话，`WalletUiState.Error` 会是一个永远走不到的分支 ——
         * 而那正是 `SyncState` 拒绝提前加 `SYNCING` 时用的同一条理由。
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

        val state: StateFlow<WalletUiState> =
            combine(
                repository.observeWallet(),
                query,
                userPresence,
            ) { cards, currentQuery, presence ->
                when {
                    presence == UserPresence.Resolving -> {
                        WalletUiState.Loading
                    }

                    presence == UserPresence.Missing -> {
                        WalletUiState.Error(WalletError.CurrentUserUnknown)
                    }

                    cards.isEmpty() -> {
                        WalletUiState.Empty
                    }

                    else -> {
                        WalletUiState.Content(
                            cards = cards.filter { it.matches(currentQuery) },
                            query = currentQuery,
                            totalCount = cards.size,
                        )
                    }
                }
            }.stateIn(
                scope = viewModelScope,
                // 旋屏与「切到后台再回来」不该让列表重新加载一遍。
                // 5 秒是 Android 官方指导里那个数：足够覆盖配置变更，
                // 又不会在用户真的离开之后还挂着一条 Room 订阅。
                started = SharingStarted.WhileSubscribed(STOP_TIMEOUT_MILLIS),
                initialValue = WalletUiState.Loading,
            )

        fun onQueryChanged(value: String) {
            query.value = value
        }

        fun onClearQuery() {
            query.value = ""
        }

        /**
         * 置顶 / 取消置顶。
         *
         * ⚠️ 不做乐观更新，也不需要：写的是 Room，而 UI 读的就是 Room 的 `Flow`——
         * 写完那一刻列表自己就变了。这正是离线优先架构省掉的那一类代码
         * （§4.3 铁律三说的「网络失败不得回滚 UI」，在这里退化成「根本没有要回滚的东西」）。
         */
        fun onTogglePin(cardId: String) {
            val card = (state.value as? WalletUiState.Content)?.cards?.firstOrNull { it.id == cardId } ?: return

            viewModelScope.launch {
                repository.setPinned(cardId, !card.isPinned)
            }
        }

        /**
         * 拖拽落下。
         *
         * [toIndex] 是**同一区段内**的下标（置顶的一段、或非置顶的一段），
         * 由 UI 算好传进来 —— 见 `CardRepository.reorder`。
         */
        fun onReorder(
            cardId: String,
            toIndex: Int,
        ) {
            viewModelScope.launch {
                repository.reorder(cardId, toIndex)
            }
        }

        /** 见 [userPresence]。 */
        private enum class UserPresence {
            Resolving,
            Known,
            Missing,
        }

        private companion object {
            /**
             * 超过这个时间还读不出「我是谁」，就不再当成「在加载」。
             *
             * 3 秒是给一次 Keystore 读 + 一次可能的 `GET /v1/me` 补写留的余量
             * （T-151 存下的会话里没有 `auth_user_id`，靠冷启动那次 `fetchMe` 补）。
             * 低端真机（§13.8 的 Android 8 / 2 GB）上 Keystore 解包是几十毫秒量级，
             * 3 秒足够宽。
             */
            val USER_RESOLUTION_GRACE = 3.seconds

            const val STOP_TIMEOUT_MILLIS = 5_000L
        }
    }
