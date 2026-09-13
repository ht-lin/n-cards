package de.ncards.feature.carddetail

import de.ncards.core.model.navigation.CardDetailRoute
import org.junit.jupiter.api.Assertions.assertEquals
import org.junit.jupiter.api.DisplayName
import org.junit.jupiter.api.Test

/**
 * [CardDetailViewModel] 有两个宿主，而它们种 `SavedStateHandle` 的方式不同：
 * NavHost 由**路由参数**种（key = 路由的属性名），`FullscreenBarcodeActivity`
 * 由 **Intent extra** 种。两边靠同一个常量对上。
 *
 * ⚠️ 这个耦合**在编译期看不见**：`CardDetailRoute::cardId.name` 会跟着属性名走，
 * 但如果有人把属性改名，导航那一半仍然对（类型安全路由自己跟着改），
 * 而 Activity 那一半会静默错位 —— 除非 `putExtra` 也用这个常量。
 * 它确实用了，本测试钉住的是**常量本身没有被换成字面量**。
 *
 * 这条测试跑在纯 JVM 上，所以它在 PR 流水线里就跑；导航那一半的真实行为
 * 要到 GMD（合入 main 后）与真机走一遍「钱包 → 详情 → 全屏」才算验过。
 */
@DisplayName("cardId 的 SavedStateHandle key")
class CardIdKeyTest {
    @Test
    @DisplayName("key 就是路由的属性名")
    fun keyMatchesTheRouteProperty() {
        assertEquals(CardDetailRoute::cardId.name, CardDetailViewModel.CARD_ID_KEY)
    }

    /**
     * 类型安全路由把每个属性按**属性名**放进 `NavBackStackEntry.arguments`，
     * 而 `hiltViewModel()` 用那个 bundle 种 `SavedStateHandle`。
     * 所以这个字面量就是运行时真正用到的那个 key —— 写死在这里，
     * 是为了让「有人重命名了属性」这件事在**单测**里就现形，
     * 而不是等到有人在真机上点开一张卡。
     */
    @Test
    @DisplayName("key 的字面值是 cardId")
    fun keyIsCardId() {
        assertEquals("cardId", CardDetailViewModel.CARD_ID_KEY)
    }
}
