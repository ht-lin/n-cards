// 卡 Repository + Entity↔Model 映射。由 M1 填充（§4.3 离线优先）。
//
// T-153 建起本模块：钱包列表的读路径 + placement（置顶/排序）的写路径。
// 上行推送不在这里 —— outbox 的合并、退避与 flush 全归 T-251，
// SyncEngine 归 T-250（见 SyncOutboxDao 的类注释）。

plugins {
    id("ncards.android.library")
    id("ncards.android.hilt")
    // outbox 的 payload_json。形状照契约的 CardPlacement（snake_case 由
    // @SerialName 钉死）—— T-251 会把它原样作为请求体发出去。
    id("ncards.kotlin.serialization")
}

android {
    namespace = "de.ncards.data.card"

    // FakeCardRepository 住在 src/testFixtures/ 里，供三个 feature 共用（T-155）。
    //
    // 为什么不是各 feature 一份 sharedTest 拷贝：T-154 的落地记录记着它当时已经有
    // 两份（feature:wallet / feature:carddetail），并点名本卡会让它变成第三份。
    // 放在这里换来的性质是三份拷贝做不到的 —— **替身与 CardRepository 接口同模块，
    // 接口加方法时替身当场编译失败**。本卡恰好就在给那个接口加两个方法。
    //
    // 为什么不是 core:testing：那要让 :core: 依赖 :data:，而 ModuleGraph 的
    // `":core:" to listOf(":core:")` 会在**配置期**把构建打红（T-154 已经勘过这条路）。
    //
    // ⚠️ 用 AGP 的 testFixtures DSL，**不是** Gradle 的 `java-test-fixtures` 插件 ——
    // 后者只对 JVM 工程有效，本模块是 com.android.library。两者的消费方写法相同
    // （`testFixtures(project(...))`），但插件那条在这里根本不生效。
    testFixtures {
        enable = true
    }
}

dependencies {
    // Card / CardRole / SyncState 出现在 CardRepository 的签名上 → api。
    api(project(":core:model"))

    // CurrentUserIdStore（「我是谁」）与 DispatcherProvider。
    // ⚠️ 本模块**不许**依赖 :data:auth（ModuleGraph 的 DATA_SIBLING_EXEMPTIONS
    // 是空集），所以 user id 是经 core:common 的端口进来的，实现由 :data:auth
    // 在 DI 根上 @Binds 进去。
    implementation(project(":core:common"))

    // Room 的 DAO 与 WalletCard 投影。⚠️ 到本模块为止 —— §12.3：
    // feature:* 一律经 Repository，看不见 Room 的任何类型。
    implementation(project(":core:database"))

    implementation(libs.kotlinx.coroutines.android)
    implementation(libs.kotlinx.serialization.json)
    implementation(libs.timber)

    // MainDispatcherExtension / TestDispatcherProvider / CardFixtures。
    // ⚠️ ncards.android.feature 会自动加这一条，但 ncards.android.library 不会 ——
    // 本模块是 data:* 不是 feature:*，所以要自己写。
    testImplementation(project(":core:testing"))

    // FakeCardRepository 的签名上就有 Flow（它实现 CardRepository）。
    // ⚠️ testFixtures 源集**不继承** main 的 implementation 依赖，所以这条要单写 ——
    // 不写的症状是 kotlinx 整个解析不到，而 :core:model 那些类型是好的
    // （那是 api，testFixtures 自动看得见）。
    testFixturesImplementation(libs.kotlinx.coroutines.core)
}
