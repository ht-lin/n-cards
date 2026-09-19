package de.ncards.feature.carddetail

import androidx.lifecycle.SavedStateHandle
import app.cash.turbine.test
import de.ncards.core.model.card.CardRole
import de.ncards.core.testing.CardFixtures
import de.ncards.core.testing.MainDispatcherExtension
import de.ncards.data.card.FakeCardRepository
import kotlinx.coroutines.test.advanceTimeBy
import kotlinx.coroutines.test.runTest
import org.junit.jupiter.api.Assertions.assertEquals
import org.junit.jupiter.api.Assertions.assertFalse
import org.junit.jupiter.api.Assertions.assertInstanceOf
import org.junit.jupiter.api.Assertions.assertNull
import org.junit.jupiter.api.Assertions.assertThrows
import org.junit.jupiter.api.DisplayName
import org.junit.jupiter.api.Nested
import org.junit.jupiter.api.Test
import org.junit.jupiter.api.extension.RegisterExtension
import kotlin.time.Duration.Companion.seconds

@DisplayName("卡详情 ViewModel")
class CardDetailViewModelTest {
    @JvmField
    @RegisterExtension
    val mainDispatcher = MainDispatcherExtension()

    private val repository = FakeCardRepository()

    private fun viewModel(cardId: String = CARD_ID) =
        CardDetailViewModel(
            savedStateHandle = SavedStateHandle(mapOf(CardDetailViewModel.CARD_ID_KEY to cardId)),
            repository = repository,
        )

    @Nested
    @DisplayName("四态")
    inner class States {
        @Test
        @DisplayName("初值是 Loading")
        fun startsLoading() =
            runTest {
                viewModel().state.test {
                    assertEquals(CardDetailUiState.Loading, awaitItem())
                    cancelAndIgnoreRemainingEvents()
                }
            }

        @Test
        @DisplayName("卡在就是 Content")
        fun cardYieldsContent() =
            runTest {
                repository.signIn()
                repository.emit(CardFixtures.card(id = CARD_ID, title = "REWE Payback"))

                viewModel().state.test {
                    assertEquals(CardDetailUiState.Loading, awaitItem())

                    val content = assertInstanceOf(CardDetailUiState.Content::class.java, awaitItem())
                    assertEquals("REWE Payback", content.card.title)
                    cancelAndIgnoreRemainingEvents()
                }
            }

        /**
         * ⚠️ 卡被 owner 删掉 / 自己被移出成员 / 解除好友，都走到这里。
         * 它**不是** `Error` —— §16 R12 要求用户分得清这不是 Bug。
         */
        @Test
        @DisplayName("卡不在钱包里是 Missing，不是 Error")
        fun absentCardYieldsMissing() =
            runTest {
                repository.signIn()
                repository.emit(CardFixtures.card(id = "eine-andere-karte"))

                viewModel().state.test {
                    assertEquals(CardDetailUiState.Loading, awaitItem())
                    assertEquals(CardDetailUiState.Missing, awaitItem())
                    cancelAndIgnoreRemainingEvents()
                }
            }

        @Test
        @DisplayName("详情页开着时卡被删掉 → 从 Content 走到 Missing")
        fun cardDisappearingWhileOpen() =
            runTest {
                repository.signIn()
                repository.emit(CardFixtures.card(id = CARD_ID))

                viewModel().state.test {
                    assertEquals(CardDetailUiState.Loading, awaitItem())
                    assertInstanceOf(CardDetailUiState.Content::class.java, awaitItem())

                    repository.emit(emptyList())

                    assertEquals(CardDetailUiState.Missing, awaitItem())
                    cancelAndIgnoreRemainingEvents()
                }
            }

        /**
         * ⚠️ 这一条守的是宽限期。
         *
         * 没有它，冷启动的头几十毫秒会误报一次 `Missing` ——「这张卡已不在你的
         * 钱包里」，而用户的卡明明还在，只是令牌还没过完 Keystore。
         * T-254 的 Widget 点击正是冷启动直奔这一页，最容易撞上那一帧。
         */
        @Test
        @DisplayName("还不知道我是谁时停在 Loading，不误报 Missing")
        fun unresolvedUserStaysLoading() =
            runTest {
                viewModel().state.test {
                    assertEquals(CardDetailUiState.Loading, awaitItem())

                    advanceTimeBy(1.seconds)

                    expectNoEvents()
                    cancelAndIgnoreRemainingEvents()
                }
            }

        @Test
        @DisplayName("几秒后还读不出「我是谁」才是 Error")
        fun unresolvedUserEventuallyErrors() =
            runTest {
                viewModel().state.test {
                    assertEquals(CardDetailUiState.Loading, awaitItem())

                    advanceTimeBy(5.seconds)

                    assertEquals(
                        CardDetailUiState.Error(CardDetailError.CurrentUserUnknown),
                        expectMostRecentItem(),
                    )
                    cancelAndIgnoreRemainingEvents()
                }
            }

        /**
         * ⚠️ 卡先于用户判定。反过来写的话，用户 id 恰好在这一帧还没到的场合
         * 会闪一下 Loading —— 而卡已经在手上了。
         */
        @Test
        @DisplayName("卡已经在手上时不等用户判定")
        fun cardWinsOverUserResolution() =
            runTest {
                repository.emit(CardFixtures.card(id = CARD_ID))

                viewModel().state.test {
                    assertEquals(CardDetailUiState.Loading, awaitItem())
                    assertInstanceOf(CardDetailUiState.Content::class.java, awaitItem())
                    cancelAndIgnoreRemainingEvents()
                }
            }
    }

    @Nested
    @DisplayName("角色与共享")
    inner class Sharing {
        /** §7.3：viewer 在 UI 层即无编辑入口。gate 用的是契约给的 `canEdit`。 */
        @Test
        @DisplayName("viewer 的 canEdit 是 false")
        fun viewerCannotEdit() =
            runTest {
                repository.signIn()
                repository.emit(CardFixtures.card(id = CARD_ID, role = CardRole.VIEWER))

                viewModel().state.test {
                    assertEquals(CardDetailUiState.Loading, awaitItem())

                    val content = assertInstanceOf(CardDetailUiState.Content::class.java, awaitItem())
                    assertFalse(content.card.canEdit)
                    cancelAndIgnoreRemainingEvents()
                }
            }

        /**
         * ⚠️ `memberCount` 对 viewer 恒为 `null`，而契约原文是「它是 `null` 时
         * **不得**推断任何默认值」。兜成 0 就等于告诉 viewer「这张卡没被共享给
         * 别人」—— 而成员数量本身就是信息（威胁模型 T21）。
         */
        @Test
        @DisplayName("viewer 拿不到 memberCount，而且它不被兜底成 0")
        fun viewerHasNoMemberCount() =
            runTest {
                repository.signIn()
                repository.emit(CardFixtures.card(id = CARD_ID, role = CardRole.VIEWER))

                viewModel().state.test {
                    assertEquals(CardDetailUiState.Loading, awaitItem())

                    val content = assertInstanceOf(CardDetailUiState.Content::class.java, awaitItem())
                    assertNull(content.card.memberCount)
                    cancelAndIgnoreRemainingEvents()
                }
            }
    }

    /**
     * 两个宿主都必须种 [CardDetailViewModel.CARD_ID_KEY]（NavHost 由路由参数种，
     * Activity 由 Intent extra 种）。缺了它是一个**装配错误**，不是一种用户
     * 可能遇到的状态 —— 兜成 `Missing` 只会把它藏在「这张卡不在你的钱包里」
     * 后面，而那时用户的卡明明还在。
     */
    @Test
    @DisplayName("缺 cardId 直接抛，不兜底成 Missing")
    fun missingCardIdFailsLoudly() {
        assertThrows(IllegalStateException::class.java) {
            CardDetailViewModel(savedStateHandle = SavedStateHandle(), repository = repository)
        }
    }

    private companion object {
        const val CARD_ID = "0192f3a1-b2c3-7d4e-8f01-23456789abcd"
    }
}
