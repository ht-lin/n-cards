// 跨 feature 的复合组件（CardTile / EmptyState / ErrorPane）。由 M1 各任务填充。
//
// T-153 建起本模块 —— android/README.md 点名了它：「core:ui 的 EmptyState /
// ErrorPane（T-153 会需要）……第三个消费者出现时就该把它们建起来，而不是抄第三份」。
//
// ⚠️ 本模块**没有 res/**，所有文案由调用方以参数传进来。
// 理由：一个跨 feature 的组件不该替调用方决定「空状态那句话怎么说」——
// 钱包的空状态与好友列表的空状态是两句完全不同的话，而它们都要用 EmptyState。
// 附带好处是 checkGermanIsDefaultLocale 与 MissingTranslation 在这里无事可做。

plugins {
    id("ncards.android.library")
    id("ncards.android.compose")
}

android {
    namespace = "de.ncards.core.ui"
}

dependencies {
    // CardTile 的入参是 CardColor（键）；色值经 core:designsystem 解析。
    api(project(":core:model"))
    implementation(project(":core:designsystem"))

    // ObserveEvents 的 LocalLifecycleOwner + repeatOnLifecycle。
    // ⚠️ feature:* 由 ncards.android.feature 自动接上这一条，但 core:ui 不是
    // feature，它走的是 ncards.android.library + compose。
    implementation(libs.androidx.lifecycle.runtime.compose)
}
