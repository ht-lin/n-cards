package de.ncards.feature.wallet

import app.cash.turbine.test
import de.ncards.core.testing.CardFixtures
import de.ncards.core.testing.MainDispatcherExtension
import kotlinx.coroutines.test.advanceTimeBy
import kotlinx.coroutines.test.advanceUntilIdle
import kotlinx.coroutines.test.runTest
import org.junit.jupiter.api.Assertions.assertEquals
import org.junit.jupiter.api.Assertions.assertFalse
import org.junit.jupiter.api.Assertions.assertInstanceOf
import org.junit.jupiter.api.Assertions.assertTrue
import org.junit.jupiter.api.DisplayName
import org.junit.jupiter.api.Nested
import org.junit.jupiter.api.Test
import org.junit.jupiter.api.extension.RegisterExtension
import kotlin.time.Duration.Companion.seconds

@DisplayName("钱包列表 ViewModel")
class WalletViewModelTest {
    @JvmField
    @RegisterExtension
    val mainDispatcher = MainDispatcherExtension()

    private val repository = FakeCardRepository()

    private fun viewModel() = WalletViewModel(repository)

    @Nested
    @DisplayName("四态迁移")
    inner class States {
        @Test
        @DisplayName("初值是 Loading——冷启动第一帧不知道有没有卡")
        fun startsLoading() =
            runTest {
                assertEquals(WalletUiState.Loading, viewModel().state.value)
            }

        @Test
        @DisplayName("登录后没有卡 → Empty")
        fun emptyWalletYieldsEmpty() =
            runTest {
                repository.signIn()

                viewModel().state.test {
                    assertEquals(WalletUiState.Loading, awaitItem())
                    assertEquals(WalletUiState.Empty, awaitItem())
                    cancelAndIgnoreRemainingEvents()
                }
            }

        @Test
        @DisplayName("有卡 → Content")
        fun cardsYieldContent() =
            runTest {
                repository.signIn()
                repository.emit(CardFixtures.cards(3))

                viewModel().state.test {
                    skipItems(1)
                    val content = assertInstanceOf(WalletUiState.Content::class.java, awaitItem())

                    assertEquals(3, content.cards.size)
                    assertEquals(3, content.totalCount)
                    cancelAndIgnoreRemainingEvents()
                }
            }

        /**
         * ⚠️ 没有这个超时的话，`WalletUiState.Error` 是一个永远走不到的分支 ——
         * 而那正是 `SyncState` 拒绝提前加 `SYNCING` 时用的同一条理由。
         *
         * 会走到这里的真实情形：T-151 存下的会话升级上来（那时还没有
         * `auth_user_id` 这个 key），而补写它的那次 `GET /v1/me` 又失败了（离线）。
         */
        @Test
        @DisplayName("一直读不出「我是谁」→ Error，而不是永远转圈")
        fun unresolvedUserEventuallyErrors() =
            runTest {
                viewModel().state.test {
                    assertEquals(WalletUiState.Loading, awaitItem())

                    advanceTimeBy(4.seconds)

                    assertEquals(
                        WalletUiState.Error(WalletError.CurrentUserUnknown),
                        awaitItem(),
                    )
                    cancelAndIgnoreRemainingEvents()
                }
            }

        @Test
        @DisplayName("宽限期内读出了「我是谁」就不报错")
        fun resolvingInTimeDoesNotError() =
            runTest {
                val vm = viewModel()

                vm.state.test {
                    assertEquals(WalletUiState.Loading, awaitItem())

                    advanceTimeBy(1.seconds)
                    repository.signIn()

                    assertEquals(WalletUiState.Empty, awaitItem())
                    cancelAndIgnoreRemainingEvents()
                }
            }
    }

    @Nested
    @DisplayName("搜索")
    inner class Search {
        @Test
        @DisplayName("过滤标题，且不改变未过滤的总数")
        fun filtersByTitle() =
            runTest {
                repository.signIn()
                repository.emit(
                    listOf(
                        // ⚠️ 商家名要显式给：CardFixtures 的默认值是 "REWE"，
                        // 不覆盖的话 b 会经由商家名命中 "rewe"，而那是**对的**行为
                        // （搜索同时看标题与商家名），只是会让这条用例测不到它想测的东西。
                        CardFixtures.card(id = "a", title = "REWE Payback", merchantLabel = "REWE"),
                        CardFixtures.card(id = "b", title = "DM Payback", merchantLabel = "dm"),
                    ),
                )
                val vm = viewModel()
                vm.state.test {
                    vm.onQueryChanged("rewe")
                    advanceUntilIdle()

                    val content = assertInstanceOf(WalletUiState.Content::class.java, expectMostRecentItem())
                    assertEquals(listOf("a"), content.cards.map { it.id })
                    assertEquals(2, content.totalCount, "totalCount 是**未过滤**的总数")
                    cancelAndIgnoreRemainingEvents()
                }
            }

        /** 主要市场是德国，而带变音符号的商家名到处都是（Müller、Höffner…）。 */
        @Test
        @DisplayName("德语变音符号的大小写能互相匹配")
        fun matchesGermanUmlauts() =
            runTest {
                repository.signIn()
                repository.emit(listOf(CardFixtures.card(id = "a", title = "Müller", merchantLabel = null)))
                val vm = viewModel()
                vm.state.test {
                    vm.onQueryChanged("MÜLLER")
                    advanceUntilIdle()

                    val content = assertInstanceOf(WalletUiState.Content::class.java, expectMostRecentItem())
                    assertEquals(1, content.cards.size)
                    cancelAndIgnoreRemainingEvents()
                }
            }

        /**
         * ⚠️⚠️ 本文件最重要的一条。
         *
         * 一个有 40 张卡的用户搜错一个字母，看到的必须是「没有匹配」，
         * **不是**「你还没有任何卡，添加第一张吧」——后者既是谎话，
         * 给的出路也是错的。
         */
        @Test
        @DisplayName("搜索无结果仍然是 Content，不塌陷成 Empty")
        fun noSearchResultIsNotEmpty() =
            runTest {
                repository.signIn()
                repository.emit(CardFixtures.cards(40)) // 标题是 "Karte 0"…，商家名默认 "REWE"
                val vm = viewModel()
                vm.state.test {
                    vm.onQueryChanged("zzzz")
                    advanceUntilIdle()

                    val content = assertInstanceOf(WalletUiState.Content::class.java, expectMostRecentItem())
                    assertTrue(content.isNoSearchResult)
                    assertTrue(content.cards.isEmpty())
                    assertEquals(40, content.totalCount)
                    cancelAndIgnoreRemainingEvents()
                }
            }

        @Test
        @DisplayName("真的没有卡时 isNoSearchResult 为假——那是 Empty 的地盘")
        fun emptyWalletIsNotANoSearchResult() =
            runTest {
                repository.signIn()
                repository.emit(CardFixtures.cards(1))
                val vm = viewModel()
                vm.state.test {
                    advanceUntilIdle()

                    val content = assertInstanceOf(WalletUiState.Content::class.java, expectMostRecentItem())
                    assertFalse(content.isNoSearchResult, "查询是空的，这不是「搜索无结果」")
                    cancelAndIgnoreRemainingEvents()
                }
            }

        @Test
        @DisplayName("清空搜索后列表回来")
        fun clearingTheQueryRestoresTheList() =
            runTest {
                repository.signIn()
                repository.emit(CardFixtures.cards(3))
                val vm = viewModel()
                vm.state.test {
                    vm.onQueryChanged("zzzz")
                    advanceUntilIdle()
                    vm.onClearQuery()
                    advanceUntilIdle()

                    val content = assertInstanceOf(WalletUiState.Content::class.java, expectMostRecentItem())
                    assertEquals(3, content.cards.size)
                    cancelAndIgnoreRemainingEvents()
                }
            }
    }

    @Nested
    @DisplayName("置顶与重排")
    inner class Placement {
        @Test
        @DisplayName("置顶时把当前状态取反传下去")
        fun togglesPin() =
            runTest {
                repository.signIn()
                repository.emit(listOf(CardFixtures.card(id = "a", isPinned = false)))
                val vm = viewModel()

                vm.state.test {
                    advanceUntilIdle()
                    vm.onTogglePin("a")
                    advanceUntilIdle()
                    cancelAndIgnoreRemainingEvents()
                }

                assertEquals(listOf("a" to true), repository.pinCalls)
            }

        @Test
        @DisplayName("已置顶的卡再点一次是取消置顶")
        fun togglesPinBack() =
            runTest {
                repository.signIn()
                repository.emit(listOf(CardFixtures.card(id = "a", isPinned = true)))
                val vm = viewModel()

                vm.state.test {
                    advanceUntilIdle()
                    vm.onTogglePin("a")
                    advanceUntilIdle()
                    cancelAndIgnoreRemainingEvents()
                }

                assertEquals(listOf("a" to false), repository.pinCalls)
            }

        @Test
        @DisplayName("列表里没有的卡不发起写入")
        fun ignoresUnknownCard() =
            runTest {
                repository.signIn()
                repository.emit(listOf(CardFixtures.card(id = "a")))
                val vm = viewModel()

                vm.state.test {
                    advanceUntilIdle()
                    vm.onTogglePin("ghost")
                    advanceUntilIdle()
                    cancelAndIgnoreRemainingEvents()
                }

                assertTrue(repository.pinCalls.isEmpty())
            }

        @Test
        @DisplayName("重排原样转发给 Repository")
        fun forwardsReorder() =
            runTest {
                repository.signIn()
                val vm = viewModel()

                vm.onReorder("a", 2)
                advanceUntilIdle()

                assertEquals(listOf("a" to 2), repository.reorderCalls)
            }
    }
}
