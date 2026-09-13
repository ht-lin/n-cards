package de.ncards.data.card

import app.cash.turbine.test
import de.ncards.core.testing.TestDispatcherProvider
import kotlinx.coroutines.test.runTest
import org.junit.jupiter.api.Assertions.assertEquals
import org.junit.jupiter.api.Assertions.assertNull
import org.junit.jupiter.api.Assertions.assertTrue
import org.junit.jupiter.api.DisplayName
import org.junit.jupiter.api.Nested
import org.junit.jupiter.api.Test

@DisplayName("CardRepository")
class DefaultCardRepositoryTest {
    private val transactions = FakeTransactionRunner()
    private val cards = FakeCardDao()
    private val members = FakeCardMemberDao()
    private val outbox = FakeSyncOutboxDao()
    private val currentUser = FakeCurrentUserIdStore()

    private val repository =
        DefaultCardRepository(
            transactions = transactions,
            cards = cards,
            members = members,
            outbox = outbox,
            currentUser = currentUser,
            dispatchers = TestDispatcherProvider(),
        )

    @Nested
    @DisplayName("读钱包")
    inner class Reading {
        /**
         * ⚠️ 冷启动的第一帧、以及未登录，都会走到这里。
         *
         * 发空列表而不是挂住：挂住的话 UI 分不清「在加载」与「卡住了」。
         * 「真的没有卡」与「还不知道你是谁」由 `observeCurrentUserId()` 区分。
         */
        @Test
        @DisplayName("还不知道我是谁时发空列表，不去查库")
        fun emptyWhenUserUnknown() =
            runTest {
                repository.observeWallet().test {
                    assertEquals(emptyList<Any>(), awaitItem())
                    cancelAndIgnoreRemainingEvents()
                }

                assertNull(cards.lastUserId, "不该拿一个 null 用户去查库")
            }

        @Test
        @DisplayName("登录后按当前用户查库，并把投影映成领域模型")
        fun mapsRowsForTheSignedInUser() =
            runTest {
                currentUser.signIn(WalletRows.USER_ID)
                cards.emit(listOf(WalletRows.row(id = "c1", title = "REWE Payback")))

                repository.observeWallet().test {
                    val wallet = awaitItem()

                    assertEquals(1, wallet.size)
                    assertEquals("REWE Payback", wallet.first().title)
                    cancelAndIgnoreRemainingEvents()
                }

                assertEquals(WalletRows.USER_ID, cards.lastUserId)
            }

        /**
         * §5.4.3 写的是 10 次。它由参数传进 SQL 而不是写死在里面 ——
         * `CardDao` 的注释把阈值的归属留给了 T-251。
         */
        @Test
        @DisplayName("重试上限作为参数传进查询，不写死在 SQL 里")
        fun passesTheRetryCeilingIntoTheQuery() =
            runTest {
                currentUser.signIn(WalletRows.USER_ID)

                repository.observeWallet().test {
                    awaitItem()
                    cancelAndIgnoreRemainingEvents()
                }

                assertEquals(10, cards.lastMaxAttempts)
            }

        /**
         * ⚠️⚠️ 换用户必须换订阅。
         *
         * 用 `combine` 而不是 `flatMapLatest` 的话，上一个用户那条 Room 订阅
         * 还活着，而它发的是**别人的卡**。登出不清本地库（同一个人重新登录
         * 不该重拉 200 张卡），所以那些行确实还在库里。
         */
        @Test
        @DisplayName("登出后回到空列表——上一个用户的订阅必须被取消")
        fun signingOutClearsTheWallet() =
            runTest {
                currentUser.signIn(WalletRows.USER_ID)
                cards.emit(listOf(WalletRows.row(id = "c1")))

                repository.observeWallet().test {
                    assertEquals(1, awaitItem().size)

                    currentUser.signOut()

                    assertEquals(emptyList<Any>(), awaitItem())
                    cancelAndIgnoreRemainingEvents()
                }
            }
    }

    @Nested
    @DisplayName("置顶")
    inner class Pinning {
        @Test
        @DisplayName("置顶不动 sort_order——两个维度正交")
        fun pinningLeavesSortOrderAlone() =
            runTest {
                currentUser.signIn(WalletRows.USER_ID)
                members.seed(WalletRows.member(cardId = "c1", sortOrder = 3, isPinned = false))

                repository.setPinned("c1", pinned = true)

                assertEquals(
                    listOf(FakeCardMemberDao.PlacementWrite("c1", sortOrder = 3, isPinned = true)),
                    members.placementWrites,
                )
            }

        /**
         * ⚠️ 这条守的是一个**用户可见的假信号**。
         *
         * `CardDao.observeWallet` 派生 `sync_state` 时只看 `entity_type = 'card'`。
         * 把 placement 写成 `card` 的话，用户每置顶一次就会在卡上看到一个
         * 「待同步」角标 —— 而 placement 是成员私有字段、不参与 revision（§5.2），
         * 它有没有推上去跟卡的同步状态无关。那个角标是假的。
         */
        @Test
        @DisplayName("入队的是 card_member 而不是 card——否则会冒出一个假的待同步徽章")
        fun enqueuesAsCardMember() =
            runTest {
                currentUser.signIn(WalletRows.USER_ID)
                members.seed(WalletRows.member(cardId = "c1", sortOrder = 3))

                repository.setPinned("c1", pinned = true)

                val entry = outbox.inserted.single()
                assertEquals("card_member", entry.entityType)
                assertEquals("c1", entry.entityId)
                assertEquals("update", entry.op)
            }

        /** 载荷会被 T-251 原样发给 `PUT /cards/{id}/placement`，字段名必须是 snake_case。 */
        @Test
        @DisplayName("载荷的形状照契约的 CardPlacement（snake_case）")
        fun payloadMatchesTheContract() =
            runTest {
                currentUser.signIn(WalletRows.USER_ID)
                members.seed(WalletRows.member(cardId = "c1", sortOrder = 3))

                repository.setPinned("c1", pinned = true)

                assertEquals(
                    """{"sort_order":3,"is_pinned":true}""",
                    outbox.inserted.single().payloadJson,
                )
            }

        @Test
        @DisplayName("状态没变就什么都不做——否则重复点会攒一堆 no-op 记录")
        fun noOpWhenAlreadyInThatState() =
            runTest {
                currentUser.signIn(WalletRows.USER_ID)
                members.seed(WalletRows.member(cardId = "c1", isPinned = true))

                repository.setPinned("c1", pinned = true)

                assertTrue(members.placementWrites.isEmpty())
                assertTrue(outbox.inserted.isEmpty())
            }

        @Test
        @DisplayName("卡已经不在了也不崩——下行同步可能刚把它删掉")
        fun missingCardIsIgnored() =
            runTest {
                currentUser.signIn(WalletRows.USER_ID)

                repository.setPinned("ghost", pinned = true)

                assertTrue(outbox.inserted.isEmpty())
            }

        @Test
        @DisplayName("还不知道我是谁时不写任何东西")
        fun doesNothingWhenUserUnknown() =
            runTest {
                members.seed(WalletRows.member(cardId = "c1"))

                repository.setPinned("c1", pinned = true)

                assertTrue(members.placementWrites.isEmpty())
                assertEquals(0, transactions.transactionCount)
            }
    }

    @Nested
    @DisplayName("拖拽重排")
    inner class Reordering {
        private fun seedSegment(count: Int) {
            currentUser.signIn(WalletRows.USER_ID)
            cards.emit(WalletRows.segment(count))
            members.seed(*Array(count) { WalletRows.member(cardId = "c$it", sortOrder = it) })
        }

        /**
         * 把第 0 张拖到第 2 位：c0 c1 c2 c3 → c1 c2 c0 c3。
         * 只有前三张的 `sort_order` 变了，c3 不该被碰。
         */
        @Test
        @DisplayName("稠密重排，且只写真的变了的那几行")
        fun densifiesOnlyTheAffectedRange() =
            runTest {
                seedSegment(count = 4)

                repository.reorder("c0", toIndex = 2)

                assertEquals(
                    listOf(
                        FakeCardMemberDao.PlacementWrite("c1", sortOrder = 0, isPinned = false),
                        FakeCardMemberDao.PlacementWrite("c2", sortOrder = 1, isPinned = false),
                        FakeCardMemberDao.PlacementWrite("c0", sortOrder = 2, isPinned = false),
                    ),
                    members.placementWrites,
                )
            }

        @Test
        @DisplayName("每一个被移动的行都入一条 outbox")
        fun enqueuesOnePerMovedRow() =
            runTest {
                seedSegment(count = 4)

                repository.reorder("c0", toIndex = 2)

                assertEquals(listOf("c1", "c2", "c0"), outbox.inserted.map { it.entityId })
                assertTrue(outbox.inserted.all { it.entityType == "card_member" })
            }

        @Test
        @DisplayName("往回拖也对")
        fun movesBackwards() =
            runTest {
                seedSegment(count = 4)

                repository.reorder("c3", toIndex = 1)

                assertEquals(
                    listOf(
                        FakeCardMemberDao.PlacementWrite("c3", sortOrder = 1, isPinned = false),
                        FakeCardMemberDao.PlacementWrite("c1", sortOrder = 2, isPinned = false),
                        FakeCardMemberDao.PlacementWrite("c2", sortOrder = 3, isPinned = false),
                    ),
                    members.placementWrites,
                )
            }

        @Test
        @DisplayName("拖回原位什么都不写")
        fun sameIndexIsANoOp() =
            runTest {
                seedSegment(count = 4)

                repository.reorder("c1", toIndex = 1)

                assertTrue(members.placementWrites.isEmpty())
                assertTrue(outbox.inserted.isEmpty())
            }

        /** UI 传进来的是手指落点算出的下标，拖到列表外是完全正常的手势。 */
        @Test
        @DisplayName("越界的下标被夹到合法范围，不抛异常")
        fun clampsOutOfBoundsIndex() =
            runTest {
                seedSegment(count = 3)

                repository.reorder("c0", toIndex = 99)

                assertEquals(
                    listOf("c1", "c2", "c0"),
                    members.placementWrites.sortedBy { it.sortOrder }.map { it.cardId },
                )
            }

        /**
         * ⚠️ 置顶与非置顶是两个独立区段（查询先按 `is_pinned DESC` 排）。
         *
         * 不按区段隔离的话，拖动一张非置顶卡会把置顶卡的 `sort_order` 一起重算 ——
         * 用户会看到自己置顶那一区的顺序莫名其妙变了。
         */
        @Test
        @DisplayName("只重排同一区段，置顶的那几张不受影响")
        fun reordersWithinTheSegmentOnly() =
            runTest {
                currentUser.signIn(WalletRows.USER_ID)
                cards.emit(
                    listOf(
                        WalletRows.row(id = "p0", sortOrder = 0, isPinned = true),
                        WalletRows.row(id = "p1", sortOrder = 1, isPinned = true),
                        WalletRows.row(id = "c0", sortOrder = 0, isPinned = false),
                        WalletRows.row(id = "c1", sortOrder = 1, isPinned = false),
                    ),
                )
                members.seed(
                    WalletRows.member(cardId = "p0", sortOrder = 0, isPinned = true),
                    WalletRows.member(cardId = "p1", sortOrder = 1, isPinned = true),
                    WalletRows.member(cardId = "c0", sortOrder = 0),
                    WalletRows.member(cardId = "c1", sortOrder = 1),
                )

                repository.reorder("c0", toIndex = 1)

                assertEquals(
                    listOf("c1", "c0"),
                    members.placementWrites.map { it.cardId },
                    "置顶区的 p0 / p1 不该被碰",
                )
                assertTrue(members.placementWrites.none { it.isPinned })
            }

        @Test
        @DisplayName("整个重排在一个事务里")
        fun runsInASingleTransaction() =
            runTest {
                seedSegment(count = 4)

                repository.reorder("c0", toIndex = 2)

                assertEquals(1, transactions.transactionCount)
            }
    }

    @Nested
    @DisplayName("moved 辅助函数")
    inner class Moved {
        @Test
        @DisplayName("不在列表里返回 null")
        fun unknownIdYieldsNull() {
            assertNull(listOf("a", "b").moved("z", 0))
        }

        @Test
        @DisplayName("没挪动返回 null——「没变化就不该产生 outbox 行」是结构性的")
        fun sameIndexYieldsNull() {
            assertNull(listOf("a", "b").moved("a", 0))
        }

        @Test
        @DisplayName("单元素列表不崩")
        fun singleElementIsSafe() {
            assertNull(listOf("a").moved("a", 5))
        }
    }
}
