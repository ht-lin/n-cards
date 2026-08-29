// openapi-generator 的输出目录（§12.3 / §13.1 第 3 条）。
//
// ⚠️ **本模块的 Kotlin 源码禁止手改。** 它由 docs/api/openapi.yaml 生成，
// 提交入库但只读；CI 会重新生成并 diff，不一致即失败。接线由 T-010 交付，
// 届时这里会多一个 openapi-generator 的 Gradle task 与生成产物。
//
// .gitattributes 已给 android/core/network/api/** 配上 linguist-generated=true。
// ktlint / detekt 对 /generated/ 路径的排除见 NcardsQualityPlugin。

plugins {
    id("ncards.android.library")
    id("ncards.kotlin.serialization")
}

android {
    namespace = "de.ncards.core.network.api"
}
