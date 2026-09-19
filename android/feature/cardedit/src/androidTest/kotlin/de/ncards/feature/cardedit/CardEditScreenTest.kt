package de.ncards.feature.cardedit

import androidx.activity.ComponentActivity
import androidx.compose.ui.test.assertIsDisplayed
import androidx.compose.ui.test.assertIsEnabled
import androidx.compose.ui.test.assertIsNotEnabled
import androidx.compose.ui.test.junit4.createAndroidComposeRule
import androidx.compose.ui.test.onNodeWithContentDescription
import androidx.compose.ui.test.onNodeWithTag
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performTextInput
import androidx.test.ext.junit.runners.AndroidJUnit4
import de.ncards.core.barcode.format.BarcodePayloadProblem
import de.ncards.core.designsystem.theme.NcardsTheme
import de.ncards.core.model.barcode.BarcodeFormat
import de.ncards.core.model.card.CardColor
import de.ncards.core.model.card.CardDraftProblem
import de.ncards.core.model.card.CardField
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith

/**
 * 新增/编辑表单的 Compose UI 测试。
 *
 * ⚠️ **不引 `hilt-android-testing`**，直接驱动无状态的 [CardEditScreen] ——
 * 照 `WalletScreenTest` / `CardDetailScreenTest` 的先例（那样要多一个自定义
 * runner 与一批 `@HiltAndroidTest`，而换来的保证 `:app` 的编译期 Dagger 校验
 * 已经给了）。
 *
 * ⚠️ 仪器测试在 PR 上**不跑**（§14.3，只在合入 `main` 后跑 GMD），
 * 所以本地必须自己跑一遍 api26：`./gradlew :feature:cardedit:connectedDebugAndroidTest`。
 * T-154 的两条断言就是这么漏到 `main` 上的。
 */
@RunWith(AndroidJUnit4::class)
class CardEditScreenTest {
    @get:Rule
    val composeTestRule = createAndroidComposeRule<ComponentActivity>()

    @Test
    fun createModeShowsTheCreateTitleAndAnEmptyForm() {
        setContent(editing(CardEditMode.CREATE, form()))

        composeTestRule
            .onNodeWithText(composeTestRule.activity.getString(R.string.cardedit_title_create))
            .assertIsDisplayed()
        composeTestRule.onNodeWithTag(TITLE_TAG).assertIsDisplayed()
        composeTestRule.onNodeWithTag(COLOR_PICKER_TAG).assertIsDisplayed()
    }

    @Test
    fun editModeShowsTheEditTitle() {
        setContent(editing(CardEditMode.EDIT, form(title = "REWE Payback")))

        composeTestRule
            .onNodeWithText(composeTestRule.activity.getString(R.string.cardedit_title_edit))
            .assertIsDisplayed()
        composeTestRule.onNodeWithText("REWE Payback").assertIsDisplayed()
    }

    @Test
    fun typingInTheTitleReachesTheCallback() {
        var typed = ""
        setContent(editing(CardEditMode.CREATE, form()), onTitleChange = { typed = it })

        composeTestRule.onNodeWithTag(TITLE_TAG).performTextInput("REWE")

        assertEquals("REWE", typed)
    }

    /**
     * ⚠️ 保存按钮**永远可点**，不合法时点它亮出红字 —— 见 [CardEditScreen] 的类注释。
     * 禁用它的唯一情形是「正在保存」。
     */
    @Test
    fun saveStaysEnabledEvenWhenTheFormIsInvalid() {
        var saved = false
        setContent(
            editing(CardEditMode.CREATE, form(title = "", problems = mapOf(CardField.TITLE to CardDraftProblem.Blank))),
            onSave = { saved = true },
        )

        composeTestRule.onNodeWithTag(EDIT_SAVE_TAG).assertIsEnabled().performClick()

        assertTrue("不合法时也要接到 onSave 上（由 ViewModel 决定亮红字还是写库）", saved)
    }

    @Test
    fun savingDisablesTheSaveAction() {
        setContent(editing(CardEditMode.CREATE, form(), isSaving = true))

        composeTestRule.onNodeWithTag(EDIT_SAVE_TAG).assertIsNotEnabled()
    }

    /** 两层校验的红字要真的画出来，而且挂在各自的输入框下面。 */
    @Test
    fun problemsAreShownOnceShowProblemsIsOn() {
        setContent(
            editing(
                CardEditMode.CREATE,
                form(
                    title = "",
                    problems = mapOf(CardField.TITLE to CardDraftProblem.Blank),
                    payloadProblem = BarcodePayloadProblem.WrongLength(listOf(12, 13)),
                    showProblems = true,
                ),
            ),
        )

        composeTestRule
            .onNodeWithText(composeTestRule.activity.getString(R.string.cardedit_problem_blank))
            .assertIsDisplayed()
        composeTestRule
            .onNodeWithText(
                composeTestRule.activity.getString(
                    R.string.cardedit_payload_wrong_length,
                    composeTestRule.activity.getString(R.string.cardedit_length_or, 12, 13),
                ),
            ).assertIsDisplayed()
    }

    @Test
    fun problemsStayHiddenBeforeTheFirstSaveAttempt() {
        setContent(
            editing(
                CardEditMode.CREATE,
                form(title = "", problems = mapOf(CardField.TITLE to CardDraftProblem.Blank), showProblems = false),
            ),
        )

        composeTestRule
            .onNodeWithText(composeTestRule.activity.getString(R.string.cardedit_problem_blank))
            .assertDoesNotExist()
    }

    /**
     * ⚠️ §11.2：不以颜色作为唯一信息载体。每一格都要有名字，选中态要有
     * 颜色之外的第二信号。
     *
     * ⚠️ 语义与可点区域必须在**同一个节点**上 —— T-154 有两条仪器断言正是栽在
     * 「tag 在外、`contentDescription` 在内」这件事上（两个未合并的语义节点，
     * `fetchSemanticsNode()` 看不见）。这条断言同时守着那个形状。
     */
    @Test
    fun everySwatchCarriesItsColourName() {
        var picked: CardColor? = null
        setContent(editing(CardEditMode.CREATE, form()), onColorChange = { picked = it })

        val green = composeTestRule.activity.getString(R.string.cardedit_color_green)
        composeTestRule.onNodeWithContentDescription(green).assertIsDisplayed().performClick()

        assertEquals(CardColor.GREEN, picked)
    }

    /** 选中的那一格要在名字里说出来 —— 只靠边框的话 TalkBack 用户听不到。 */
    @Test
    fun theSelectedSwatchSaysSoInItsDescription() {
        setContent(editing(CardEditMode.CREATE, form(colorWire = CardColor.RED.wireName)))

        val red = composeTestRule.activity.getString(R.string.cardedit_color_red)
        composeTestRule
            .onNodeWithContentDescription(
                composeTestRule.activity.getString(R.string.cardedit_color_selected, red),
            ).assertIsDisplayed()
    }

    /**
     * 一个本版本不认识的色键（新版本 App 建的 `mint_600`）：**一格都不该高亮**。
     * 高亮蓝格等于告诉用户这张卡是蓝色的，而它不是 —— 见 `CardColor.fromWire` 的注释。
     */
    @Test
    fun anUnknownColourKeyHighlightsNothing() {
        setContent(editing(CardEditMode.EDIT, form(colorWire = "mint_600")))

        CardColor.entries.forEach { colour ->
            val name = composeTestRule.activity.getString(colour.nameRes)
            composeTestRule
                .onNodeWithContentDescription(
                    composeTestRule.activity.getString(R.string.cardedit_color_selected, name),
                ).assertDoesNotExist()
        }
    }

    @Test
    fun missingShowsItsOwnPaneNotAnError() {
        setContent(CardEditUiState.Missing)

        composeTestRule.onNodeWithTag(EDIT_MISSING_TAG).assertIsDisplayed()
        composeTestRule.onNodeWithTag(EDIT_ERROR_TAG).assertDoesNotExist()
        // Missing 时没什么可保存的。
        composeTestRule.onNodeWithTag(EDIT_SAVE_TAG).assertDoesNotExist()
    }

    @Test
    fun errorShowsTheErrorPane() {
        setContent(CardEditUiState.Error(CardEditError.CurrentUserUnknown))

        composeTestRule.onNodeWithTag(EDIT_ERROR_TAG).assertIsDisplayed()
        composeTestRule.onNodeWithTag(EDIT_MISSING_TAG).assertDoesNotExist()
    }

    // ---------------------------------------------------------------- 脚手架

    private fun editing(
        mode: CardEditMode,
        form: CardEditForm,
        isSaving: Boolean = false,
    ) = CardEditUiState.Editing(form = form, mode = mode, isSaving = isSaving)

    private fun form(
        title: String = "",
        colorWire: String = CardColor.DEFAULT.wireName,
        problems: Map<CardField, CardDraftProblem> = emptyMap(),
        payloadProblem: BarcodePayloadProblem? = null,
        showProblems: Boolean = false,
    ) = CardEditForm(
        title = title,
        merchantLabel = "",
        colorWire = colorWire,
        barcodeFormat = BarcodeFormat.EAN_13,
        barcodeValue = "",
        note = "",
        expiresOn = null,
        problems = problems,
        payloadProblem = payloadProblem,
        showProblems = showProblems,
    )

    private fun setContent(
        state: CardEditUiState,
        onTitleChange: (String) -> Unit = {},
        onColorChange: (CardColor) -> Unit = {},
        onSave: () -> Unit = {},
    ) {
        composeTestRule.setContent {
            NcardsTheme(dynamicColor = false) {
                CardEditScreen(
                    state = state,
                    onBack = {},
                    onTitleChange = onTitleChange,
                    onMerchantLabelChange = {},
                    onColorChange = onColorChange,
                    onBarcodeFormatChange = {},
                    onBarcodeValueChange = {},
                    onNoteChange = {},
                    onExpiresOnChange = {},
                    onSave = onSave,
                )
            }
        }
    }
}
