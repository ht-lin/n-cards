// OkHttp 配置、X-Client / X-Request-Id 拦截器、错误映射、重试。**由 T-010 填充**（§6.1）。

plugins {
    id("ncards.android.library")
    id("ncards.kotlin.serialization")
    id("ncards.android.hilt")
}

android {
    namespace = "de.ncards.core.network.impl"
}
