// OkHttp 配置、X-Client / X-Request-Id 拦截器、错误映射、重试（§6.1）。由 T-010 交付。
//
// 这是网络层**唯一**手写代码的地方 —— core:network:api 全部是生成产物。
//
// 认证（Bearer、401 静默刷新、并发刷新串行化）**不在这里**：那是 T-150 的
// OkHttp Authenticator，落在 data:auth。本模块只留出插槽（NetworkModule 的
// authenticator / authInterceptors 两个可选注入点）。

plugins {
    id("ncards.android.library")
    id("ncards.kotlin.serialization")
    id("ncards.android.hilt")
}

android {
    namespace = "de.ncards.core.network.impl"
}

dependencies {
    // api 而不是 implementation：ApiError / ApiResult 里出现的 Problem.Code、
    // ProblemFieldError 等生成类型会出现在本模块暴露给 data:* 的签名上。
    api(project(":core:network:api"))

    implementation(platform(libs.okhttp.bom))
    implementation(libs.okhttp)
    implementation(libs.retrofit)
    implementation(libs.retrofit.kotlinx.serialization)
    implementation(libs.kotlinx.coroutines.android)
    implementation(libs.timber)
    // §7.3：release 不得输出任何日志。这个依赖进得来，但它的 Interceptor 只在
    // NetworkConfig.enableHttpLogging 为 true 时才装（由 :app 用 BuildConfig.DEBUG 给）。
    implementation(libs.okhttp.logging.interceptor)

    testImplementation(platform(libs.okhttp.bom))
    testImplementation(libs.okhttp.mockwebserver)
}
