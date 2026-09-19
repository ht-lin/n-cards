package de.ncards.data.card

import de.ncards.core.database.entity.SyncOutboxEntity
import de.ncards.core.model.card.CardDraft
import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable
import kotlinx.serialization.encodeToString
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive

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

    /**
     * 建卡（T-155）。
     *
     * ⚠️ `entity_type` 是 `card` 而不是 `card_member` —— **这一条就是 J4 的验收标准**。
     * `CardDao.observeWallet` 派生 `sync_state` 的子查询里写死了
     * `WHERE entity_type = 'card'`，所以只有这里写对了，飞行模式下新增的卡才会
     * 带上「待同步」徽章。写成 `card_member` 的话卡会出现、但徽章不亮，
     * 而两者在代码审查里长得一模一样。
     *
     * 载荷的形状照契约的 `CardCreate`：`id` 必填（**客户端生成**的 UUIDv7），
     * 与 `title` / `color` / `barcode_format` / `barcode_value` 一起是必填项，
     * 其余三个可空。
     *
     * ⚠️ `expires_on` 是 **ISO 日期串**（`2026-12-31`）而不是 epoch day ——
     * Room 那一列存的是 epoch day，两处单位不同。搞混的症状是服务端 422，
     * 而那要等 T-251 真的开始推送才会暴露。
     *
     * 关于 T-251 会撞上的两条 `POST /v1/cards` 语义（`docs/api/openapi.yaml`）：
     * id 已经是自己的 → `200`，而且**请求体被整个忽略、什么都不改**
     * （所以离线攒下的 create 重放不会覆盖服务端上更新的标题）；
     * id 属于别人 → `409 id_conflict`，要重新生成 id 再试。
     */
    fun cardCreate(
        cardId: String,
        draft: CardDraft,
        now: Long,
    ): SyncOutboxEntity =
        SyncOutboxEntity(
            entityType = ENTITY_TYPE_CARD,
            entityId = cardId,
            op = OP_CREATE,
            payloadJson = json.encodeToString(JsonObject(draft.jsonFields() + ("id" to JsonPrimitive(cardId)))),
            nextAttemptAt = now,
            createdAt = now,
        )

    /**
     * 改卡（T-155）。`entity_type` 同样是 `card`，理由见 [cardCreate]。
     *
     * ============================================================================
     * ⚠️ 载荷里**只放变了的键**，而「键不在」与「键是 null」是两件事
     * ============================================================================
     * 契约的 `CardUpdate` 是 `minProperties: 1`、全部字段可选，语义是：
     *
     * - 键**不出现** = 别动这个字段
     * - 键出现且是 `null` = **清空**它（`merchant_label` / `note` / `expires_on` 可空）
     *
     * 所以这段 JSON 用 [JsonObject] 显式构造。用一个全可空的 `@Serializable`
     * data class 是做不到的：序列化器分不清「没设」与「设成了 null」，
     * 于是要么把没改的字段一起发出去（覆盖掉别的设备刚改的值），
     * 要么把清空操作丢掉（用户删掉备注，保存后它又回来了）。
     *
     * @param changed 变了的字段，由 `DefaultCardRepository` 在事务里对着库里那一行算出来。
     */
    fun cardUpdate(
        cardId: String,
        changed: Map<String, JsonElement>,
        now: Long,
    ): SyncOutboxEntity =
        SyncOutboxEntity(
            entityType = ENTITY_TYPE_CARD,
            entityId = cardId,
            op = OP_UPDATE,
            payloadJson = json.encodeToString(JsonObject(changed)),
            nextAttemptAt = now,
            createdAt = now,
        )

    /**
     * 一个 draft 的全部契约字段。建卡用全套；改卡从里面挑变了的那些。
     *
     * ⚠️ 键名是 snake_case，逐字照契约 —— camelCase 服务端**不接受**。
     * 与 [PlacementPayload] 用 `@SerialName` 钉死是同一条理由，
     * 只是这里手写 `JsonObject`，所以没有编译器替我们看着。
     * `SyncOutboxEntryTest` 把这几个键名写成了断言。
     */
    internal fun CardDraft.jsonFields(): Map<String, JsonElement> =
        mapOf(
            KEY_TITLE to JsonPrimitive(title),
            KEY_MERCHANT_LABEL to JsonPrimitive(merchantLabel),
            KEY_COLOR to JsonPrimitive(colorWire),
            KEY_BARCODE_FORMAT to JsonPrimitive(barcodeFormat.wireName),
            KEY_BARCODE_VALUE to JsonPrimitive(barcodeValue),
            KEY_NOTE to JsonPrimitive(note),
            // ISO-8601 的 `yyyy-MM-dd`，就是 LocalDate.toString() 的形态。
            KEY_EXPIRES_ON to JsonPrimitive(expiresOn?.toString()),
        )

    private val json = Json

    /** 与 `SyncOutboxEntity.entityType` 的注释里那两个值一致。 */
    const val ENTITY_TYPE_CARD_MEMBER = "card_member"

    /** ⚠️ 只有这个值会让「待同步」徽章亮起来。见 [cardCreate]。 */
    const val ENTITY_TYPE_CARD = "card"

    const val OP_UPDATE = "update"
    const val OP_CREATE = "create"

    const val KEY_TITLE = "title"
    const val KEY_MERCHANT_LABEL = "merchant_label"
    const val KEY_COLOR = "color"
    const val KEY_BARCODE_FORMAT = "barcode_format"
    const val KEY_BARCODE_VALUE = "barcode_value"
    const val KEY_NOTE = "note"
    const val KEY_EXPIRES_ON = "expires_on"
}
