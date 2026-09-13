package de.ncards.core.model.sync

import org.junit.jupiter.api.Assertions.assertFalse
import org.junit.jupiter.api.Assertions.assertSame
import org.junit.jupiter.api.DisplayName
import org.junit.jupiter.api.Test

@DisplayName("SyncState")
class SyncStateTest {
    /**
     * 这三个字面值是 `CardDao.observeWallet` 的 `CASE WHEN` 里写死的。
     *
     * ⚠️ 本模块看不见 `core:database`（§12.3：`core:*` 之间可以互相依赖，
     * 但让领域模型依赖 Room 是把列名倒灌回模型层），所以对不上只能靠这条测试
     * 加人眼 —— 改那段 SQL 的人必须同时改这里。
     */
    @Test
    @DisplayName("column 与 observeWallet 派生的那一列逐字一致")
    fun columnsMatchTheDerivedSql() {
        assertSame(SyncState.SYNCED, SyncState.fromColumn("SYNCED"))
        assertSame(SyncState.PENDING, SyncState.fromColumn("PENDING"))
        assertSame(SyncState.FAILED, SyncState.fromColumn("FAILED"))
    }

    /**
     * 兜底到 [SyncState.SYNCED] 即**不显示徽章**。
     *
     * 徽章是一个「有问题」的信号。凭一个读不懂的字符串给用户报警，
     * 比什么都不说更糟 —— 而这一列是本仓库自己的 SQL 产出的，
     * 走到兜底就说明 SQL 与枚举漂了，那是 bug，该在 review 里抓，不该惊动用户。
     */
    @Test
    @DisplayName("认不出来的值兜底到 SYNCED（即不显示徽章）")
    fun unknownColumnFallsBackToSynced() {
        assertSame(SyncState.SYNCED, SyncState.fromColumn("SYNCING"))
        assertSame(SyncState.SYNCED, SyncState.fromColumn(null))
    }

    /**
     * §4.3 列了四个状态，本枚举刻意只有三个。
     *
     * 这条测试是那个决定的**留痕**：它会在 T-250 加上 `SYNCING` 的那一刻变红，
     * 而那正是应该重新读一遍 [SyncState] 类注释的时刻。
     */
    @Test
    @DisplayName("SYNCING 尚未建模——它归 T-250")
    fun syncingIsNotModelledYet() {
        assertFalse(SyncState.entries.any { it.name == "SYNCING" })
    }
}
