// 组装层：NavHost、Application、DI 根（§12.3）。
//
// 这是唯一允许依赖 feature:* / sync / widget 的模块 —— 跨 feature 的导航在这里
// 汇合，feature 之间因此不需要互相引用（§12.3 的第二条规则）。

plugins {
    id("ncards.android.application")
    id("ncards.android.compose")
    id("ncards.android.hilt")
}

android {
    namespace = "de.ncards"

    defaultConfig {
        applicationId = "de.ncards"

        // 网络层的 base URL 从这里下发（T-010）。core:network:impl 是 library，
        // ncards.android.library 里 buildConfig 是关死的，而且 library 的
        // BuildConfig 里也拿不到 versionName / versionCode —— X-Client 要的正是那两个。
        //
        // ⚠️ 必须**带 /v1/ 且以 / 结尾**：契约的 servers[].url 含 /v1，paths 下写的是
        // 相对路径，生成的注解是 @GET("cards")。NetworkConfig 的 init 会拦住写错的。
        //
        // 默认值给生产。debug 覆盖成 staging（§14.1：staging 是合成数据）。
        // benchmark 变体由 ncards.android.application 里的 initWith(release) 建出来，
        // 那一步发生在本脚本之前 —— 所以默认值放 defaultConfig 而不是 release，
        // 否则 benchmark 会拿不到这个字段（AGP 会报 "Unknown field"）。
        buildConfigField("String", "API_BASE_URL", "\"https://api.ncards.de/v1/\"")
    }

    buildTypes {
        getByName("debug") {
            buildConfigField("String", "API_BASE_URL", "\"https://api.staging.ncards.de/v1/\"")
        }
    }
}

dependencies {
    implementation(project(":core:model"))
    implementation(project(":core:common"))
    implementation(project(":core:designsystem"))
    implementation(project(":core:ui"))

    // DI 根要看得见这两个模块的 Hilt Module 才能把数据库装配起来（T-009）。
    // 它们本身不被 UI 直接使用 —— feature 一律经 data:* 的 Repository（§12.3）。
    implementation(project(":core:crypto"))
    implementation(project(":core:database"))

    // 同理（T-010）：DI 根要看得见 NetworkModule，而且 NetworkConfig 的绑定就在
    // 本模块的 AppNetworkModule 里 —— 少了它，Hilt 在编译期就报 missing binding。
    implementation(project(":core:network:impl"))

    // DI 根要看得见各 data 模块的 Hilt Module 才能装配它们。
    implementation(project(":data:auth"))
    implementation(project(":data:card"))
    implementation(project(":data:friend"))
    implementation(project(":data:sharing"))
    implementation(project(":data:sync"))

    // §12.3：sync 只被 app 依赖（绑定 Worker 与 FcmService），feature 一律经 Repository。
    implementation(project(":sync"))
    implementation(project(":widget"))

    implementation(project(":feature:onboarding"))
    implementation(project(":feature:wallet"))
    implementation(project(":feature:carddetail"))
    implementation(project(":feature:cardedit"))
    implementation(project(":feature:scan"))
    implementation(project(":feature:imageimport"))
    implementation(project(":feature:sharing"))
    implementation(project(":feature:friends"))
    implementation(project(":feature:settings"))
    implementation(project(":feature:legal"))

    implementation(libs.androidx.core.ktx)
    implementation(libs.androidx.activity.compose)
    implementation(libs.androidx.lifecycle.runtime.ktx)
    implementation(libs.androidx.navigation.compose)
    implementation(libs.timber)
}
