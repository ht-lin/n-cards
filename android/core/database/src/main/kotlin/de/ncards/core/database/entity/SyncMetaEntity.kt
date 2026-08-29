package de.ncards.core.database.entity

import androidx.room.ColumnInfo
import androidx.room.Entity

/**
 * 每个实体「上次同步成功时的服务端态」—— 也就是 §5.4.3 三路合并里的 **base**。
 *
 * ## 它装的**不是**同步游标
 *
 * 游标（`{"seq":…,"sv":…}`）归 Proto DataStore，见 §5.4.1 与 T-250。两者不重叠：
 * 游标是「拉到哪了」，这张表是「上次拉到的内容长什么样」。
 *
 * ## 为什么 base 必须落盘
 *
 * §5.4.3 的字段级三路合并要 base / local / remote 三份：
 *
 * ```
 * local[f] == base[f]  → 取 remote[f]（本地没改）
 * remote[f] == base[f] → 取 local[f]（对方没改）
 * 否则                  → 真冲突
 * ```
 *
 * 没有 base，就分不清「对方改了」和「我改了」，只能退化成粗暴的
 * last-write-wins。而冲突发生在**同一 owner 的两台设备之间**（v1.1 收窄后
 * 只剩这一种），恰恰是「用户自己在两台设备上都动过手」的场景 ——
 * 那正是最不该丢数据的时候。base 存在内存里，进程一被杀就没了，
 * 所以它必须在这张表上。
 *
 * [revision] 单独成列而不是从 [baseJson] 里解：上行 `PATCH` 的 `If-Match`
 * 每次都要它，为一个数去反序列化整个实体不值当。
 */
@Entity(
    tableName = "sync_meta",
    primaryKeys = ["entity_type", "entity_id"],
)
data class SyncMetaEntity(
    /** `card` / `card_member`，与 [SyncOutboxEntity.entityType] 同一套取值。 */
    @ColumnInfo(name = "entity_type")
    val entityType: String,
    @ColumnInfo(name = "entity_id")
    val entityId: String,
    /** 上次同步成功时的服务端 `revision`。`card_member` 不参与 revision，恒为 0。 */
    @ColumnInfo(name = "revision")
    val revision: Long,
    /** 上次同步成功的服务端实体，原样 JSON。合并算法由 T-252 写。 */
    @ColumnInfo(name = "base_json")
    val baseJson: String,
    /** epoch millis。 */
    @ColumnInfo(name = "synced_at")
    val syncedAt: Long,
)
