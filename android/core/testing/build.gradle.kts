// 测试替身、规则、假数据。被各模块 testImplementation 消费（§13.4）。

plugins {
    id("ncards.android.library")
}

android {
    namespace = "de.ncards.core.testing"
}
