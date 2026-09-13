// Result / DispatcherProvider / Clock / UiText（§12.3）。跨模块的纯工具，不含 UI。
//
// T-153 建起本模块。此前它是空壳，而 T-151 的两处「本该用这里的东西」各自绕开了
// 一次（android/README.md 的「已知事项」）——「第三个消费者出现时就该把它们建起来，
// 而不是抄第三份」。

plugins {
    id("ncards.android.library")
}

android {
    namespace = "de.ncards.core.common"
}

dependencies {
    // api 而不是 implementation：DispatcherProvider 与 CurrentUserIdStore 的
    // **签名上**就有 CoroutineDispatcher / StateFlow，消费方不拿到它就没法实现
    // 这两个接口，也没法 collect。与 core:barcode 对 core:model 用 api 同理。
    //
    // ⚠️ ncards.android.library 只把 coroutines-test 挂进 testImplementation，
    // 主源集一个协程类型都没有 —— data:auth 是自己声明的 coroutines-android。
    api(libs.kotlinx.coroutines.core)

    // UiText 的 @StringRes。它早已由 core-ktx / compose 传递进来，
    // 显式声明不改变依赖图，但本模块不依赖那两者，所以这里必须自己写。
    implementation(libs.androidx.annotation)
}
