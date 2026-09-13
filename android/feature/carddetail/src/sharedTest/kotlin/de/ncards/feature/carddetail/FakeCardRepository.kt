package de.ncards.feature.carddetail

import de.ncards.core.model.card.Card
import de.ncards.data.card.CardRepository
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.distinctUntilChanged
import kotlinx.coroutines.flow.map

/**
 * [CardRepository] 的内存替身。
 *
 * ⚠️ 它住在 `src/sharedTest`，`test` 与 `androidTest` **共用这一份** ——
 * 两个源集互相看不见，而 ViewModel 单测与 Compose UI 测试必须对着同一批假卡
 * 说话。接法见本模块 `build.gradle.kts` 的注释。
 *
 * ============================================================================
 * ⚠️ 这是全仓的**第二份** `FakeCardRepository`，而且它**不能**下沉到 `core:testing`
 * ============================================================================
 * `feature:wallet` 有一份同名的。把它挪进 `core:testing` 是结构性不可行的：
 * 那个模块要因此依赖 `:data:card`，而 `ModuleGraph` 的 `":core:" to listOf(":core:")`
 * 会在**配置期**直接把构建打红。
 *
 * 所以现在是两份。T-155 会让它变成第三份，而那时的出路不是第四份，是这两条之一：
 * 给 `:data:card` 开 `java-test-fixtures`，或者照 `core:testing` 的做法把假替身
 * 放进 `data:card/src/main`（它已经这么对待 `CardFixtures` 了）。
 * 写在这里，免得下一个人重新发现一遍。
 */
class FakeCardRepository : CardRepository {
    private val cards = MutableStateFlow<List<Card>>(emptyList())
    private val currentUserId = MutableStateFlow<String?>(null)

    fun emit(value: List<Card>) {
        cards.value = value
    }

    fun emit(card: Card) {
        cards.value = listOf(card)
    }

    fun signIn(userId: String = DEFAULT_USER_ID) {
        currentUserId.value = userId
    }

    fun signOut() {
        currentUserId.value = null
    }

    override fun observeWallet(): Flow<List<Card>> = cards

    override fun observeCard(cardId: String): Flow<Card?> =
        cards
            .map { list -> list.firstOrNull { it.id == cardId } }
            .distinctUntilChanged()

    override fun observeCurrentUserId(): Flow<String?> = currentUserId

    override suspend fun setPinned(
        cardId: String,
        pinned: Boolean,
    ) = Unit

    override suspend fun reorder(
        cardId: String,
        toIndex: Int,
    ) = Unit

    private companion object {
        const val DEFAULT_USER_ID = "0192f3a1-b2c3-7d4e-8f01-00000000a11a"
    }
}
