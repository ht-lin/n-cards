package de.ncards.feature.cardedit

import de.ncards.core.model.navigation.CardEditRoute
import org.junit.jupiter.api.Assertions.assertEquals
import org.junit.jupiter.api.DisplayName
import org.junit.jupiter.api.Test

/**
 * `SavedStateHandle` 的 key 与 [CardEditRoute] 的属性名必须逐字相同。
 *
 * ============================================================================
 * 为什么这条值得一个自己的测试文件
 * ============================================================================
 * 这是本模块唯一一处**纯约定**的耦合：navigation-compose 按属性名把参数种进
 * `SavedStateHandle`，而 [CardEditViewModel] 按 [CardEditViewModel.CARD_ID_KEY]
 * 取。两者对不上的症状特别恶劣 —— ViewModel 取不到 id ⇒ 判定成**新建模式** ⇒
 * 用户点「编辑」，改完保存，**得到的是一张新卡，原来那张纹丝不动**。
 * 没有崩溃、没有报错，而且列表里真的多了一张卡，看起来像是用户自己点错了。
 *
 * ⚠️ 与 `CardDetailViewModel` 那边不同，这里**不能**用 `checkNotNull` 兜底：
 * 在那边缺 key 是装配错误（两个宿主都必须种它），在这里「没有 id」是一种
 * **合法模式**（`CardCreateRoute` 本来就没有参数）。所以那边靠一次响亮的崩溃
 * 守着，这边只能靠这条断言。
 *
 * 它跑在纯 JVM 上，不需要设备 —— 而 §14.3 规定仪器测试只在合入 main 后跑。
 * 与 `CardDetailViewModel` 的 `CardIdKeyTest` 是同一个处置。
 */
@DisplayName("编辑模式的 cardId key")
class CardEditIdKeyTest {
    @Test
    @DisplayName("与 CardEditRoute 的属性名逐字相同")
    fun keyMatchesTheRoutePropertyName() {
        assertEquals(CardEditRoute::cardId.name, CardEditViewModel.CARD_ID_KEY)
    }

    /**
     * 属性引用写对了，值也得是那个字面量 —— 两条一起断言才能挡住
     * 「把属性名改成 `id` 于是两边一起变了、但导航参数其实还是 cardId」这种情况。
     */
    @Test
    @DisplayName("值就是 cardId")
    fun keyIsCardId() {
        assertEquals("cardId", CardEditViewModel.CARD_ID_KEY)
    }
}
