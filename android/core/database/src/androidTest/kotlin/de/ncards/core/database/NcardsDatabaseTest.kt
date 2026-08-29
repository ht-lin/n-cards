package de.ncards.core.database

import android.content.Context
import androidx.room.Room
import androidx.test.core.app.ApplicationProvider
import androidx.test.ext.junit.runners.AndroidJUnit4
import de.ncards.core.database.entity.CardEntity
import de.ncards.core.database.entity.CardMemberEntity
import de.ncards.core.database.entity.SyncMetaEntity
import de.ncards.core.database.entity.SyncOutboxEntity
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.runBlocking
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test
import org.junit.runner.RunWith

/**
 * 四张表的 DAO 行为。跑在 SQLCipher 上（内存库），不是 Room 的默认引擎 ——
 * 「SQL 在真正会跑它的引擎上是对的」也是这套测试的一部分。
 */
@RunWith(AndroidJUnit4::class)
class NcardsDatabaseTest {
    private lateinit var database: NcardsDatabase

    @Before
    fun setUp() {
        val context: Context = ApplicationProvider.getApplicationContext()
        database =
            Room
                .inMemoryDatabaseBuilder(context, NcardsDatabase::class.java)
                .openHelperFactory(SqlCipher.openHelperFactory(ByteArray(32) { 0x11 }))
                .build()
    }

    @After
    fun tearDown() = database.close()

    @Test
    fun roundTripsACard() =
        runBlocking {
            database.cardDao().insert(card(CARD_ID))

            val stored = database.cardDao().findCard(CARD_ID)

            assertEquals("Kundenkarte", stored?.title)
            assertEquals("4001234567890", stored?.barcodeValue)
        }

    /** 墓碑到了就物理删（§5.4.3），成员行由外键 CASCADE 一起走。 */
    @Test
    fun deletingACardCascadesToItsMembers() =
        runBlocking {
            database.cardDao().insert(card(CARD_ID))
            database.cardMemberDao().upsert(listOf(member(CARD_ID, OWNER_ID, "owner")))

            database.cardDao().deleteByIds(listOf(CARD_ID))

            assertEquals(0, database.cardDao().count())
            assertNull(database.cardMemberDao().findMember(CARD_ID, OWNER_ID))
        }

    /**
     * §5.2 / 威胁模型 T21 的那条不变量：**viewer 的本地库里，
     * 一张共享卡恰好有 2 行成员**（owner + 自己）。
     *
     * 这里断言的是数据形状，不是过滤逻辑 —— 保证它成立的是同步层的 audience
     * 裁剪（T-250）。这条测试是那个约定在数据库侧的锚点：哪天有人让第三行
     * 落进来了，它会在这里而不是在用户的隐私事故里被发现。
     */
    @Test
    fun viewerSeesExactlyOwnerAndSelf() =
        runBlocking {
            database.cardDao().insert(card(CARD_ID))
            database.cardMemberDao().upsert(
                listOf(
                    member(CARD_ID, OWNER_ID, "owner"),
                    member(CARD_ID, VIEWER_ID, "viewer"),
                ),
            )

            assertEquals(2, database.cardMemberDao().memberCount(CARD_ID))
        }

    @Test
    fun placementIsPerMember() =
        runBlocking {
            database.cardDao().insert(card(CARD_ID))
            database.cardMemberDao().upsert(
                listOf(
                    member(CARD_ID, OWNER_ID, "owner"),
                    member(CARD_ID, VIEWER_ID, "viewer"),
                ),
            )

            database.cardMemberDao().updatePlacement(CARD_ID, VIEWER_ID, sortOrder = 5, isPinned = true)

            val viewer = database.cardMemberDao().findMember(CARD_ID, VIEWER_ID)
            val owner = database.cardMemberDao().findMember(CARD_ID, OWNER_ID)
            assertEquals(5, viewer?.sortOrder)
            assertEquals(true, viewer?.isPinned)
            assertEquals("viewer 的置顶不得影响 owner —— sort_order/is_pinned 是成员私有的", false, owner?.isPinned)
        }

    @Test
    fun walletReportsSyncedWhenOutboxIsEmpty() =
        runBlocking {
            insertCardWithOwner()

            val wallet = database.cardDao().observeWallet(OWNER_ID, MAX_ATTEMPTS).first()

            assertEquals(1, wallet.size)
            assertEquals("SYNCED", wallet.single().syncState)
            assertEquals("owner", wallet.single().role)
        }

    @Test
    fun walletReportsPendingWhileOutboxHasAnEntry() =
        runBlocking {
            insertCardWithOwner()
            database.syncOutboxDao().insert(outboxEntry(attemptCount = 1))

            assertEquals(
                "PENDING",
                database
                    .cardDao()
                    .observeWallet(OWNER_ID, MAX_ATTEMPTS)
                    .first()
                    .single()
                    .syncState,
            )
        }

    @Test
    fun walletReportsFailedOnceAttemptsAreExhausted() =
        runBlocking {
            insertCardWithOwner()
            database.syncOutboxDao().insert(outboxEntry(attemptCount = MAX_ATTEMPTS))

            assertEquals(
                "FAILED",
                database
                    .cardDao()
                    .observeWallet(OWNER_ID, MAX_ATTEMPTS)
                    .first()
                    .single()
                    .syncState,
            )
        }

    /** 钱包只显示「我是成员」的卡 —— 不是成员的卡根本不该出现在列表里。 */
    @Test
    fun walletOnlyShowsCardsTheUserIsAMemberOf() =
        runBlocking {
            insertCardWithOwner()

            assertTrue(
                database
                    .cardDao()
                    .observeWallet(VIEWER_ID, MAX_ATTEMPTS)
                    .first()
                    .isEmpty(),
            )
        }

    @Test
    fun walletPutsPinnedCardsFirst() =
        runBlocking {
            val second = "01900000-0000-7000-8000-000000000002"
            database.cardDao().upsert(listOf(card(CARD_ID), card(second)))
            database.cardMemberDao().upsert(
                listOf(
                    member(CARD_ID, OWNER_ID, "owner", sortOrder = 0, isPinned = false),
                    member(second, OWNER_ID, "owner", sortOrder = 9, isPinned = true),
                ),
            )

            val wallet = database.cardDao().observeWallet(OWNER_ID, MAX_ATTEMPTS).first()

            assertEquals(listOf(second, CARD_ID), wallet.map { it.card.id })
        }

    @Test
    fun outboxFindsOnlyDueEntries() =
        runBlocking {
            database.syncOutboxDao().insert(outboxEntry(nextAttemptAt = 100))
            database.syncOutboxDao().insert(outboxEntry(nextAttemptAt = 5_000))

            val due = database.syncOutboxDao().findDue(now = 1_000, maxAttempts = MAX_ATTEMPTS, limit = 10)

            assertEquals(1, due.size)
            assertEquals(100, due.single().nextAttemptAt)
        }

    @Test
    fun outboxSkipsExhaustedEntries() =
        runBlocking {
            database.syncOutboxDao().insert(outboxEntry(attemptCount = MAX_ATTEMPTS))

            assertTrue(database.syncOutboxDao().findDue(Long.MAX_VALUE, MAX_ATTEMPTS, limit = 10).isEmpty())
            assertEquals(1, database.syncOutboxDao().observeFailedCount(MAX_ATTEMPTS).first())
        }

    @Test
    fun syncMetaStoresTheMergeBase() =
        runBlocking {
            database.syncMetaDao().upsert(
                listOf(SyncMetaEntity("card", CARD_ID, revision = 3, baseJson = """{"title":"alt"}""", syncedAt = 1)),
            )

            // 3L 而不是 3：findRevision 返回 Long?，可空就没有 assertEquals(long, long)
            // 重载可选，会退到 assertEquals(Object, Object) 去比 Integer 与 Long。
            assertEquals(3L, database.syncMetaDao().findRevision("card", CARD_ID))
            assertEquals("""{"title":"alt"}""", database.syncMetaDao().find("card", CARD_ID)?.baseJson)
        }

    /** 下行同步会重放（游标只在整批写成功后才推进），所以 upsert 必须幂等。 */
    @Test
    fun downstreamUpsertIsIdempotent() =
        runBlocking {
            val batch = listOf(card(CARD_ID))
            database.cardDao().upsert(batch)
            database.cardDao().upsert(batch)

            assertEquals(1, database.cardDao().count())
        }

    private suspend fun insertCardWithOwner() {
        database.cardDao().insert(card(CARD_ID))
        database.cardMemberDao().upsert(listOf(member(CARD_ID, OWNER_ID, "owner")))
    }

    private fun card(id: String) =
        CardEntity(
            id = id,
            ownerId = OWNER_ID,
            title = "Kundenkarte",
            merchantLabel = null,
            color = "blue_600",
            barcodeFormat = "EAN_13",
            barcodeValue = "4001234567890",
            note = null,
            expiresOn = null,
            revision = 1,
            memberCount = 1,
            createdAt = 0,
            updatedAt = 0,
        )

    private fun member(
        cardId: String,
        userId: String,
        role: String,
        sortOrder: Int = 0,
        isPinned: Boolean = false,
    ) = CardMemberEntity(
        cardId = cardId,
        userId = userId,
        role = role,
        sortOrder = sortOrder,
        isPinned = isPinned,
        addedBy = null,
        joinedAt = 0,
    )

    private fun outboxEntry(
        attemptCount: Int = 0,
        nextAttemptAt: Long = 0,
    ) = SyncOutboxEntity(
        entityType = "card",
        entityId = CARD_ID,
        op = "update",
        payloadJson = "{}",
        attemptCount = attemptCount,
        nextAttemptAt = nextAttemptAt,
        createdAt = 0,
    )

    private companion object {
        const val CARD_ID = "01900000-0000-7000-8000-000000000001"
        const val OWNER_ID = "01900000-0000-7000-8000-0000000000aa"
        const val VIEWER_ID = "01900000-0000-7000-8000-0000000000bb"

        /** §5.4.3：最多 10 次后标 FAILED。真正的退避曲线归 T-251。 */
        const val MAX_ATTEMPTS = 10
    }
}
