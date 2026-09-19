package de.ncards.data.card

import app.cash.turbine.test
import de.ncards.core.model.barcode.BarcodeFormat
import de.ncards.core.model.card.CardDraft
import de.ncards.core.model.id.IdGenerator
import de.ncards.core.testing.TestDispatcherProvider
import kotlinx.coroutines.test.runTest
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonNull
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import org.junit.jupiter.api.Assertions.assertEquals
import org.junit.jupiter.api.Assertions.assertFalse
import org.junit.jupiter.api.Assertions.assertNull
import org.junit.jupiter.api.Assertions.assertTrue
import org.junit.jupiter.api.DisplayName
import org.junit.jupiter.api.Nested
import org.junit.jupiter.api.Test
import java.time.LocalDate

@DisplayName("CardRepository")
class DefaultCardRepositoryTest {
    private val transactions = FakeTransactionRunner()
    private val cards = FakeCardDao()
    private val members = FakeCardMemberDao()
    private val outbox = FakeSyncOutboxDao()
    private val currentUser = FakeCurrentUserIdStore()

    /**
     * 固定 id 的生成器。
     *
     * 真生成一个 UUIDv7 的话，「写进 Room 的 id 与写进 outbox 载荷的 id 是同一个」
     * 就只能断言成「两个都非空」—— 而那恰好漏掉了真正会发生的那个 bug
     * （两处各调一次 `newId()`）。UUIDv7 本身的正确性归 `UuidV7GeneratorTest`。
     */
    private val idGenerator = IdGenerator { NEW_ID }

    private val repository =
        DefaultCardRepository(
            transactions = transactions,
            cards = cards,
            members = members,
            outbox = outbox,
            currentUser = currentUser,
            dispatchers = TestDispatcherProvider(),
            idGenerator = idGenerator,
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
    @DisplayName("读单卡（T-154 的详情页与全屏条码页）")
    inner class ReadingOne {
        @Test
        @DisplayName("钱包里有这张卡就发它")
        fun emitsTheMatchingCard() =
            runTest {
                currentUser.signIn(WalletRows.USER_ID)
                cards.emit(
                    listOf(
                        WalletRows.row(id = "c1", title = "REWE Payback"),
                        WalletRows.row(id = "c2", title = "DM Payback"),
                    ),
                )

                repository.observeCard("c2").test {
                    assertEquals("DM Payback", awaitItem()?.title)
                    cancelAndIgnoreRemainingEvents()
                }
            }

        @Test
        @DisplayName("不在我的钱包里就发 null——这不是错误")
        fun emitsNullWhenTheCardIsNotMine() =
            runTest {
                currentUser.signIn(WalletRows.USER_ID)
                cards.emit(listOf(WalletRows.row(id = "c1")))

                repository.observeCard("fremde-karte").test {
                    assertNull(awaitItem())
                    cancelAndIgnoreRemainingEvents()
                }
            }

        /**
         * ⚠️ 详情页**正开着**的时候，一次下行同步可能把这张卡删掉
         * （owner 删卡、我被移出成员、解除好友）。§16 R12 点名这是用户最容易
         * 当成 Bug 的一类事件，所以它必须是一个能到达的状态，而不是一条空流。
         */
        @Test
        @DisplayName("卡被墓碑删掉后发一次 null")
        fun emitsNullWhenTheCardDisappears() =
            runTest {
                currentUser.signIn(WalletRows.USER_ID)
                cards.emit(listOf(WalletRows.row(id = "c1")))

                repository.observeCard("c1").test {
                    assertEquals("c1", awaitItem()?.id)

                    cards.emit(emptyList())

                    assertNull(awaitItem())
                    cancelAndIgnoreRemainingEvents()
                }
            }

        @Test
        @DisplayName("还不知道我是谁时发 null，不去查库")
        fun emitsNullWhenUserUnknown() =
            runTest {
                repository.observeCard("c1").test {
                    assertNull(awaitItem())
                    cancelAndIgnoreRemainingEvents()
                }

                assertNull(cards.lastUserId, "不该拿一个 null 用户去查库")
            }

        /**
         * ⚠️ 这一条守的就是 `distinctUntilChanged()`。
         *
         * 上游是**钱包全表**：别的卡动一下（拖拽重排、置顶）它就发一次，
         * 而本流的值一个字节都没变。去掉那个操作符的话，用户在列表里拖动
         * 另一张卡时，正开着的详情页会跟着重组。
         */
        @Test
        @DisplayName("别的卡变化时不重复发值")
        fun doesNotReEmitWhenAnotherCardChanges() =
            runTest {
                currentUser.signIn(WalletRows.USER_ID)
                cards.emit(
                    listOf(
                        WalletRows.row(id = "c1", title = "REWE Payback"),
                        WalletRows.row(id = "c2", title = "DM Payback"),
                    ),
                )

                repository.observeCard("c1").test {
                    assertEquals("REWE Payback", awaitItem()?.title)

                    cards.emit(
                        listOf(
                            WalletRows.row(id = "c1", title = "REWE Payback"),
                            WalletRows.row(id = "c2", title = "DM Payback (umbenannt)"),
                        ),
                    )

                    expectNoEvents()
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

    @Nested
    @DisplayName("建卡（§4.3 铁律二 + J4）")
    inner class Creating {
        /**
         * ⚠️ **本文件最重要的一条。** 它是 J4 验收标准的可执行形态：
         * 「飞行模式下新增卡立即可见且带待同步徽章」。
         *
         * 三行缺一不可，而每一行漏掉的症状都不一样：
         * - 少了 `cards` 那行 —— 什么都没有。
         * - 少了 `card_members` 那行 —— **卡存了但钱包里看不见**（那段 SQL 是
         *   `INNER JOIN card_members`）。这条最隐蔽：Room 与 outbox 都对。
         * - 少了 outbox 那行 —— 卡出现了但**徽章不亮**，而 J4 要的就是那个徽章。
         */
        @Test
        @DisplayName("一个事务里写三行：cards、owner 成员、outbox")
        fun writesAllThreeRowsInOneTransaction() =
            runTest {
                currentUser.signIn(WalletRows.USER_ID)

                val id = repository.createCard(draft())

                assertEquals(NEW_ID, id, "返回的该是客户端生成的那个 id")
                assertEquals(1, cards.inserted.size)
                assertEquals(1, members.upserted.size)
                assertEquals(1, outbox.inserted.size)
                assertEquals(1, transactions.transactionCount, "三行必须在同一个事务里")
            }

        /**
         * `CardDao.observeWallet` 派生 `sync_state` 的子查询里写死了
         * `WHERE entity_type = 'card'` —— 写成 `card_member` 的话卡会出现、
         * 徽章不亮，而两者在 code review 里长得一模一样。
         */
        @Test
        @DisplayName("outbox 的 entity_type 是 card —— 徽章全靠这一个字符串")
        fun outboxRowIsTypedAsCardSoTheBadgeLightsUp() =
            runTest {
                currentUser.signIn(WalletRows.USER_ID)

                repository.createCard(draft())

                val entry = outbox.inserted.single()
                assertEquals("card", entry.entityType)
                assertEquals("create", entry.op)
                assertEquals(NEW_ID, entry.entityId)
            }

        @Test
        @DisplayName("成员行是 owner，且 sort_order = 0（排序靠 created_at DESC）")
        fun ownerMemberRowIsWritten() =
            runTest {
                currentUser.signIn(WalletRows.USER_ID)

                repository.createCard(draft())

                val member = members.upserted.single()
                assertEquals(NEW_ID, member.cardId)
                assertEquals(WalletRows.USER_ID, member.userId)
                assertEquals("owner", member.role)
                assertEquals(0, member.sortOrder)
                assertFalse(member.isPinned)
            }

        /**
         * 两处各调一次 `newId()` 是个真会发生的 bug，而它的后果是
         * 「本地这张卡永远推不上去」——outbox 那条指着一个不存在的实体。
         */
        @Test
        @DisplayName("Room 里那行的 id 与 outbox 载荷里的 id 是同一个")
        fun theIdIsGeneratedExactlyOnce() =
            runTest {
                currentUser.signIn(WalletRows.USER_ID)

                repository.createCard(draft())

                val payload = Json.parseToJsonElement(outbox.inserted.single().payloadJson).jsonObject
                assertEquals(cards.inserted.single().id, payload["id"]?.jsonPrimitive?.content)
            }

        @Test
        @DisplayName("载荷的键名是契约的 snake_case，expires_on 是 ISO 日期串不是 epoch day")
        fun payloadMatchesTheContract() =
            runTest {
                currentUser.signIn(WalletRows.USER_ID)

                repository.createCard(draft(expiresOn = LocalDate.of(2026, 12, 31)))

                val payload = Json.parseToJsonElement(outbox.inserted.single().payloadJson).jsonObject
                assertEquals(
                    setOf(
                        "id",
                        "title",
                        "merchant_label",
                        "color",
                        "barcode_format",
                        "barcode_value",
                        "note",
                        "expires_on",
                    ),
                    payload.keys,
                )
                assertEquals("2026-12-31", payload["expires_on"]?.jsonPrimitive?.content)
                // 库里那一列是 epoch day，两种表示都要对。
                assertEquals(LocalDate.of(2026, 12, 31).toEpochDay(), cards.inserted.single().expiresOn)
            }

        /** 冷启动时令牌要过一趟 Keystore，那一帧是真实存在的。 */
        @Test
        @DisplayName("还不知道我是谁时什么都不写，返回 null")
        fun writesNothingWhenUserUnknown() =
            runTest {
                assertNull(repository.createCard(draft()))

                assertTrue(cards.inserted.isEmpty())
                assertTrue(outbox.inserted.isEmpty())
            }
    }

    @Nested
    @DisplayName("改卡")
    inner class Updating {
        /**
         * `CardUpdate` 是 `minProperties: 1`、全部字段可选，语义是
         * 「键不出现 = 别动」。把没改的字段一起发出去，会覆盖掉别的设备刚改的值。
         */
        @Test
        @DisplayName("载荷里只有变了的那个键")
        fun payloadCarriesOnlyTheChangedFields() =
            runTest {
                currentUser.signIn(WalletRows.USER_ID)
                cards.seed(WalletRows.entity(id = "c1", title = "REWE Payback"))

                repository.updateCard("c1", draft(title = "REWE Payback (neu)"))

                val payload = Json.parseToJsonElement(outbox.inserted.single().payloadJson).jsonObject
                assertEquals(setOf("title"), payload.keys)
                assertEquals("REWE Payback (neu)", payload["title"]?.jsonPrimitive?.content)
            }

        /**
         * 不拦的话，用户点开表单又原样保存也会让卡冒出一个待同步徽章 —— 而那是假的。
         * 与 `setPinned` 里「本来就是这个状态」那条守卫同一条理由。
         */
        @Test
        @DisplayName("什么都没变时一行都不写")
        fun noOpChangeWritesNothing() =
            runTest {
                currentUser.signIn(WalletRows.USER_ID)
                cards.seed(WalletRows.entity(id = "c1"))

                repository.updateCard("c1", draft())

                assertTrue(cards.upserted.isEmpty(), "Room 不该被碰")
                assertTrue(outbox.inserted.isEmpty(), "不该冒出一个假徽章")
            }

        /**
         * `revision` 是服务端的乐观锁，T-251 推送时拿它做 `If-Match`。
         * 本机递增就等于发出一个服务端从未见过的版本号 —— 一定 409，
         * 而且要等离线攒了一堆改动之后才炸。
         */
        @Test
        @DisplayName("不在本机递增 revision，也不动 created_at")
        fun revisionAndCreatedAtSurviveAnEdit() =
            runTest {
                currentUser.signIn(WalletRows.USER_ID)
                val before = WalletRows.entity(id = "c1").copy(revision = 7, createdAt = 111)
                cards.seed(before)

                repository.updateCard("c1", draft(title = "neu"))

                val after = cards.upserted.single()
                assertEquals(7, after.revision, "revision 归服务端")
                assertEquals(111, after.createdAt, "created_at 是钱包排序的第三个键")
            }

        /**
         * `Card.color` 的注释点名的那个陷阱：一个装了新版本 App 的设备发来
         * `mint_600`，本版本解析成 BLUE。编辑时若把**枚举**写回去，
         * 那张卡的颜色就被老客户端静默改成了蓝色，而服务端不会发现。
         */
        @Test
        @DisplayName("本版本不认识的色键原样透传，不被改写成默认色")
        fun unknownColorKeySurvivesAnEdit() =
            runTest {
                currentUser.signIn(WalletRows.USER_ID)
                cards.seed(WalletRows.entity(id = "c1", color = "mint_600"))

                repository.updateCard("c1", draft(colorWire = "mint_600", title = "neu"))

                assertEquals("mint_600", cards.upserted.single().color)
                val payload = Json.parseToJsonElement(outbox.inserted.single().payloadJson).jsonObject
                assertEquals(setOf("title"), payload.keys, "颜色没变就不该出现在载荷里")
            }

        /**
         * 契约里「键不出现」是「别动」，「键是 null」是「清空」。
         * 表单清空一个可选字段产出的是空串，所以写库前归一成 null ——
         * 两边用的必须是同一个归一函数，否则每次保存都会产生一条 outbox 记录。
         */
        @Test
        @DisplayName("清空商家名发 null（不是空串），而空串与 null 之间不算变化")
        fun clearingAnOptionalFieldSendsExplicitNull() =
            runTest {
                currentUser.signIn(WalletRows.USER_ID)
                cards.seed(WalletRows.entity(id = "c1", merchantLabel = "REWE"))

                repository.updateCard("c1", draft(merchantLabel = ""))

                val payload = Json.parseToJsonElement(outbox.inserted.single().payloadJson).jsonObject
                assertEquals(setOf("merchant_label"), payload.keys)
                assertTrue(payload["merchant_label"] is JsonNull, "清空要发显式 null")
                assertNull(cards.upserted.single().merchantLabel)

                // 再保存一次：库里已经是 null，表单仍是空串 —— 不该再算一次变化。
                outbox.inserted.clear()
                repository.updateCard("c1", draft(merchantLabel = ""))
                assertTrue(outbox.inserted.isEmpty(), "空串与 null 归一后相等")
            }

        @Test
        @DisplayName("卡不在了就静默返回")
        fun missingCardIsSilent() =
            runTest {
                currentUser.signIn(WalletRows.USER_ID)

                repository.updateCard("weg", draft())

                assertTrue(outbox.inserted.isEmpty())
            }
    }

    private fun draft(
        title: String = "REWE Payback",
        merchantLabel: String? = "REWE",
        colorWire: String = "blue_600",
        barcodeValue: String = "4012345678901",
        note: String? = null,
        expiresOn: LocalDate? = null,
    ) = CardDraft(
        title = title,
        merchantLabel = merchantLabel,
        colorWire = colorWire,
        barcodeFormat = BarcodeFormat.EAN_13,
        barcodeValue = barcodeValue,
        note = note,
        expiresOn = expiresOn,
    )

    private companion object {
        const val NEW_ID = "0192f3a1-b2c3-7d4e-8f01-0000000000ff"
    }
}
