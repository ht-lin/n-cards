package de.ncards.data.card

import de.ncards.core.database.entity.CardEntity
import de.ncards.core.database.model.WalletCard
import de.ncards.core.model.barcode.BarcodeFormat
import de.ncards.core.model.card.Card
import de.ncards.core.model.card.CardDraft
import de.ncards.core.model.card.CardRole
import de.ncards.core.model.sync.SyncState
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonPrimitive
import java.time.LocalDate

/**
 * [CardDraft] → 一行新的 `cards`（T-155 建卡）。
 *
 * 这是 [toCard] 的反方向，而**它只存在于写路径上** —— 读路径永远走
 * `WalletCard`（带成员投影），因为 UI 需要 `role` / `sortOrder` / `syncState`，
 * 那三样都不在 `cards` 上。
 *
 * ⚠️ 三个初值各有理由，都不是随手填的：
 *
 * - `revision = 1` 与服务端的 `DEFAULT 1` 对齐。「本机建的、还没推上去」这件事
 *   **不**靠一个哨兵 revision 表达 —— outbox 里那条 `op = 'create'` 才是判据，
 *   而它本来就在。发明一个 `revision = 0` 只会多一个语义要维护。
 * - `memberCount = 1` —— 就我自己。契约说 `member_count` 只有 owner 拿得到值，
 *   而本机建的卡我必然是 owner。
 * - `expiresOn` 转成 **epoch day**（`toEpochDay()`），不是 millis。
 *   `cards.expires_on` 是「哪一天」不是「哪一刻」（§3.13）。
 *   ⚠️ 与 outbox 载荷里那个 ISO 日期串是**两种表示**，别互相抄。
 */
internal fun CardDraft.toEntity(
    id: String,
    ownerId: String,
    now: Long,
): CardEntity =
    CardEntity(
        id = id,
        ownerId = ownerId,
        title = title,
        merchantLabel = merchantLabel.orNullIfBlank(),
        // ⚠️ 原样写 colorWire，**不要**经过 CardColor —— 见 Card.color 的注释。
        color = colorWire,
        barcodeFormat = barcodeFormat.wireName,
        barcodeValue = barcodeValue,
        note = note.orNullIfBlank(),
        expiresOn = expiresOn?.toEpochDay(),
        revision = 1,
        memberCount = 1,
        createdAt = now,
        updatedAt = now,
    )

/**
 * 把 [draft] 盖到库里那一行上（T-155 改卡）。
 *
 * ⚠️ **`revision` 与 `createdAt` 原样留下。** 前者是服务端的乐观锁（本机改了
 * T-251 的 `If-Match` 就一定 409），后者是这张卡的出生时刻，而钱包排序的第三个键
 * 正是它 —— 改了会让一次普通编辑把卡挪到列表最前。
 *
 * `ownerId` 同样不动：所有权不可转让（C8，转让端点已从契约里移除）。
 */
internal fun CardEntity.applying(
    draft: CardDraft,
    now: Long,
): CardEntity =
    copy(
        title = draft.title,
        merchantLabel = draft.merchantLabel.orNullIfBlank(),
        color = draft.colorWire,
        barcodeFormat = draft.barcodeFormat.wireName,
        barcodeValue = draft.barcodeValue,
        note = draft.note.orNullIfBlank(),
        expiresOn = draft.expiresOn?.toEpochDay(),
        updatedAt = now,
    )

/**
 * 库里那一行与 [draft] 之间**真的变了**的那些契约字段，键名照 `CardUpdate`。
 *
 * 空 Map = 什么都没变 ⇒ 调用方既不写 Room 也不入 outbox。
 *
 * ⚠️ 这里产出的是 [JsonElement] 而不是一个可空 data class，因为契约里
 * 「键不出现」与「键是 null」是两件事：前者是「别动」，后者是「清空」。
 * 详见 `SyncOutboxEntry.cardUpdate` 的注释。
 *
 * ⚠️ 比较前先把空串归一成 `null`（[orNullIfBlank]），与 [applying] 写库时的归一
 * 用的是**同一个函数**。两边不一致的话，用户把商家名清成空串会被判成「变了」，
 * 而写进库的是 `null` —— 于是每次保存都产生一条 outbox 记录，徽章永远亮着。
 */
internal fun CardEntity.changedFieldsOf(draft: CardDraft): Map<String, JsonElement> =
    buildMap {
        putIfChanged(SyncOutboxEntry.KEY_TITLE, title, draft.title)
        putIfChanged(SyncOutboxEntry.KEY_MERCHANT_LABEL, merchantLabel, draft.merchantLabel.orNullIfBlank())
        putIfChanged(SyncOutboxEntry.KEY_COLOR, color, draft.colorWire)
        putIfChanged(SyncOutboxEntry.KEY_BARCODE_FORMAT, barcodeFormat, draft.barcodeFormat.wireName)
        putIfChanged(SyncOutboxEntry.KEY_BARCODE_VALUE, barcodeValue, draft.barcodeValue)
        putIfChanged(SyncOutboxEntry.KEY_NOTE, note, draft.note.orNullIfBlank())
        putIfChanged(
            SyncOutboxEntry.KEY_EXPIRES_ON,
            expiresOn?.let(LocalDate::ofEpochDay)?.toString(),
            draft.expiresOn?.toString(),
        )
    }

private fun MutableMap<String, JsonElement>.putIfChanged(
    key: String,
    current: String?,
    next: String?,
) {
    if (current != next) put(key, JsonPrimitive(next))
}

/**
 * 空串与只有空白 → `null`。
 *
 * 契约把 `merchant_label` / `note` 写成 `[string, "null"]`，而表单里「清空一个
 * 可选字段」产出的是空串。两种表示都存进库的话，同一个「没填」会有两个值，
 * 而 `changedFieldsOf` 的比较会在它们之间反复横跳。
 *
 * ⚠️ 码值**不走这个函数** —— 空格在 Code 39 / Code 128 里是合法载荷字符
 * （`CardDraft.problems()` 里那条注释）。
 */
private fun String?.orNullIfBlank(): String? = this?.takeIf(String::isNotBlank)

/**
 * `WalletCard`（Room 的查询投影）→ [Card]（领域模型）。
 *
 * §12.3 把这个映射归给 `data:*`，而 `core:database/build.gradle.kts` 的文件头
 * 点名了本卡：「Entity ↔ Model 的映射按 §12.3 归 data:*（T-153）」。
 *
 * ⚠️ 这是**唯一**的一道关：过了它就没有裸字符串、没有 epoch 数字、
 * 没有 Room 的列名了。`WalletCard` 的类注释要的就是这个 ——
 * 「别让 Compose 直接吃这个类型，否则 Room 的列名会一路渗到 UI 层」。
 *
 * 写成同包的私有扩展函数而不是另开一个 `mapper/` 包，是照 `data:auth` 的做法
 * （`persisting` / `unwrappingUser` 也都是 impl 上的私有方法）。
 */
internal fun WalletCard.toCard(): Card =
    Card(
        id = card.id,
        ownerId = card.ownerId,
        title = card.title,
        merchantLabel = card.merchantLabel,
        // ⚠️ 原样带走，不在这里解析成枚举。渲染用的 CardColor 是 Card.color
        // 那个计算属性，而写回时用的是这个字符串 —— 否则一个本版本不认识的
        // 色键会在下次编辑时被静默改写（见 Card.color 的注释）。
        colorWire = card.color,
        barcodeFormat = BarcodeFormat.fromWire(card.barcodeFormat),
        barcodeValue = card.barcodeValue,
        note = card.note,
        // 库里存的是 epoch day（不是 millis）——§3.13 的 expires_on 是 DATE，
        // 一个「哪一天到期」的概念，带上时区与时刻只会让同一张卡在两台设备上
        // 显示成不同的日期。
        expiresOn = card.expiresOn?.let(LocalDate::ofEpochDay),
        revision = card.revision,
        memberCount = card.memberCount,
        createdAt = card.createdAt,
        updatedAt = card.updatedAt,
        role = CardRole.fromWire(role),
        sortOrder = sortOrder,
        isPinned = isPinned,
        syncState = SyncState.fromColumn(syncState),
    )
