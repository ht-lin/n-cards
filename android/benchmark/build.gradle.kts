// Macrobenchmark：启动、Widget→条码（§9.1 的性能预算）。
//
// **实际用例由 T-453 交付**，本任务只把接线摆好。为什么现在就接：
// Macrobenchmark 需要 app 侧有一个「像 release 但可被 profile」的 benchmark buildType，
// 而往一个已经长满 30 个模块的变体矩阵里补 buildType，代价远高于现在的十几行
// （见 NcardsAndroidApplicationPlugin 里 buildTypes.create("benchmark")）。
//
// 本模块用 com.android.test，不走 ncards.android.library —— 它没有 library 的
// 变体结构。模块依赖规则仍然生效：显式应用 ncards.module.graph。
// 规则表里 :benchmark 的允许列表是**空的**，因为它经 targetProjectPath 指向 :app，
// 那不是一条普通的 project 依赖。

plugins {
    alias(libs.plugins.android.test)
    id("ncards.module.graph")
    id("ncards.quality")
}

android {
    namespace = "de.ncards.benchmark"

    compileSdk = libs.versions.compileSdk.get().toInt()
    compileSdkMinor = libs.versions.compileSdkMinor.get().toInt()

    defaultConfig {
        // Macrobenchmark 需要 API 24+；本项目的 minSdk 26 已满足（§4.3）。
        minSdk =
            libs.versions.minSdk
                .get()
                .toInt()
        targetSdk =
            libs.versions.targetSdk
                .get()
                .toInt()
        testInstrumentationRunner = "androidx.test.runner.AndroidJUnitRunner"
    }

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }

    targetProjectPath = ":app"

    // 只对 benchmark 变体构建：debug 跑基准是没有意义的（未混淆、可调试）。
    buildTypes {
        create("benchmark") {
            isDebuggable = true
        }
    }

    experimentalProperties["android.experimental.self-instrumenting"] = true
}

dependencies {
    implementation(libs.androidx.test.junit)
    implementation(libs.androidx.test.runner)
    implementation(libs.androidx.uiautomator)
    implementation(libs.androidx.benchmark.macro.junit4)
}
