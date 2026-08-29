// SyncEngine / Outbox / ConflictResolver / Workers / FcmService。**由 T-250 起填充**（§4.4）。\n// ⚠️ feature:* 不得直接依赖本模块 —— UI 只经 Repository（§12.3）。

plugins {
    id("ncards.android.library")
    id("ncards.android.hilt")
}

android {
    namespace = "de.ncards.sync"
}
