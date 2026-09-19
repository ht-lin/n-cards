package de.ncards.feature.carddetail

import androidx.activity.ComponentActivity
import androidx.compose.ui.test.assertIsDisplayed
import androidx.compose.ui.test.assertIsEnabled
import androidx.compose.ui.test.junit4.createAndroidComposeRule
import androidx.compose.ui.test.onNodeWithTag
import androidx.compose.ui.test.performClick
import androidx.test.ext.junit.runners.AndroidJUnit4
import de.ncards.core.designsystem.theme.NcardsTheme
import de.ncards.core.model.card.CardRole
import de.ncards.core.testing.CardFixtures
import org.junit.Assert.assertTrue
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith

/**
 * 卡详情页的 Compose UI 测试。
 *
 * ⚠️ **不引 `hilt-android-testing`**，直接调无状态的 `CardDetailScreen` ——
 * 照 `WalletScreenTest` 与 `OnboardingJourneyTest` 的做法。理由在那两个类的
 * 注释里：那样要多一个自定义 runner 与一批 `@HiltAndroidTest`，
 * 而换来的保证（图能装配起来）`:app` 的编译期 Dagger 校验已经给了。
 *
 * ⚠️ 这些跑在 Gradle Managed Device 上（api 26 + 34），而 GMD **只在合入 main
 * 时跑** —— PR 流水线的预算是 12 分钟（§14.3）。本地：
 * `./gradlew :feature:carddetail:connectedDebugAndroidTest`
 */
@RunWith(AndroidJUnit4::class)
class CardDetailScreenTest {
    @get:Rule
    val composeTestRule = createAndroidComposeRule<ComponentActivity>()

    private fun setContent(
        state: CardDetailUiState,
        onEdit: () -> Unit = {},
    ) {
        composeTestRule.setContent {
            NcardsTheme(dynamicColor = false) {
                CardDetailScreen(
                    state = state,
                    onBack = {},
                    onShowBarcode = {},
                    onEdit = onEdit,
                )
            }
        }
    }

    @Test
    fun ownerSeesTheBarcodeAction() {
        setContent(CardDetailUiState.Content(CardFixtures.card()))

        composeTestRule.onNodeWithTag(DETAIL_SHOW_BARCODE_TAG).assertIsDisplayed()
        composeTestRule.onNodeWithTag(DETAIL_VALUE_TAG).assertIsDisplayed()
    }

    /**
     * T-155 接上了编辑表单，所以这个按钮从「画出来但按不动」变成了真的能按。
     *
     * ⚠️ 断言到 `onEdit` 真的被调用，而不只是 `assertIsEnabled()` ——
     * 后者在 `onClick = {}` 被误删的情况下仍然是绿的。
     */
    @Test
    fun ownerEditActionIsEnabledAndInvokesTheCallback() {
        var edited = false
        setContent(CardDetailUiState.Content(CardFixtures.card(role = CardRole.OWNER)), onEdit = { edited = true })

        composeTestRule.onNodeWithTag(DETAIL_EDIT_TAG).assertIsEnabled().performClick()

        assertTrue("owner 的「编辑」必须接到 onEdit 上（T-155）", edited)
    }

    /**
     * ⚠️ **这一条是 §7.3 / §4.4 的那句话本身**：「viewer 的共享卡在 UI 层**即无**
     * 编辑/删除入口（不是『点了才报错』）」。
     *
     * 所以断言是 `assertDoesNotExist` 而不是 `assertIsNotEnabled` ——
     * 一个禁用的编辑按钮仍然是在告诉 viewer「这张卡本来可以编辑」。
     */
    @Test
    fun viewerHasNoEditAction() {
        setContent(CardDetailUiState.Content(CardFixtures.card(role = CardRole.VIEWER)))

        composeTestRule.onNodeWithTag(DETAIL_EDIT_TAG).assertDoesNotExist()
    }

    /**
     * ⚠️ `memberCount` 对 viewer 恒为 `null`，而契约原文是「它是 `null` 时
     * **不得**推断任何默认值」。画成「共享给 0 人」等于告诉 viewer
     * 这张卡没被共享给别人 —— 成员数量本身就是信息（威胁模型 T21）。
     */
    @Test
    fun viewerSeesNoMemberCount() {
        setContent(CardDetailUiState.Content(CardFixtures.card(role = CardRole.VIEWER)))

        composeTestRule.onNodeWithTag(DETAIL_SHARED_TAG).assertDoesNotExist()
    }

    @Test
    fun ownerSeesMemberCount() {
        setContent(CardDetailUiState.Content(CardFixtures.card(memberCount = 3)))

        composeTestRule.onNodeWithTag(DETAIL_SHARED_TAG).assertIsDisplayed()
    }

    /** 卡被删掉是**正常结局**，走 Missing 那一格而不是 Error。 */
    @Test
    fun missingCardShowsItsOwnPane() {
        setContent(CardDetailUiState.Missing)

        composeTestRule.onNodeWithTag(DETAIL_MISSING_TAG).assertIsDisplayed()
        composeTestRule.onNodeWithTag(DETAIL_ERROR_TAG).assertDoesNotExist()
    }

    @Test
    fun errorShowsTheErrorPane() {
        setContent(CardDetailUiState.Error(CardDetailError.CurrentUserUnknown))

        composeTestRule.onNodeWithTag(DETAIL_ERROR_TAG).assertIsDisplayed()
        composeTestRule.onNodeWithTag(DETAIL_MISSING_TAG).assertDoesNotExist()
    }

    /**
     * 德语长词 + 备注 + 过期日期一起铺开。
     *
     * ⚠️ 与 `WalletScreenTest.longGermanWordIsPresent` 同一个诚实的边界：
     * `assertIsDisplayed` **证不了**「没被截断」—— 一个被省略号截掉的文本节点
     * 仍然是 displayed，语义树里存的也还是完整字符串。真正防截断的是
     * 「不设固定高度 + 可滚动」，而那由 `@Preview(fontScale = 2f)` 用眼睛看。
     */
    @Test
    fun rendersLongGermanContent() {
        setContent(
            CardDetailUiState.Content(
                CardFixtures.card(
                    title = "Benachrichtigungseinstellungen",
                    note = "Benachrichtigungseinstellungen für die Kundenkarte",
                ),
            ),
        )

        composeTestRule.onNodeWithTag(DETAIL_CONTENT_TAG).assertIsDisplayed()
    }
}
