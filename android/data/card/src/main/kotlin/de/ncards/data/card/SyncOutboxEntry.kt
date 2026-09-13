package de.ncards.data.card

import de.ncards.core.database.entity.SyncOutboxEntity
import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable
import kotlinx.serialization.encodeToString
import kotlinx.serialization.json.Json

/**
 * 把一次 placement 修改包成一条 outbox 记录。
 *
 * ⚠️ 本文件**只负责入队**。合并（同 `entity_id` 的 pending 记录只留最新）、
 * 指数退避、4xx 不重试、先 `card` 后 `card_member` 的推送顺序 ——
 * 全归 T-251，`SyncOutboxDao` 的类注释点名禁止提前实现。
 */
internal object SyncOutboxEntry {
    /**
     * ⚠️ `entity_type` 是 `card_member`，不是 `card`。
     *
     * 这不只是分类准确的问题：`CardDao.observeWallet` 派生 `sync_state` 时
     * **只看 `entity_type = 'card'`**。写成 `card` 的话，用户每置顶一次就会在卡上
     * 看到一个「待同步」角标 —— 而 placement 是成员私有字段、不参与 revision
     * （§5.2），它有没有推上去跟卡的同步状态无关。那个角标会是假的。
     *
     * `op` 恒为 `update`：placement 那一行在建卡时就随 owner 成员一起存在了
     * （T-110：「建卡时**同时**写入一行 `card_members(role='owner')`」），
     * 从来不需要 create。
     */
    fun placement(
        cardId: String,
        sortOrder: Int,
        isPinned: Boolean,
        now: Long,
    ): SyncOutboxEntity =
        SyncOutboxEntity(
            entityType = ENTITY_TYPE_CARD_MEMBER,
            entityId = cardId,
            op = OP_UPDATE,
            payloadJson = json.encodeToString(PlacementPayload(sortOrder, isPinned)),
            // 立刻可推。退避曲线归 T-251 —— 这里给「现在」而不是给一个猜的延迟，
            // 是因为首次尝试本来就该立刻发生，退避是**失败之后**的事。
            nextAttemptAt = now,
            createdAt = now,
        )

    /**
     * 载荷的形状照契约的 `CardPlacement`（`docs/api/openapi.yaml`）：
     * `{ "sort_order": 0, "is_pinned": false }`。
     *
     * ⚠️ 字段名是 snake_case，由 [SerialName] 钉死 —— T-251 会把这段 JSON
     * 原样作为 `PUT /v1/cards/{id}/placement` 的请求体发出去，
     * 而契约那边**不接受** camelCase。写错的症状是离线攒下的重排在恢复网络后
     * 全部 422，而那时已经没人记得是谁写的这段 JSON 了。
     *
     * 不复用生成的 `core:network:api` 的 `CardPlacement`：那会让 `data:card`
     * 依赖网络层，而本模块在 T-153 里根本不发请求（§4.3 铁律一：
     * 写只进 Room 与 outbox）。等 T-251 真的要发时，由它决定怎么解这段 JSON。
     */
    @Serializable
    private data class PlacementPayload(
        @SerialName("sort_order") val sortOrder: Int,
        @SerialName("is_pinned") val isPinned: Boolean,
    )

    private val json = Json

    /** 与 `SyncOutboxEntity.entityType` 的注释里那两个值一致。 */
    const val ENTITY_TYPE_CARD_MEMBER = "card_member"
    const val OP_UPDATE = "update"
}
