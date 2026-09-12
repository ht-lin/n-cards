package de.ncards.core.testing

import de.ncards.core.model.barcode.BarcodeFormat
import de.ncards.core.model.card.Card
import de.ncards.core.model.card.CardRole
import de.ncards.core.model.sync.SyncState

/**
 * 卡片的假数据。形状照契约的 `Card` example（`docs/api/openapi.yaml`）。
 *
 * ⚠️ 这里造的是 `core:model` 的 [Card]（领域模型），**不是** `core:database` 的
 * `WalletCard`（查询投影）。后者是 Room 的东西，造它要 `core:database`，
 * 而 §12.3 不让 `core:testing` 去依赖一个具体的持久化实现 ——
 * 需要 `WalletCard` 的测试只有 `data:card` 自己，它在自己的 test 源集里造。
 */
object CardFixtures {
    const val OWNER_ID = "0192f3a1-b2c3-7d4e-8f01-00000000a11a"

    /**
     * 一张默认可用的卡。每个字段都能覆盖，因为不同的用例关心不同的字段 ——
     * 一个「什么都能改」的工厂比五个专用工厂好维护。
     */
    @Suppress("LongParameterList")
    fun card(
        id: String = "0192f3a1-b2c3-7d4e-8f01-23456789abcd",
        title: String = "REWE Payback",
        merchantLabel: String? = "REWE",
        colorWire: String = "blue_600",
        barcodeFormat: BarcodeFormat = BarcodeFormat.EAN_13,
        barcodeValue: String = "4012345678901",
        role: CardRole = CardRole.OWNER,
        sortOrder: Int = 0,
        isPinned: Boolean = false,
        syncState: SyncState = SyncState.SYNCED,
        ownerId: String = OWNER_ID,
    ): Card =
        Card(
            id = id,
            ownerId = ownerId,
            title = title,
            merchantLabel = merchantLabel,
            colorWire = colorWire,
            barcodeFormat = barcodeFormat,
            barcodeValue = barcodeValue,
            note = null,
            expiresOn = null,
            revision = 1,
            // ⚠️ 只有 owner 拿得到值，viewer 恒为 null（§5.2 / 威胁模型 T21）。
            // 默认值跟着 role 走，免得用例造出一张「viewer 却知道成员数」的卡 ——
            // 那种卡在真实数据里不存在，拿它做断言会得到一个假的绿。
            memberCount = if (role == CardRole.OWNER) 1 else null,
            createdAt = 0,
            updatedAt = 0,
            role = role,
            sortOrder = sortOrder,
            isPinned = isPinned,
            syncState = syncState,
        )

    /**
     * N 张卡，`sort_order` 依次递增。
     *
     * 给两类用例用：重排算法（要一串已知顺序的卡），
     * 以及 §9.1 的 200 张卡滚动预算（`count = 200`）。
     */
    fun cards(count: Int): List<Card> =
        List(count) { index ->
            card(
                id = "0192f3a1-b2c3-7d4e-8f01-%012d".format(index),
                title = "Karte $index",
                sortOrder = index,
            )
        }
}
