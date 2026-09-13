// 认证 Repository 与令牌存储（§7.1 / §7.3）。由 T-150 交付。
//
// 这里是 OkHttp 的 Authenticator 与 Bearer 拦截器的家 —— 它们**不在**
// core:network:impl，因为令牌存储在 core:crypto 的 SecretStore 后面，
// 而认证还要有会话状态。那个模块只声明了两个插槽（NetworkAuthSlotsModule），
// 本模块把实现绑进去。方向由 §12.3 规则三决定：core:* 不得依赖 data:*。

plugins {
    id("ncards.android.library")
    id("ncards.android.hilt")
}

android {
    namespace = "de.ncards.data.auth"
}

dependencies {
    // UsernameRules.normalize()（T-151）。§3.8 要求「客户端与服务端均对输入
    // trim + toLowerCase(Locale.ROOT)」—— 归一化在这里再做一次，
    // 于是无论哪个调用方进来，发出去的和用来导出 Idempotency-Key 的都是同一个值。
    implementation(project(":core:model"))

    // T-153：SessionStore 顺带实现 core:common 的 CurrentUserIdStore ——
    // 钱包列表要 user id 去 JOIN card_members，而 :data:card 不许依赖本模块
    // （ModuleGraph 的 DATA_SIBLING_EXEMPTIONS 是空集），所以端口下沉到 core:common。
    //
    // api 而不是 implementation：那个接口出现在本模块 Hilt 绑定的签名上。
    api(project(":core:common"))

    // §7.3：令牌只存加密存储。SecretStore 是那条要求唯一的落地点（ADR-0007）。
    implementation(project(":core:crypto"))

    // 它 api 出 :core:network:api，所以生成的 AuthApi / Session / User 一并可见。
    implementation(project(":core:network:impl"))

    // Authenticator / Interceptor 是 OkHttp 的类型，出现在本模块绑进插槽的签名上。
    implementation(platform(libs.okhttp.bom))
    implementation(libs.okhttp)

    implementation(libs.kotlinx.coroutines.android)
    implementation(libs.timber)

    // 拦截器与 Authenticator 的价值全在「发出去的报文里到底有什么」，
    // 所以测试用真的 MockWebServer 而不是伪造的 Chain —— 与 core:network:impl
    // 的 InterceptorTest 同一条理由。
    testImplementation(platform(libs.okhttp.bom))
    testImplementation(libs.okhttp.mockwebserver)
    testImplementation(libs.retrofit)
    testImplementation(libs.retrofit.kotlinx.serialization)
    testImplementation(libs.kotlinx.serialization.json)
}
