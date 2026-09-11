// feature:onboarding —— J1 的注册流程（§1.4）：语言/隐私说明 → 邮箱 → 6 位码
// → username 设定页。由 T-151 交付。
//
// ⚠️ 本模块**不含**钱包代码。「进入空钱包 → 引导添加第一张卡」那一步的落点是
// :app 里的一个临时占位路由，由 T-153 原地替换成 feature:wallet。

plugins {
    id("ncards.android.feature")

    // 嵌套图内部那三个路由是 @Serializable 的（navigation-compose 的类型安全路由）。
    id("ncards.kotlin.serialization")
}

android {
    namespace = "de.ncards.feature.onboarding"
}

dependencies {
    // §12.3：feature 一律经 Repository 拿数据。AuthRepository 是这条流程的全部数据面。
    implementation(project(":data:auth"))

    // ⚠️ 为了在 ViewModel 里 when 一个 ApiResult / ApiError。
    //
    // 这看着像「UI 依赖网络实现」，其实不是：ApiError 是 §6.1 错误码表的 Kotlin 形态，
    // 它的 KDoc 逐条写的就是「UI 该做什么」（「跳转 username 设定页（T-151）」
    // 那一句就在 ApiError.UsernameRequired 上）。它是客户端分支的词表，不是传输细节 ——
    // 传输细节（Response / HttpException / IOException）已经被 ApiResult 挡在 data 层之外了。
    //
    // 生成的 User / OtpChallenge / OtpRequest.Locale 由 core:network:impl 的
    // api(:core:network:api) 一并带进来。
    implementation(project(":core:network:impl"))

    // BackHandler —— username 设定页必须吃掉系统返回键（§3.8：不可跳过、不可返回）。
    implementation(libs.androidx.activity.compose)

    implementation(libs.timber)

    // ⚠️ Compose UI Test（§13.4 的验收标准：J1 旅程）在这里**不需要**任何新依赖：
    // ui-test-junit4 与 ui-test-manifest 由 ncards.android.compose 接好了，
    // 而测试自己手工构造 ViewModel，所以**不引** hilt-android-testing ——
    // 理由见 androidTest 下 OnboardingJourneyTest 的类注释。
}
