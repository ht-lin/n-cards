package de.ncards.data.card

import de.ncards.core.common.dispatcher.DispatcherProvider
import de.ncards.core.common.user.CurrentUserIdStore
import de.ncards.core.database.dao.CardDao
import de.ncards.core.database.dao.CardMemberDao
import de.ncards.core.database.dao.SyncOutboxDao
import de.ncards.core.model.card.Card
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.distinctUntilChanged
import kotlinx.coroutines.flow.flatMapLatest
import kotlinx.coroutines.flow.flowOf
import kotlinx.coroutines.flow.flowOn
import kotlinx.coroutines.flow.map
import kotlinx.coroutines.withContext
import javax.inject.Inject
import javax.inject.Singleton

/**
 * [CardRepository] 的实现。
 *
 * 三件事：把 Room 的投影映成领域模型、把「我是谁」接进查询、
 * 把 placement 的写入做成「先 Room 再 outbox」的一个原子步骤。
 */
@Singleton
internal class DefaultCardRepository
    @Inject
    constructor(
        private val transactions: TransactionRunner,
        private val cards: CardDao,
        private val members: CardMemberDao,
        private val outbox: SyncOutboxDao,
        private val currentUser: CurrentUserIdStore,
        private val dispatchers: DispatcherProvider,
    ) : CardRepository {
        /**
         * ⚠️ `flatMapLatest` 而不是 `combine`：用户换了（登出再登入）之后，
         * **上一个用户的那条 Room 订阅必须被取消**。`combine` 会让两条订阅都活着，
         * 而它们发的是两个人的卡。
         *
         * 没有 `distinctUntilChanged`：`StateFlow` 自带去重（conflate + equality），
         * 对它用那个操作符是空操作，Kotlin 1.9 起直接标了 `@Deprecated`。
         */
        @OptIn(ExperimentalCoroutinesApi::class)
        override fun observeWallet(): Flow<List<Card>> =
            currentUser.userId
                .flatMapLatest { userId ->
                    if (userId == null) {
                        // 还不知道我是谁。发空列表而不是挂住 —— 挂住的话
                        // UI 分不清「在加载」与「卡住了」。调用方用
                        // observeCurrentUserId() 区分「真的没有卡」与「还不知道」。
                        flowOf(emptyList())
                    } else {
                        cards
                            .observeWallet(userId, PROVISIONAL_MAX_SYNC_ATTEMPTS)
                            .map { rows -> rows.map { it.toCard() } }
                    }
                }.flowOn(dispatchers.io)

        /**
         * ⚠️ `distinctUntilChanged()` 在这里**不是**空操作 ——
         * 与 [observeWallet] 上那句注释（`StateFlow` 自带去重）说的不是一回事。
         *
         * 上游是钱包全表：钱包里**任何一张别的卡**变一个字段，它都会发一次，
         * 而本流的值一个字节都没变。不去重的话，用户拖动列表里另一张卡时，
         * 正开着的详情页会跟着重组一次。
         *
         * 没有 `flowOn`：[observeWallet] 自己已经带了 `flowOn(dispatchers.io)`，
         * 这里的 `firstOrNull` 是纯内存比较。
         */
        override fun observeCard(cardId: String): Flow<Card?> =
            observeWallet()
                .map { cards -> cards.firstOrNull { it.id == cardId } }
                .distinctUntilChanged()

        override fun observeCurrentUserId(): Flow<String?> = currentUser.userId

        override suspend fun setPinned(
            cardId: String,
            pinned: Boolean,
        ) {
            val userId = currentUser.userId.value ?: return

            withContext(dispatchers.io) {
                transactions.transaction {
                    val current = members.findMember(cardId, userId)

                    // 卡不在了（一次下行同步刚把它删了），或者本来就是这个状态。
                    // 后者不是「顺手优化」：不拦的话，用户每点一次已经置顶的卡
                    // 都会多一条 outbox 记录，而它们推上去全是 no-op。
                    if (current == null || current.isPinned == pinned) {
                        return@transaction
                    }

                    // ⚠️ sortOrder 原样传回去，不动它。查询是
                    // `ORDER BY is_pinned DESC, sort_order ASC` —— 两个维度正交。
                    // 把置顶做成「sort_order 改到最小」会让取消置顶后原位置永久丢失。
                    members.updatePlacement(cardId, userId, current.sortOrder, pinned)
                    enqueuePlacement(cardId, current.sortOrder, pinned)
                }
            }
        }

        /**
         * 受影响区间的**稠密**重排。
         *
         * 不用稀疏间隔（gap）方案：服务端的 `PUT /cards/{id}/placement` 对
         * `sort_order` **没有任何归一化契约**（契约里它就是一个 `integer`），
         * 所以稀疏值在多设备合流之后照样会撞，等于白付复杂度。
         * 而稠密重排在 §7.5 的 500 张卡上限下，最坏也就是几百行 UPDATE，
         * 一个事务就完了。
         *
         * 只重排 [cardId] 所在的那个区段（置顶的 / 非置顶的）：
         * 跨区段的拖拽由调用方先 [setPinned] 再排，否则一次拖拽要同时改两件事，
         * 而其中一件（置顶）用户并没有要求。
         */
        override suspend fun reorder(
            cardId: String,
            toIndex: Int,
        ) {
            val userId = currentUser.userId.value ?: return

            withContext(dispatchers.io) {
                transactions.transaction {
                    val moving = members.findMember(cardId, userId) ?: return@transaction

                    // 同一区段内、按当前可见顺序排好的那些卡。
                    //
                    // ⚠️ 在事务**里**读，不是在外面。从 Flow 上 first() 拿到的是
                    // 事务外的快照，两次读之间完全可能插进一次下行同步 ——
                    // 那样算出的 sort_order 是对着一份已经过期的顺序算的。
                    val segment =
                        cards
                            .walletSnapshot(userId, PROVISIONAL_MAX_SYNC_ATTEMPTS)
                            .filter { it.isPinned == moving.isPinned }
                            .map { it.card.id }

                    val reordered = segment.moved(cardId, toIndex) ?: return@transaction

                    // 只写真的变了的那些行。一次拖拽通常只动一小段，
                    // 而每一行都要写一条 outbox —— 全量写会让 T-251 推 500 次
                    // （而且其中绝大多数是把同一个值再写一遍）。
                    reordered.forEachIndexed { index, id ->
                        if (segment.getOrNull(index) == id) return@forEachIndexed

                        members.updatePlacement(id, userId, index, moving.isPinned)
                        enqueuePlacement(id, index, moving.isPinned)
                    }
                }
            }
        }

        /**
         * §4.3 铁律二：先写 Room（乐观更新），**再**入 outbox，由 SyncEngine 异步推送。
         *
         * ⚠️ 这里只**入队**。合并、指数退避、4xx 不重试、推送顺序 ——
         * 全归 T-251，`SyncOutboxDao` 的类注释点名禁止提前实现。
         * SyncEngine 要到 T-250（M2）才存在，所以现在写进去的行会一直堆着不发。
         * 那是对的，不是缺陷：离线优先的语义就是本地先成立、推送另说。
         *
         * ⚠️ `entity_type` 是 `card_member` 而不是 `card`。这不只是分类准确：
         * `CardDao.observeWallet` 派生 `sync_state` 时**只看 `entity_type = 'card'`**，
         * 所以一个待推送的 placement **不会**让卡显示待同步徽章 ——
         * 与「placement 不参与 revision」的语义一致（§5.2）。
         * 写成 `card` 的话，用户每置顶一次就会看到一个待同步角标，而那是假的。
         */
        private suspend fun enqueuePlacement(
            cardId: String,
            sortOrder: Int,
            isPinned: Boolean,
        ) {
            val now = System.currentTimeMillis()
            outbox.insert(
                SyncOutboxEntry.placement(
                    cardId = cardId,
                    sortOrder = sortOrder,
                    isPinned = isPinned,
                    now = now,
                ),
            )
        }

        private companion object {
            /**
             * 重试上限。§5.4.3 写的是 10 次。
             *
             * ⚠️ 这是一个**临时落点**，归属已经写在任务卡上：`CardDao.observeWallet`
             * 把它做成参数而不是写死在 SQL 里，注释写着「上限与退避曲线归 T-251，
             * 所以阈值从参数传进来」。T-251 建立真正的同步策略时，
             * 这个常量该被那边的配置取代，而不是在两处各留一个 10。
             *
             * ⚠️ 这里**刻意不留待办注释**。本仓库的债务跟踪是**任务卡**
             * （`docs/tasks/M2.md` 的 T-251），不是 issue 编号 —— 全仓库至今
             * 一条带编号的待办注释都没有，而 `check-todo-issue-refs.sh` 只校验
             * 格式、不校验那个编号存不存在。编一个号出来只会让它看起来有人跟进。
             *
             * 那个脚本自己的结语说的就是这件事：「不打算开 issue 的，就别留
             * 待办 —— 直接删掉，或者把结论写成一段说明为什么现在这样做的注释」。
             * 上面这几行就是那段注释。
             */
            const val PROVISIONAL_MAX_SYNC_ATTEMPTS = 10
        }
    }

/**
 * 把一个 id 从它当前的位置挪到 [toIndex]。
 *
 * 返回 `null` 表示「什么都不用做」：id 不在列表里，或者它已经在那个位置了。
 * 让调用方少写一个分支，也让「没变化就不该产生 outbox 行」成为结构性的。
 *
 * [toIndex] 会被夹到合法范围内 —— UI 传进来的是手指落点算出来的下标，
 * 而拖到列表底部之外是完全正常的手势。
 */
internal fun List<String>.moved(
    id: String,
    toIndex: Int,
): List<String>? {
    val from = indexOf(id)
    // 不在列表里（-1），或者夹进合法范围之后就是原位 —— 两种都是「什么都不用做」。
    val to = if (from < 0) from else toIndex.coerceIn(0, lastIndex)

    return if (from < 0 || from == to) {
        null
    } else {
        toMutableList().apply {
            removeAt(from)
            add(to, id)
        }
    }
}
