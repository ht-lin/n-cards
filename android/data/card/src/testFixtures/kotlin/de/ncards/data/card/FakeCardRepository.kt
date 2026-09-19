package de.ncards.data.card

import de.ncards.core.model.card.Card
import de.ncards.core.model.card.CardDraft
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.distinctUntilChanged
import kotlinx.coroutines.flow.map

/**
 * [CardRepository] 的内存替身 —— **全仓唯一的一份**（T-155）。
 *
 * ============================================================================
 * 为什么它住在 `:data:card` 的 testFixtures 里
 * ============================================================================
 * T-154 交接时它有**两份**（`feature:wallet` 与 `feature:carddetail` 各一份的
 * `src/sharedTest` 拷贝），并且那份注释点名说本卡会让它变成第三份，出路是两条之一：
 * 给 `:data:card` 开 test fixtures，或者照 `core:testing` 对 `CardFixtures` 的做法
 * 把替身放进 `src/main`。本卡走第一条。
 *
 * 换来的性质正是三份拷贝做不到的：**替身与 [CardRepository] 同模块，
 * 接口加方法时替身当场编译失败**，而不是三个 feature 各自漂到一个只有自己成立的世界里。
 * 本卡就在给那个接口加 [createCard] / [updateCard] 两个方法 —— 这条性质当场兑现。
 *
 * 它下沉不到 `core:testing`：那要让 `:core:` 依赖 `:data:`，而 `ModuleGraph` 的
 * `":core:" to listOf(":core:")` 会在**配置期**把构建打红。
 *
 * ============================================================================
 * ⚠️ 它**不模拟** Room 的排序
 * ============================================================================
 * [observeWallet] 发什么就是什么。排序语义（`is_pinned DESC, sort_order ASC,
 * created_at DESC`）归 `CardDao` 那段 SQL，守着它的是 `core:database` 的仪器测试 ——
 * 在这里再实现一遍，只能证明「我照着 SQL 又写了一遍」。
 *
 * 同理它也**不模拟**「先 Room 再 outbox」：那是 [DefaultCardRepository] 的事，
 * 由 `DefaultCardRepositoryTest` 对着假 DAO 验。本替身只记录调用，供 ViewModel 断言。
 */
class FakeCardRepository : CardRepository {
    private val cards = MutableStateFlow<List<Card>>(emptyList())
    private val currentUserId = MutableStateFlow<String?>(null)

    /** 每一次 [setPinned] 的参数，按调用顺序。 */
    val pinCalls = mutableListOf<Pair<String, Boolean>>()

    /** 每一次 [reorder] 的参数，按调用顺序。 */
    val reorderCalls = mutableListOf<Pair<String, Int>>()

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

    /** 与真实现同构：在钱包之上挑一张，挑不到就是 `null`（T-154）。 */
    override fun observeCard(cardId: String): Flow<Card?> =
        cards
            .map { list -> list.firstOrNull { it.id == cardId } }
            .distinctUntilChanged()

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

    /** 每一次 [createCard] 的 draft，按调用顺序。 */
    val createdDrafts = mutableListOf<CardDraft>()

    /** 每一次 [updateCard] 的参数，按调用顺序。 */
    val updatedDrafts = mutableListOf<Pair<String, CardDraft>>()

    /**
     * 让 [createCard] 返回 `null`，也就是「当前用户还不知道是谁」。
     *
     * 那是一条 ViewModel 必须处理的真实路径（冷启动时令牌要过一趟 Keystore），
     * 而它在真实现里由 `currentUser.userId.value ?: return null` 产生 ——
     * 替身这边没有别的办法触发它。
     */
    var createReturnsNull: Boolean = false

    override suspend fun createCard(draft: CardDraft): String? {
        createdDrafts += draft

        return if (createReturnsNull) null else NEW_CARD_ID
    }

    override suspend fun updateCard(
        cardId: String,
        draft: CardDraft,
    ) {
        updatedDrafts += cardId to draft
    }

    companion object {
        const val DEFAULT_USER_ID = "0192f3a1-b2c3-7d4e-8f01-00000000a11a"

        /** [createCard] 返回的那个 id。是常量而不是真生成一个，测试才好断言。 */
        const val NEW_CARD_ID = "0192f3a1-b2c3-7d4e-8f01-0000000000ff"
    }
}
