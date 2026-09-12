package de.ncards.data.card

import de.ncards.core.database.model.WalletCard
import de.ncards.core.model.barcode.BarcodeFormat
import de.ncards.core.model.card.Card
import de.ncards.core.model.card.CardRole
import de.ncards.core.model.sync.SyncState
import java.time.LocalDate

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
