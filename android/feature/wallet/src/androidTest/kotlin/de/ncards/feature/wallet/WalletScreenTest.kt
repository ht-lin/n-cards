package de.ncards.feature.wallet

import androidx.activity.ComponentActivity
import androidx.compose.ui.test.assertIsDisplayed
import androidx.compose.ui.test.assertIsNotEnabled
import androidx.compose.ui.test.junit4.createAndroidComposeRule
import androidx.compose.ui.test.onNodeWithTag
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performScrollToIndex
import androidx.compose.ui.test.performTextInput
import androidx.test.ext.junit.runners.AndroidJUnit4
import de.ncards.core.designsystem.theme.NcardsTheme
import de.ncards.core.model.barcode.BarcodeFormat
import de.ncards.core.model.card.Card
import de.ncards.core.model.card.CardRole
import de.ncards.core.model.sync.SyncState
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith

/**
 * 钱包列表的 Compose UI 测试。
 *
 * ⚠️ **不引 `hilt-android-testing`**，ViewModel 手工构造 —— 照
 * `OnboardingJourneyTest` 的做法。理由在那个类的注释里：那样要多一个自定义
 * runner 与一批 `@HiltAndroidTest`，而换来的保证（图能装配起来）
 * `:app` 的编译期 Dagger 校验已经给了。
 *
 * ⚠️ 这些跑在 Gradle Managed Device 上（api 26 + 34），而 GMD **只在合入 main
 * 时跑** —— PR 流水线的预算是 12 分钟（§14.3）。本地：
 * `./gradlew :feature:wallet:connectedDebugAndroidTest`
 */
@RunWith(AndroidJUnit4::class)
class WalletScreenTest {
    @get:Rule
    val composeTestRule = createAndroidComposeRule<ComponentActivity>()

    private fun card(
        id: String,
        title: String,
        merchantLabel: String? = "REWE",
        syncState: SyncState = SyncState.SYNCED,
    ) = Card(
        id = id,
        ownerId = "owner",
        title = title,
        merchantLabel = merchantLabel,
        colorWire = "blue_600",
        barcodeFormat = BarcodeFormat.EAN_13,
        barcodeValue = "4012345678901",
        note = null,
        expiresOn = null,
        revision = 1,
        memberCount = 1,
        createdAt = 0,
        updatedAt = 0,
        role = CardRole.OWNER,
        sortOrder = 0,
        isPinned = false,
        syncState = syncState,
    )

    private fun setContent(state: WalletUiState) {
        composeTestRule.setContent {
            NcardsTheme(dynamicColor = false) {
                WalletScreen(
                    state = state,
                    onQueryChanged = {},
                    onClearQuery = {},
                    onCardClick = {},
                    onTogglePin = {},
                    onReorder = { _, _ -> },
                    onAddCard = {},
                )
            }
        }
    }

    @Test
    fun showsCards() {
        setContent(
            WalletUiState.Content(
                cards = listOf(card(id = "1", title = "REWE Payback")),
                query = "",
                totalCount = 1,
            ),
        )

        composeTestRule.onNodeWithTag(WALLET_LIST_TAG).assertIsDisplayed()
        composeTestRule.onNodeWithText("REWE Payback").assertIsDisplayed()
    }

    /**
     * §11.2：不以颜色作为唯一信息载体 —— 徽章必须带文字或图标。
     * 这条断言的是**文字真的在那里**，而不是只有一个彩色小点。
     */
    @Test
    fun syncBadgeCarriesText() {
        setContent(
            WalletUiState.Content(
                cards = listOf(card(id = "1", title = "REWE", syncState = SyncState.FAILED)),
                query = "",
                totalCount = 1,
            ),
        )

        composeTestRule
            .onNodeWithText(
                composeTestRule.activity.getString(R.string.wallet_badge_failed),
            ).assertIsDisplayed()
    }

    /** 一切正常时不该有徽章 —— 满屏绿勾会把真正要注意的那两张淹掉。 */
    @Test
    fun syncedCardHasNoBadge() {
        setContent(
            WalletUiState.Content(
                cards = listOf(card(id = "1", title = "REWE", syncState = SyncState.SYNCED)),
                query = "",
                totalCount = 1,
            ),
        )

        composeTestRule
            .onNodeWithText(composeTestRule.activity.getString(R.string.wallet_badge_pending))
            .assertDoesNotExist()
    }

    @Test
    fun emptyWalletShowsTheOnboardingCta() {
        setContent(WalletUiState.Empty)

        composeTestRule.onNodeWithTag(WALLET_EMPTY_TAG).assertIsDisplayed()
        // 录入三条路（T-155/156/157）都还不存在，所以按钮是禁用的。
        composeTestRule
            .onNodeWithText(composeTestRule.activity.getString(R.string.wallet_add_first_card))
            .assertIsNotEnabled()
    }

    /**
     * ⚠️⚠️ 本文件最重要的一条。
     *
     * 有卡但搜不到，与一张卡都没有，是**两屏不同的话**。
     * 塌陷成一个的话，一个有 40 张卡的用户搜错字母会被告知他没有卡。
     */
    @Test
    fun noSearchResultIsNotTheEmptyWallet() {
        setContent(WalletUiState.Content(cards = emptyList(), query = "xyz", totalCount = 40))

        composeTestRule.onNodeWithTag(WALLET_NO_RESULTS_TAG).assertIsDisplayed()
        composeTestRule.onNodeWithTag(WALLET_EMPTY_TAG).assertDoesNotExist()
        composeTestRule
            .onNodeWithText(composeTestRule.activity.getString(R.string.wallet_add_first_card))
            .assertDoesNotExist()
    }

    /** 空钱包不给搜索框 —— 那是在问用户「你想在空盒子里找什么」。 */
    @Test
    fun emptyWalletHasNoSearchField() {
        setContent(WalletUiState.Empty)

        composeTestRule.onNodeWithTag(WALLET_SEARCH_TAG).assertDoesNotExist()
    }

    @Test
    fun searchFieldAcceptsInput() {
        setContent(
            WalletUiState.Content(
                cards = listOf(card(id = "1", title = "REWE Payback")),
                query = "",
                totalCount = 1,
            ),
        )

        composeTestRule.onNodeWithTag(WALLET_SEARCH_TAG).performTextInput("rewe")
    }

    @Test
    fun errorStateIsShown() {
        setContent(WalletUiState.Error(WalletError.CurrentUserUnknown))

        composeTestRule.onNodeWithTag(WALLET_ERROR_TAG).assertIsDisplayed()
    }

    /**
     * §11.1 / 验收标准：德语长词 `Benachrichtigungseinstellungen` 不得截断。
     *
     * ⚠️ `assertIsDisplayed` 证不了「没被截断」—— 一个被 `Ellipsis` 截掉的
     * 文本节点仍然是 displayed，语义树里存的也还是完整字符串。
     * 真正能证伪截断的是那张长词预览（人眼 + 截图），这条守的是较弱的一半：
     * 这个标题**能被找到**，也就是它确实作为一个整体进了语义树。
     */
    @Test
    fun longGermanWordIsPresent() {
        setContent(
            WalletUiState.Content(
                cards =
                    listOf(
                        card(
                            id = "1",
                            title = "Benachrichtigungseinstellungen",
                            merchantLabel = null,
                        ),
                    ),
                query = "",
                totalCount = 1,
            ),
        )

        composeTestRule.onNodeWithText("Benachrichtigungseinstellungen").assertIsDisplayed()
    }

    /**
     * §9.1：「列表滚动无掉帧（P99 帧耗时 < 16.6 ms，**200 张卡**）」。
     *
     * ⚠️ **这条测不出 P99。** Compose UI Test 没有帧耗时的概念，能证的只有
     * 「200 张卡在真机上能渲染、能滚到底、最后一张确实在那里」——
     * 也就是那条预算的**正确性**那一半，不是**性能**那一半。
     *
     * 性能那一半归 T-453 的 Macrobenchmark（`:benchmark` 模块今天还是空壳，
     * 而 `ModuleGraph` 只允许它依赖 `:app`，刻意做成黑盒）。
     * 在那之前，P99 只能手工量并把机型与数字记进落地记录。
     *
     * 它仍然值得存在：`key` 或 `contentType` 被人改坏时，200 项的滚动
     * 是最容易先崩的地方，而这条会红。
     */
    @Test
    fun rendersAndScrollsTwoHundredCards() {
        val many = List(TWO_HUNDRED) { card(id = "c$it", title = "Karte $it") }

        setContent(WalletUiState.Content(cards = many, query = "", totalCount = many.size))

        composeTestRule
            .onNodeWithTag(WALLET_LIST_TAG)
            .performScrollToIndex(many.lastIndex)

        composeTestRule.onNodeWithText("Karte ${many.lastIndex}").assertIsDisplayed()
    }

    private companion object {
        const val TWO_HUNDRED = 200
    }
}
