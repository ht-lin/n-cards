// openapi-generator 的输出目录（§12.3 / §13.1 第 4 条）。由 T-010 接线。
//
// ⚠️ generated/ 下的每一个文件都是产物，**禁止手改** —— 下一次
// `./gradlew :core:network:api:generateApiClient` 会原样覆盖掉。
// 要改接口，去改 docs/api/openapi.yaml，然后重新生成（契约优先，§13.1 第 1 条）。
//
// 三个任务由 ncards.openapi 提供，说明见 NcardsOpenApiPlugin 的类注释：
//   generateApiClient                  重新生成到 generated/
//   generateApiClientForVerification   生成到 build/，不碰工作区
//   checkApiClientUpToDate             比对两者（挂在 check 上，CI 跑这条）
//
// 本模块**没有** src/ —— 手写代码一律归 core:network:impl。

plugins {
    id("ncards.android.library")
    id("ncards.kotlin.serialization")
    id("ncards.openapi")
}

android {
    namespace = "de.ncards.core.network.api"
}

dependencies {
    // 全部是 api 而不是 implementation：生成的 Retrofit 接口在**方法签名里**就出现了
    // retrofit2.Response 与 okhttp3 的类型，消费方（core:network:impl）不看见它们
    // 就没法调用。这是 api/implementation 区分的教科书场景，不是偷懒。
    api(platform(libs.okhttp.bom))
    api(libs.okhttp)
    api(libs.retrofit)
}
