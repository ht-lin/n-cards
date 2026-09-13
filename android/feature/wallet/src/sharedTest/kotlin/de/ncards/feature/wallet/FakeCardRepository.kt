package de.ncards.feature.wallet

import de.ncards.core.model.card.Card
import de.ncards.data.card.CardRepository
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.MutableStateFlow

/**
 * [CardRepository] 的内存替身。
 *
 * ⚠️ 它住在 `src/sharedTest`，`test` 与 `androidTest` **共用这一份** ——
 * 两个源集互相看不见，而 ViewModel 单测与 Compose UI 测试必须对着同一批假卡
 * 说话，否则它们会各自漂到一个只有自己成立的世界里。
 * 接法见本模块 `build.gradle.kts` 的注释（`core:barcode` 验过的那条路）。
 *
 * 它**不模拟** Room 的排序：`observeWallet` 发什么就是什么。
 * 排序语义（`is_pinned DESC, sort_order ASC`）归那段 SQL，
 * 而守着它的是 `core:database` 的仪器测试 —— 在这里再实现一遍，
 * 只能证明「我照着 SQL 又写了一遍」。
 */
class FakeCardRepository : CardRepository {
    private val cards = MutableStateFlow<List<Card>>(emptyList())
    private val currentUserId = MutableStateFlow<String?>(null)

    /** 每一次 `setPinned` 的参数，按调用顺序。 */
    val pinCalls = mutableListOf<Pair<String, Boolean>>()

    /** 每一次 `reorder` 的参数，按调用顺序。 */
    val reorderCalls = mutableListOf<Pair<String, Int>>()

    fun emit(value: List<Card>) {
        cards.value = value
    }

    fun signIn(userId: String = "0192f3a1-b2c3-7d4e-8f01-00000000a11a") {
        currentUserId.value = userId
    }

    fun signOut() {
        currentUserId.value = null
    }

    override fun observeWallet(): Flow<List<Card>> = cards

    override fun observeCurrentUserId(): Flow<String?> = currentUserId

    override suspend fun setPinned(
        cardId: String,
        pinned: Boolean,
    ) {
        pinCalls += cardId to pinned
    }

    override suspend fun reorder(
        cardId: String,
        toIndex: Int,
    ) {
        reorderCalls += cardId to toIndex
    }
}
