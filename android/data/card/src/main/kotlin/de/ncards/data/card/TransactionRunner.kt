package de.ncards.data.card

import androidx.room.withTransaction
import de.ncards.core.database.NcardsDatabase
import javax.inject.Inject

/**
 * 「把这一段当成一个原子步骤跑」。
 *
 * ============================================================================
 * ⚠️ 它为什么存在：`withTransaction` 在单元测试里够不着
 * ============================================================================
 * `NcardsDatabase.withTransaction { }` 要一个**真的** `RoomDatabase`。而本仓库的
 * 库是 SQLCipher 撑起来的，那是 JNI —— Robolectric 加载不了它
 * （`Coverage.kt` 里 `:core:database` 拿豁免用的就是这条理由：
 * 「SQLCipher 是 JNI，Robolectric 加载不了」）。
 *
 * 于是直接注入 `NcardsDatabase` 的后果是：`setPinned` 与 `reorder` 这两个
 * **本卡最容易写错的方法**（重排算出来的 `sort_order` 对不对、outbox 有没有
 * 按 `card_member` 入队）一条单测都写不了，只能靠仪器测试 ——
 * 而 Kover 只统计单元测试，`data:card` 又在 70% 的门禁里。
 *
 * 抽一层之后，事务的**语义**留在生产实现里，而**编排**可以在裸 JVM 上逐条断言。
 * 测试里的替身直接执行 block（见 `FakeTransactionRunner`）——
 * 它证明不了「真的回滚了」，那条留给仪器测试；它证明的是「写了哪些行、
 * 顺序对不对、该跳过的跳过了没有」，而那才是这两个方法真正会出错的地方。
 */
internal interface TransactionRunner {
    suspend fun <T> transaction(block: suspend () -> T): T
}

/**
 * 生产实现。薄到只有一行 —— 它不该有任何逻辑，
 * 否则那些逻辑就跟着它一起变成测不到的了。
 */
internal class RoomTransactionRunner
    @Inject
    constructor(
        private val database: NcardsDatabase,
    ) : TransactionRunner {
        override suspend fun <T> transaction(block: suspend () -> T): T = database.withTransaction(block)
    }
