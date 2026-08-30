// N-Cards Android 构建根。模块树严格对应 §12.3。
//
// Gradle 根落在 android/ 而不是仓库根：仓库根是 monorepo 顶层，已经被 npm 工具链
// 占着（package.json / .spectral.yaml / commitlint）。后端是 composer，Android 是 Gradle，
// 三套工具链各自为根，互不干扰。
//
// ⚠️ 往这里加模块时，必须同时在 build-logic 的 ModuleGraph.kt 里让它匹配到一行规则。
// ModuleGraphTest.testEveryIncludedModuleIsCoveredByARule() 会读取本文件逐条断言 ——
// 加了模块却没有规则覆盖，是「依赖规则静默失效」最现实的一条路径。

pluginManagement {
    // convention plugin 作为 included build 参与，产出 ncards.* 的插件 id。
    includeBuild("build-logic")

    repositories {
        google()
        mavenCentral()
        gradlePluginPortal()
    }
}

dependencyResolutionManagement {
    // 模块级 repositories {} 一律拒绝 —— 依赖从哪来必须只有一个答案。
    repositoriesMode.set(RepositoriesMode.FAIL_ON_PROJECT_REPOS)

    repositories {
        google()
        mavenCentral()
    }
}

rootProject.name = "ncards"

// ---------------------------------------------------------------- 组装层
include(":app")

// ---------------------------------------------------------------- core:*
// 不得依赖 data:* / feature:*（§12.3）。
include(":core:model")          // 纯 Kotlin（ncards.jvm.library）：Card, Member, BarcodeFormat, SyncState
include(":core:common")         // Result, DispatcherProvider, Clock, UiText
include(":core:designsystem")   // Theme, Color tokens, Typography, 基础组件
include(":core:ui")             // 跨 feature 的复合组件（CardTile, EmptyState, ErrorPane）
include(":core:database")       // Room + SQLCipher（T-009）
include(":core:datastore")      // Proto DataStore（cursor、设置）
include(":core:crypto")         // Keystore 包装、EncryptedPrefs、DB passphrase 供给（T-009）
include(":core:network:api")    // ← openapi-generator 输出，只读，禁止手改（T-010）
include(":core:network:impl")   // OkHttp 配置、拦截器、错误映射、重试（T-010）
include(":core:barcode")        // 扫描 + 静态图片解码（ML Kit）与渲染（ZXing）（T-152）
include(":core:testing")        // 测试替身、规则、假数据

// ---------------------------------------------------------------- data:*
// Repository 实现 + Entity↔Model 映射。只得依赖 core:*。
include(":data:auth")
include(":data:card")
include(":data:friend")
include(":data:sharing")
include(":data:sync")

// ---------------------------------------------------------------- 同步引擎
// 可依赖 data:* 与 core:*，**不得被 feature:* 直接依赖**（feature 只经 Repository）。
include(":sync")

// ---------------------------------------------------------------- feature:*
// 彼此之间禁止互相依赖。跨 feature 导航经 app 的 NavHost + core:model 的路由定义。
include(":feature:onboarding")  // 含 username 设定页（不可跳过、二次确认）
include(":feature:wallet")
include(":feature:carddetail")
include(":feature:cardedit")
include(":feature:scan")
include(":feature:imageimport") // Photo Picker → 解码 → 多码选择（§10.1）
include(":feature:sharing")
include(":feature:friends")     // 含 username 精确搜索（显式提交，无 typeahead）
include(":feature:settings")
include(":feature:legal")

// ---------------------------------------------------------------- 其他端点
include(":widget")              // Glance AppWidget + TileService + Shortcuts（T-254/255）
include(":benchmark")           // Macrobenchmark（启动、Widget→条码）（T-453）
