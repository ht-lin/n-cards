package de.ncards.data.card

import de.ncards.core.common.user.CurrentUserIdStore
import de.ncards.core.database.dao.CardDao
import de.ncards.core.database.dao.CardMemberDao
import de.ncards.core.database.dao.SyncOutboxDao
import de.ncards.core.database.entity.CardEntity
import de.ncards.core.database.entity.CardMemberEntity
import de.ncards.core.database.entity.SyncOutboxEntity
import de.ncards.core.database.model.WalletCard
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow

/*
 * `data:card` 的测试替身。
 *
 * ⚠️ 它们**不在** `:core:testing` 里，而 `CardFixtures` 在。分界是：
 * `core:testing` 造的是 `core:model` 的领域对象（谁都用得上），
 * 这里造的是 Room 的 entity 与 DAO —— 那需要 `core:database`，
 * 而让 `core:testing` 依赖一个具体的持久化实现会把它变成半个 data 层。
 * 需要这些的只有本模块。
 */

/** 直接执行 block。见 [TransactionRunner] 的类注释：它证不了回滚，证的是编排。 */
internal class FakeTransactionRunner : TransactionRunner {
    var transactionCount = 0
        private set

    override suspend fun <T> transaction(block: suspend () -> T): T {
        transactionCount++
        return block()
    }
}

internal class FakeCurrentUserIdStore(
    initial: String? = null,
) : CurrentUserIdStore {
    private val _userId = MutableStateFlow(initial)
    override val userId: StateFlow<String?> = _userId.asStateFlow()

    fun signIn(id: String) {
        _userId.value = id
    }

    fun signOut() {
        _userId.value = null
    }
}

/**
 * `CardDao` 的替身。
 *
 * ⚠️ 它**不重现** `observeWallet` 的 SQL（那两个 JOIN 与派生 `sync_state` 的
 * 子查询）。重现一遍 SQL 的替身只能证明「我照着 SQL 又写了一遍」——
 * 真正该守着那段 SQL 的是 `core:database` 的仪器测试，那里有真库。
 *
 * 这里的替身只回答「给它什么它就发什么」，于是本模块的测试能专注在
 * **映射**与**编排**上，也就是 `data:card` 真正拥有的那两件事。
 */
internal class FakeCardDao : CardDao {
    private val wallet = MutableStateFlow<List<WalletCard>>(emptyList())

    /** 最近一次 `observeWallet` / `walletSnapshot` 收到的参数，给断言用。 */
    var lastUserId: String? = null
        private set
    var lastMaxAttempts: Int? = null
        private set

    fun emit(rows: List<WalletCard>) {
        wallet.value = rows
    }

    override fun observeWallet(
        userId: String,
        maxAttempts: Int,
    ): Flow<List<WalletCard>> {
        lastUserId = userId
        lastMaxAttempts = maxAttempts
        return wallet
    }

    override suspend fun walletSnapshot(
        userId: String,
        maxAttempts: Int,
    ): List<WalletCard> {
        lastUserId = userId
        lastMaxAttempts = maxAttempts
        return wallet.value
    }

    override fun observeCard(cardId: String): Flow<CardEntity?> = throw NotImplementedError("T-154")

    override suspend fun findCard(cardId: String): CardEntity? = throw NotImplementedError("T-154")

    override suspend fun upsert(cards: List<CardEntity>) = throw NotImplementedError("T-250")

    override suspend fun insert(card: CardEntity) = throw NotImplementedError("T-155")

    override suspend fun deleteByIds(cardIds: List<String>) = throw NotImplementedError("T-250")

    override suspend fun count(): Int = wallet.value.size
}

internal class FakeCardMemberDao : CardMemberDao {
    private val rows = mutableMapOf<Pair<String, String>, CardMemberEntity>()

    /** 每一次 `updatePlacement` 的参数，按调用顺序。重排的断言全靠它。 */
    val placementWrites = mutableListOf<PlacementWrite>()

    fun seed(vararg members: CardMemberEntity) {
        members.forEach { rows[it.cardId to it.userId] = it }
    }

    override suspend fun findMember(
        cardId: String,
        userId: String,
    ): CardMemberEntity? = rows[cardId to userId]

    override suspend fun updatePlacement(
        cardId: String,
        userId: String,
        sortOrder: Int,
        isPinned: Boolean,
    ) {
        placementWrites += PlacementWrite(cardId, sortOrder, isPinned)
        rows[cardId to userId]?.let {
            rows[cardId to userId] = it.copy(sortOrder = sortOrder, isPinned = isPinned)
        }
    }

    override fun observeMembers(cardId: String): Flow<List<CardMemberEntity>> = throw NotImplementedError("M3")

    override suspend fun upsert(members: List<CardMemberEntity>) = throw NotImplementedError("T-250")

    override suspend fun deleteMember(
        cardId: String,
        userId: String,
    ) = throw NotImplementedError("M3")

    override suspend fun memberCount(cardId: String): Int = rows.keys.count { it.first == cardId }

    data class PlacementWrite(
        val cardId: String,
        val sortOrder: Int,
        val isPinned: Boolean,
    )
}

internal class FakeSyncOutboxDao : SyncOutboxDao {
    val inserted = mutableListOf<SyncOutboxEntity>()

    override suspend fun insert(entry: SyncOutboxEntity): Long {
        inserted += entry
        return inserted.size.toLong()
    }

    override suspend fun findDue(
        now: Long,
        maxAttempts: Int,
        limit: Int,
    ): List<SyncOutboxEntity> = throw NotImplementedError("T-251")

    override suspend fun findFor(
        entityType: String,
        entityId: String,
    ): List<SyncOutboxEntity> = inserted.filter { it.entityType == entityType && it.entityId == entityId }

    override fun observeFailedCount(maxAttempts: Int): Flow<Int> = throw NotImplementedError("T-251")

    override suspend fun update(entry: SyncOutboxEntity) = throw NotImplementedError("T-251")

    override suspend fun deleteById(id: Long) = throw NotImplementedError("T-251")

    override suspend fun deleteFor(
        entityType: String,
        entityId: String,
    ) = throw NotImplementedError("T-251")

    override suspend fun count(): Int = inserted.size
}

// ---------------------------------------------------------------- 假数据

internal object WalletRows {
    const val USER_ID = "0192f3a1-b2c3-7d4e-8f01-00000000a11a"

    fun entity(
        id: String,
        title: String = "REWE Payback",
        color: String = "blue_600",
        barcodeFormat: String = "EAN_13",
        expiresOn: Long? = null,
        merchantLabel: String? = "REWE",
    ) = CardEntity(
        id = id,
        ownerId = USER_ID,
        title = title,
        merchantLabel = merchantLabel,
        color = color,
        barcodeFormat = barcodeFormat,
        barcodeValue = "4012345678901",
        note = null,
        expiresOn = expiresOn,
        revision = 1,
        memberCount = 1,
        createdAt = 0,
        updatedAt = 0,
    )

    fun member(
        cardId: String,
        sortOrder: Int = 0,
        isPinned: Boolean = false,
        role: String = "owner",
        userId: String = USER_ID,
    ) = CardMemberEntity(
        cardId = cardId,
        userId = userId,
        role = role,
        sortOrder = sortOrder,
        isPinned = isPinned,
        addedBy = null,
        joinedAt = 0,
    )

    fun row(
        id: String,
        title: String = "REWE Payback",
        sortOrder: Int = 0,
        isPinned: Boolean = false,
        role: String = "owner",
        syncState: String = "SYNCED",
        color: String = "blue_600",
        barcodeFormat: String = "EAN_13",
        expiresOn: Long? = null,
        merchantLabel: String? = "REWE",
    ) = WalletCard(
        card =
            entity(
                id = id,
                title = title,
                color = color,
                barcodeFormat = barcodeFormat,
                expiresOn = expiresOn,
                merchantLabel = merchantLabel,
            ),
        sortOrder = sortOrder,
        isPinned = isPinned,
        role = role,
        syncState = syncState,
    )

    /** 一个区段里 n 张卡，id 是 `c0`…`c{n-1}`，`sort_order` 依次 0…n-1。 */
    fun segment(
        count: Int,
        isPinned: Boolean = false,
    ): List<WalletCard> = List(count) { row(id = "c$it", sortOrder = it, isPinned = isPinned) }
}
