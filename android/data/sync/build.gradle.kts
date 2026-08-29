// 同步元数据 Repository（游标、outbox 视图）。由 M2 填充（§5.4）。

plugins {
    id("ncards.android.library")
    id("ncards.android.hilt")
}

android {
    namespace = "de.ncards.data.sync"
}
