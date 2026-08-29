// 卡 Repository + Entity↔Model 映射。由 M1 填充（§4.3 离线优先）。

plugins {
    id("ncards.android.library")
    id("ncards.android.hilt")
}

android {
    namespace = "de.ncards.data.card"
}
