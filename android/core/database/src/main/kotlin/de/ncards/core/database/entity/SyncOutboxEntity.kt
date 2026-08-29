package de.ncards.core.database.entity

import androidx.room.ColumnInfo
import androidx.room.Entity
import androidx.room.Index
import androidx.room.PrimaryKey

/**
 * 离线期间攒下的、还没推给服务端的写入（§5.4.3 的「离线队列」）。
 *
 * 列名与 §5.4.3 的表定义逐字对应，别改名 —— 那段规格是 T-251 的验收基准。
 *
 * **本任务只交付表与最基础的读写。** 下面这些都归 T-251，不要提前在这里实现：
 * - 同一 `entity_id` 的 pending 记录入队时合并（只保留最新）
 * - 指数退避 `min(2^n * 5s, 30min)`，10 次后标 `FAILED`
 * - 4xx（除 409/429）不重试，直接 `FAILED` + 上报 Sentry
 * - 推送顺序先 `card` 后 `card_member`（保证引用完整性）
 *
 * ⚠️ **viewer 永远不为共享卡入队**（`placement` 除外）——§3.5/§5.4.3：
 * 只有 owner 能写卡。真入了队，服务端会 403，那是客户端 bug 的信号。
 */
@Entity(
    tableName = "sync_outbox",
    indices = [
        // 合并（T-251）与「这张卡有没有待同步」的派生查询都走这一条。
        Index("entity_type", "entity_id"),
        // flush 时取「到点了的」那些。
        Index("next_attempt_at"),
    ],
)
data class SyncOutboxEntity(
    @PrimaryKey(autoGenerate = true)
    @ColumnInfo(name = "id")
    val id: Long = 0,
    /** `card` / `card_member`。 */
    @ColumnInfo(name = "entity_type")
    val entityType: String,
    @ColumnInfo(name = "entity_id")
    val entityId: String,
    /** `create` / `update` / `delete`。 */
    @ColumnInfo(name = "op")
    val op: String,
    @ColumnInfo(name = "payload_json")
    val payloadJson: String,
    @ColumnInfo(name = "attempt_count")
    val attemptCount: Int = 0,
    /** epoch millis。退避曲线由 T-251 写。 */
    @ColumnInfo(name = "next_attempt_at")
    val nextAttemptAt: Long,
    /**
     * 上一次失败的原因，给 UI 的失败提示与排查用。
     *
     * ⚠️ 只写错误 `code`（§6.1 的错误码表），**不要**把请求体或响应体塞进来 ——
     * `payload_json` 里已经有码值了，再把它复制进一个到处传播的错误串里，
     * 迟早会跟着日志或 Sentry 出去（§7.3 / T-011 的敏感日志扫描）。
     */
    @ColumnInfo(name = "last_error")
    val lastError: String? = null,
    @ColumnInfo(name = "created_at")
    val createdAt: Long,
)
