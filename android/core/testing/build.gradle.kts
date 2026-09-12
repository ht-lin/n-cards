// 测试替身、规则、假数据。被各模块 testImplementation 消费（§13.4）。
//
// T-153 建起本模块。触发它的是 android/README.md 的那条规则：
// 「第三个消费者出现时就该把它们建起来，而不是抄第三份」——
// MainDispatcherExtension 此前只在 feature:onboarding 的 test 源集里有一份，
// 而本卡的 data:card 与 feature:wallet 都要用它。
//
// ⚠️ 本模块的依赖是 api 而不是 implementation，且测试库是 api 出去的：
// 它是被 testImplementation 消费的，消费方要能直接看见 JUnit 的
// @RegisterExtension 与 kotlinx-coroutines-test 的 TestDispatcher。

plugins {
    id("ncards.android.library")
}

android {
    namespace = "de.ncards.core.testing"
}

dependencies {
    api(project(":core:model"))
    api(project(":core:common"))

    // ⚠️ 这两条是 api + 非 test 配置，看着反直觉，但本模块**整体**就是测试代码：
    // 它的 src/main 被别人的 testImplementation 引。放进 testImplementation
    // 的话，消费方拿不到 MainDispatcherExtension 的基类。
    api(libs.junit.jupiter)
    api(libs.kotlinx.coroutines.test)
}
