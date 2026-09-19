package de.ncards.feature.cardedit

import androidx.lifecycle.SavedStateHandle
import app.cash.turbine.test
import de.ncards.core.barcode.format.BarcodePayloadProblem
import de.ncards.core.model.barcode.BarcodeFormat
import de.ncards.core.model.card.CardColor
import de.ncards.core.model.card.CardDraftProblem
import de.ncards.core.model.card.CardField
import de.ncards.core.testing.CardFixtures
import de.ncards.core.testing.MainDispatcherExtension
import de.ncards.data.card.FakeCardRepository
import kotlinx.coroutines.test.advanceTimeBy
import kotlinx.coroutines.test.advanceUntilIdle
import kotlinx.coroutines.test.runTest
import org.junit.jupiter.api.Assertions.assertEquals
import org.junit.jupiter.api.Assertions.assertFalse
import org.junit.jupiter.api.Assertions.assertInstanceOf
import org.junit.jupiter.api.Assertions.assertNull
import org.junit.jupiter.api.Assertions.assertTrue
import org.junit.jupiter.api.DisplayName
import org.junit.jupiter.api.Nested
import org.junit.jupiter.api.Test
import org.junit.jupiter.api.extension.RegisterExtension
import java.time.LocalDate
import kotlin.time.Duration.Companion.seconds

@DisplayName("新增/编辑卡 ViewModel")
class CardEditViewModelTest {
    @JvmField
    @RegisterExtension
    val mainDispatcher = MainDispatcherExtension()

    private val repository = FakeCardRepository()

    /** 新建模式：`SavedStateHandle` 里**没有** cardId（`CardCreateRoute` 无参数）。 */
    private fun createViewModel() = CardEditViewModel(savedStateHandle = SavedStateHandle(), repository = repository)

    private fun editViewModel(cardId: String = CARD_ID) =
        CardEditViewModel(
            savedStateHandle = SavedStateHandle(mapOf(CardEditViewModel.CARD_ID_KEY to cardId)),
            repository = repository,
        )

    @Nested
    @DisplayName("模式")
    inner class Modes {
        /**
         * 「取不到 id 就是新建」是本类的核心判据。它错了的症状是用户点「编辑」
         * 却建了一张新卡 —— 见 `CardEditIdKeyTest`。
         */
        @Test
        @DisplayName("没有 cardId ⇒ 新建，第一帧就是空表单（不经过 Loading）")
        fun withoutCardIdItIsCreateMode() =
            runTest {
                createViewModel().state.test {
                    val first = assertInstanceOf(CardEditUiState.Editing::class.java, awaitItem())

                    assertEquals(CardEditMode.CREATE, first.mode)
                    assertEquals("", first.form.title)
                    cancelAndIgnoreRemainingEvents()
                }
            }

        @Test
        @DisplayName("有 cardId ⇒ 编辑，先 Loading 再用那张卡播种表单")
        fun withCardIdItIsEditMode() =
            runTest {
                repository.signIn()
                repository.emit(CardFixtures.card(id = CARD_ID, title = "REWE Payback"))

                editViewModel().state.test {
                    assertEquals(CardEditUiState.Loading, awaitItem())

                    val editing = assertInstanceOf(CardEditUiState.Editing::class.java, awaitItem())
                    assertEquals(CardEditMode.EDIT, editing.mode)
                    assertEquals("REWE Payback", editing.form.title)
                    cancelAndIgnoreRemainingEvents()
                }
            }

        /**
         * 新建时颜色的初值必须是 [CardColor.DEFAULT] —— `CardColor` 的 KDoc
         * 点名要求本卡这么做：色板按 `entries` 排、默认色是第一个，
         * 于是「什么都不选」与「选第一格」拿到同一个色。
         */
        @Test
        @DisplayName("新建的默认色就是 CardColor.DEFAULT")
        fun createStartsWithTheDefaultColour() =
            runTest {
                createViewModel().state.test {
                    val form = assertInstanceOf(CardEditUiState.Editing::class.java, awaitItem()).form

                    assertEquals(CardColor.DEFAULT.wireName, form.colorWire)
                    assertEquals(CardColor.DEFAULT, CardColor.entries.first())
                    cancelAndIgnoreRemainingEvents()
                }
            }
    }

    @Nested
    @DisplayName("表单不被 Room 冲掉")
    inner class FormOwnership {
        /**
         * ⚠️ **本文件最重要的一条。**
         *
         * `observeCard` 建在 `observeWallet` 之上，所以钱包里**任何一张别的卡**
         * 变一下它都会再发一次。让它直接驱动表单的话，用户正在打的字会被
         * 无声地覆盖 —— 而这在真机上只在「同时发生了一次同步」时才复现，
         * 是最难查的一类。
         */
        @Test
        @DisplayName("播种之后，上游再发一次不覆盖用户已经改过的内容")
        fun upstreamEmissionsDoNotClobberTypedInput() =
            runTest {
                repository.signIn()
                val card = CardFixtures.card(id = CARD_ID, title = "REWE Payback")
                repository.emit(card)

                val viewModel = editViewModel()

                viewModel.state.test {
                    awaitItem() // Loading
                    awaitItem() // 播种后的 Editing

                    viewModel.onTitleChange("Ich tippe gerade")

                    // 一次下行同步：同一张卡再发一遍（内容甚至可以不同）。
                    repository.emit(card.copy(title = "Vom Server überschrieben"))
                    advanceUntilIdle()

                    val latest =
                        cancelAndConsumeRemainingEvents()
                            .filterIsInstance<app.cash.turbine.Event.Item<CardEditUiState>>()
                            .map { it.value }
                            .filterIsInstance<CardEditUiState.Editing>()
                            .last()

                    assertEquals("Ich tippe gerade", latest.form.title, "用户打的字被上游覆盖了")
                }
            }
    }

    @Nested
    @DisplayName("校验")
    inner class Validation {
        /** 一打开就红着一片对用户是敌意的 —— 红字要等到点过保存之后。 */
        @Test
        @DisplayName("没点保存之前不显示任何问题，尽管问题已经算出来了")
        fun problemsAreComputedButHiddenUntilSaveIsPressed() =
            runTest {
                val viewModel = createViewModel()

                viewModel.state.test {
                    val form = assertInstanceOf(CardEditUiState.Editing::class.java, awaitItem()).form

                    assertEquals(CardDraftProblem.Blank, form.problems[CardField.TITLE], "问题该算出来")
                    assertNull(form.problemOf(CardField.TITLE), "但还不该显示")
                    assertFalse(form.canSave)
                    cancelAndIgnoreRemainingEvents()
                }
            }

        @Test
        @DisplayName("不合法时点保存 ⇒ 不写库，改成亮出红字")
        fun invalidSaveShowsProblemsInsteadOfWriting() =
            runTest {
                val viewModel = createViewModel()

                viewModel.state.test {
                    awaitItem()

                    viewModel.onSave()
                    advanceUntilIdle()

                    val form = assertInstanceOf(CardEditUiState.Editing::class.java, expectMostRecentItem()).form
                    assertEquals(CardDraftProblem.Blank, form.problemOf(CardField.TITLE))
                    assertTrue(repository.createdDrafts.isEmpty(), "不该写库")
                    cancelAndIgnoreRemainingEvents()
                }
            }

        /**
         * 两层校验（`core:model` 的长度 + `core:barcode` 的编码规则）都要接上。
         * 这条钉的是后者真的被调用了 —— 只接长度那一层的话它会静默通过。
         */
        @Test
        @DisplayName("码值的编码问题也拦得住保存，并带着原因")
        fun barcodePayloadProblemsBlockSavingToo() =
            runTest {
                val viewModel = createViewModel()

                viewModel.state.test {
                    awaitItem()

                    viewModel.onTitleChange("REWE")
                    // EAN-13 要 12 或 13 位，这里给 11 位。
                    viewModel.onBarcodeValueChange("40123456789")
                    advanceUntilIdle()

                    val form = assertInstanceOf(CardEditUiState.Editing::class.java, expectMostRecentItem()).form
                    assertEquals(BarcodePayloadProblem.WrongLength(listOf(12, 13)), form.payloadProblem)
                    assertFalse(form.canSave)
                    cancelAndIgnoreRemainingEvents()
                }
            }

        /** 改对之后红字要**立刻**消失，而不是等下一次点保存。 */
        @Test
        @DisplayName("改对之后问题立刻消失")
        fun fixingTheInputClearsTheProblem() =
            runTest {
                val viewModel = createViewModel()

                viewModel.state.test {
                    awaitItem()
                    viewModel.onSave() // 亮出红字
                    viewModel.onTitleChange("REWE")
                    viewModel.onBarcodeValueChange("4012345678901")
                    advanceUntilIdle()

                    val form = assertInstanceOf(CardEditUiState.Editing::class.java, expectMostRecentItem()).form
                    assertNull(form.problemOf(CardField.TITLE))
                    assertTrue(form.canSave)
                    cancelAndIgnoreRemainingEvents()
                }
            }
    }

    @Nested
    @DisplayName("保存")
    inner class Saving {
        @Test
        @DisplayName("新建：调 createCard，并发一次「保存好了」")
        fun createWritesAndEmitsSaved() =
            runTest {
                val viewModel = createViewModel()

                viewModel.savedEvents.test {
                    viewModel.onTitleChange("REWE Payback")
                    viewModel.onMerchantLabelChange("REWE")
                    viewModel.onBarcodeValueChange("4012345678901")
                    viewModel.onExpiresOnChange(LocalDate.of(2026, 12, 31))
                    viewModel.onSave()
                    advanceUntilIdle()

                    awaitItem()

                    val draft = repository.createdDrafts.single()
                    assertEquals("REWE Payback", draft.title)
                    assertEquals("REWE", draft.merchantLabel)
                    assertEquals("4012345678901", draft.barcodeValue)
                    assertEquals(LocalDate.of(2026, 12, 31), draft.expiresOn)
                    assertTrue(repository.updatedDrafts.isEmpty())
                    cancelAndIgnoreRemainingEvents()
                }
            }

        @Test
        @DisplayName("编辑：调 updateCard，带着那张卡的 id")
        fun editWritesThroughUpdate() =
            runTest {
                repository.signIn()
                repository.emit(CardFixtures.card(id = CARD_ID, title = "REWE Payback"))
                val viewModel = editViewModel()

                viewModel.savedEvents.test {
                    advanceUntilIdle()
                    viewModel.onTitleChange("REWE Payback (neu)")
                    viewModel.onSave()
                    advanceUntilIdle()

                    awaitItem()

                    val (id, draft) = repository.updatedDrafts.single()
                    assertEquals(CARD_ID, id)
                    assertEquals("REWE Payback (neu)", draft.title)
                    assertTrue(repository.createdDrafts.isEmpty())
                    cancelAndIgnoreRemainingEvents()
                }
            }

        /**
         * §4.3 铁律二下保存是本地写，很快 —— 但「很快」不是「原子」。
         * 连点两下会建出两张卡，而用户只想要一张。
         */
        @Test
        @DisplayName("连点两下只建一张卡")
        fun doubleTapCreatesOnlyOneCard() =
            runTest {
                val viewModel = createViewModel()

                viewModel.onTitleChange("REWE")
                viewModel.onBarcodeValueChange("4012345678901")

                viewModel.onSave()
                viewModel.onSave()
                advanceUntilIdle()

                assertEquals(1, repository.createdDrafts.size)
            }

        /**
         * 「保存好了」必须是一次性事件（Channel + receiveAsFlow），不是状态。
         * 放进 UiState 的话旋转屏幕会让它再触发一次 —— 用户会退两层。
         */
        @Test
        @DisplayName("「保存好了」只发一次")
        fun savedEventFiresExactlyOnce() =
            runTest {
                val viewModel = createViewModel()

                viewModel.savedEvents.test {
                    viewModel.onTitleChange("REWE")
                    viewModel.onBarcodeValueChange("4012345678901")
                    viewModel.onSave()
                    advanceUntilIdle()

                    awaitItem()
                    expectNoEvents()
                }
            }

        @Test
        @DisplayName("保存中禁用表单（isSaving）")
        fun formIsDisabledWhileSaving() =
            runTest {
                val viewModel = createViewModel()

                viewModel.state.test {
                    awaitItem()
                    viewModel.onTitleChange("REWE")
                    viewModel.onBarcodeValueChange("4012345678901")
                    viewModel.onSave()
                    advanceUntilIdle()

                    // 保存完了就该放开 —— 卡在 isSaving = true 上比不禁用更糟。
                    val latest = assertInstanceOf(CardEditUiState.Editing::class.java, expectMostRecentItem())
                    assertFalse(latest.isSaving)
                    cancelAndIgnoreRemainingEvents()
                }
            }
    }

    @Nested
    @DisplayName("卡没了 / 出错了")
    inner class Absent {
        /**
         * ⚠️ 「这张卡不在了」与「还不知道我是谁」是两件事（§16 R12）。
         * 没有宽限期的话，每一次冷启动的头几十毫秒都会误报一次 Missing ——
         * 而用户的卡明明还在。
         */
        @Test
        @DisplayName("用户还没解析出来时是 Loading，不是 Missing")
        fun unknownUserIsLoadingNotMissing() =
            runTest {
                editViewModel().state.test {
                    assertEquals(CardEditUiState.Loading, awaitItem())
                    expectNoEvents()
                    cancelAndIgnoreRemainingEvents()
                }
            }

        @Test
        @DisplayName("宽限期过了还是不知道我是谁 ⇒ Error")
        fun unresolvedUserBecomesAnError() =
            runTest {
                val viewModel = editViewModel()

                viewModel.state.test {
                    awaitItem()
                    advanceTimeBy(4.seconds)

                    assertEquals(
                        CardEditUiState.Error(CardEditError.CurrentUserUnknown),
                        expectMostRecentItem(),
                    )
                    cancelAndIgnoreRemainingEvents()
                }
            }

        /** 登录了、卡却不在 ⇒ 它被删了或不再共享给我。这是正常结局，不是错误。 */
        @Test
        @DisplayName("登录了但卡不在 ⇒ Missing")
        fun signedInButNoCardIsMissing() =
            runTest {
                repository.signIn()

                editViewModel().state.test {
                    awaitItem()
                    advanceUntilIdle()

                    assertEquals(CardEditUiState.Missing, expectMostRecentItem())
                    cancelAndIgnoreRemainingEvents()
                }
            }
    }

    @Nested
    @DisplayName("写回时的两条陷阱")
    inner class WriteBack {
        /**
         * `Card.color` 的注释点名的那个陷阱：一张 `mint_600` 的卡（新版本 App 建的）
         * 在本版本里解析成 BLUE。表单存枚举的话，光是打开再保存就把它改成了蓝色，
         * 而服务端没有任何机制会发现。
         */
        @Test
        @DisplayName("不认识的色键原样透传，不被打开表单这个动作改掉")
        fun unknownColourKeySurvivesOpeningTheForm() =
            runTest {
                repository.signIn()
                repository.emit(CardFixtures.card(id = CARD_ID).copy(colorWire = "mint_600"))
                val viewModel = editViewModel()

                viewModel.savedEvents.test {
                    advanceUntilIdle()
                    viewModel.onSave()
                    advanceUntilIdle()
                    awaitItem()

                    assertEquals(
                        "mint_600",
                        repository.updatedDrafts
                            .single()
                            .second.colorWire,
                    )
                    cancelAndIgnoreRemainingEvents()
                }
            }

        /**
         * 标题会 trim，**码值不会** —— 空格在 Code 39 / Code 128 里是合法载荷字符，
         * 而扫码枪读出来的就是那个字符串。修剪它会静默改掉用户的卡。
         */
        @Test
        @DisplayName("标题两端的空白去掉，码值原样保留")
        fun titleIsTrimmedButBarcodeValueIsNot() =
            runTest {
                val viewModel = createViewModel()

                viewModel.savedEvents.test {
                    viewModel.onTitleChange("  REWE  ")
                    viewModel.onBarcodeFormatChange(BarcodeFormat.CODE_128)
                    viewModel.onBarcodeValueChange(" 12 34 ")
                    viewModel.onSave()
                    advanceUntilIdle()
                    awaitItem()

                    val draft = repository.createdDrafts.single()
                    assertEquals("REWE", draft.title)
                    assertEquals(" 12 34 ", draft.barcodeValue)
                    cancelAndIgnoreRemainingEvents()
                }
            }
    }

    private companion object {
        const val CARD_ID = "0192f3a1-b2c3-7d4e-8f01-000000000001"
    }
}
