# android

Kotlin / Compose / Gradle 多模块客户端，`minSdk 26`。工程骨架由 **T-008** 交付。

## 快速开始

```bash
cd android
echo "sdk.dir=$ANDROID_HOME" > local.properties   # local.properties 不入库

./gradlew assembleDebug        # 产出 app/build/outputs/apk/debug/app-debug.apk
./gradlew ktlintCheck detekt   # §13.3 的风格门禁
./gradlew :app:lintDebug       # Android Lint（checkDependencies=true，一遍覆盖全部模块）
./gradlew check                # 上面几条 + 两个自建 i18n 门禁（见下）
./gradlew testDebugUnitTest    # 单元测试
```

**前置**：JDK 17（AGP 9.3.2 的 `org.gradle.jvm.version` 就是 17）、Android SDK
platform 37.1（`sdkmanager "platforms;android-37.1"`）。Gradle 由 wrapper 提供，
版本与 SHA-256 都钉在 `gradle/wrapper/gradle-wrapper.properties` 里。

## 工具链版本

| | 版本 | 备注 |
|---|---|---|
| AGP | 9.3.2 | **自带 Kotlin 支持** —— 见下方「三个 AGP 9 的坑」 |
| Gradle | 9.7.1 | wrapper 带 `distributionSha256Sum` |
| Kotlin | 2.4.10 | jvmTarget 17，由 `compileOptions` 推导 |
| compileSdk | 37（minor 1） | AndroidX 的 AAR 元数据要求 ≥ 37 |
| targetSdk / minSdk | 36 / 26 | §4.3 |

全部版本在 [`gradle/libs.versions.toml`](gradle/libs.versions.toml) —— §12.3 的
**唯一依赖声明处**。任何模块的 `build.gradle.kts` 里不得出现字面量版本号。

## 结构（§12.3）

```
app · core/{model,common,designsystem,ui,database,datastore,crypto,network/{api,impl},barcode,testing}
data/{auth,card,friend,sharing,sync} · sync · feature/* · widget · benchmark
build-logic/convention/  ← ncards.android.{application,library,feature,hilt,room} 等
gradle/libs.versions.toml ← 唯一依赖声明处
```

30 个模块里目前只有 `app` 与 `core:designsystem` 有实质内容，其余是空壳，
由各自的任务填充（每个 `build.gradle.kts` 顶部写了归属任务）。

## Convention plugins

`build-logic/convention/`，全部以 `ncards.` 为前缀。

**任务书点名的六个**

| id | 用途 |
|---|---|
| `ncards.android.application` | 只有 `:app` 用。三个 buildType、`localeFilters`、release 开 R8 |
| `ncards.android.library` | `core:*` / `data:*` / `sync` / `widget` 的基线 |
| `ncards.android.feature` | library + Hilt + Compose，并自动接上 `core:{model,common,designsystem,ui}` 与 lifecycle / navigation |
| `ncards.android.hilt` | KSP + Hilt |
| `ncards.android.room` | KSP + Room，`schemaLocation` 指向模块内 `schemas/`（T-009 用） |
| `ncards.kotlin.serialization` | kotlinx.serialization |

**另有四个内部插件**，任务书没点名，理由写在各自实现类的头部注释里：
`ncards.module.graph`（依赖规则强制点）、`ncards.quality`（ktlint + detekt）、
`ncards.android.compose`（只给真的有 UI 的模块）、`ncards.jvm.library`（只给
`core:model` —— 它是纯 Kotlin，连 `android.*` 都 import 不到）。

> ⚠️ 它们是 `Plugin<Project>` 类而不是 `*.gradle.kts` 预编译脚本插件。
> 理由（AGP 会与根 `build.gradle.kts` 的版本声明打架）写在
> [`build-logic/convention/build.gradle.kts`](build-logic/convention/build.gradle.kts) 顶部。

## 模块依赖规则（Gradle 在配置期强制）

```
app → feature:* → data:* → core:*
feature:* 之间禁止互相依赖（跨 feature 导航经 app 的 NavHost + core:model 的路由定义）
core:* 不得依赖 data:* / feature:*
sync 可依赖 data:* 与 core:*，不得被 feature:* 直接依赖（feature 只经 Repository）
```

规则表在
[`build-logic/…/ModuleGraph.kt`](build-logic/convention/src/main/kotlin/de/ncards/buildlogic/ModuleGraph.kt)，
**是唯一真相源**。违规在配置期就报错 —— `./gradlew help` 就会红，不必等到编译。

```bash
./gradlew -p build-logic test      # 规则表本身对不对（穷举 + settings.gradle.kts 覆盖率断言）
tools/module-graph-selftest.sh     # 规则真的接在构建上（注入 4 条违规，断言构建红）
```

两条缺一不可，理由见 [ADR-0006](../docs/adr/0006-android-module-graph-enforcement.md)。

**新建模块时**：`settings.gradle.kts` 加 `include`，并确认它能匹配到 `ModuleGraph.kt`
里的一行 —— 匹配不到会直接构建失败，那是刻意的。

## 三个 AGP 9 的坑（写 convention plugin 之前先读）

1. **不要应用 `org.jetbrains.kotlin.android`。** AGP 9 自带 Kotlin 支持，应用它会被
   直接拒绝。jvmTarget 由 `compileOptions` 推导（已验证产物是 class file major 61）。
2. **`CommonExtension` 不再带类型参数。** AGP 8 的 `CommonExtension<*, *, *, *, *, *>`
   编译不过。
3. **不要写 `extension.apply { … }`。** `CommonExtension` 继承 `ExtensionAware`，
   那个 `apply` 会绑到 Gradle 的重载而非 Kotlin 作用域函数，块内几乎所有成员
   都会变成 "Unresolved reference"，而报错完全不指向真正的原因。

网上（含 Now in Android 的历史版本）多数 convention plugin 教程在这三点上都已过时。

## 不可妥协的约束

- **离线优先**：本地数据库是 UI 的**唯一真相源**（§4.3）。网络失败绝不阻塞 UI。
- **德语是默认语言**：`values/` 放德语，`values-en/` 放英语，**不存在 `values-de/`**。
  `MissingTranslation` 为 error；反方向（有人把德语挪进 `values-de/`）由
  `./gradlew checkGermanIsDefaultLocale` 守 —— lint 查不出那个方向。
- **Compose 文案不得硬编码**：`./gradlew checkComposeHardcodedText`。
  ⚠️ 这条**不是**锦上添花 —— Android Lint 的 `HardcodedText` 只查 XML 布局属性，
  而本项目是全 Compose 无 XML 布局，那条规则**一次都不会触发**（T-008 实测：
  塞一个 `Text("hardcoded")` 进 `MainActivity`，`:app:lintDebug` 依然 BUILD SUCCESSFUL）。
  §13.3 点名的三条 lint 规则**照配了**，但 Compose 那一半必须由这个任务补上。
- **本地库加密**：Room + SQLCipher，passphrase 经 Android Keystore 包裹（T-009）。
- **release 不输出任何日志**：Timber 只在 debug 种 `DebugTree`（§7.3，见 `NcardsApplication`）。
- `core:network:api` 是 openapi-generator 产物，**禁止手改**（T-010）。

## 质量门禁（§13.3）

`ktlintCheck` / `detekt` 0 · Android Lint 0 error（`HardcodedText`、`MissingTranslation`、
`ContentDescription` 提升为 error）· `core:*` 与 `data:*` 覆盖率 ≥ 70% ·
APK 大小回归 ≤ +500 KB。

CI 见 [`.github/workflows/android.yml`](../.github/workflows/android.yml)（T-008 的最小版本）。

> ⚠️ **覆盖率阈值已配好但 CI 里还没开。** Kover 的 70% 门禁写在
> [`Coverage.kt`](build-logic/convention/src/main/kotlin/de/ncards/buildlogic/Coverage.kt)，
> 只作用于 `core:*` 与 `data:*`。现在不跑 `koverVerify` 是因为 28 个模块还是空壳、
> 分母为 0，跑了只会得到假绿。由 **T-011** 在 T-009 / T-010 之后打开。
>
> 同样待 T-011 补的还有：instrumentation 测试（Gradle Managed Device，api 26 + 34）、
> `assembleRelease` 与 APK 大小回归、生成代码 diff（需 T-010 先落地）。

## 两个自建门禁（lint 覆盖不到的地方）

| 任务 | 守什么 | 为什么 lint 不行 |
|---|---|---|
| `checkGermanIsDefaultLocale` | 不存在 `values-de/`；`values/strings.xml` 带 `tools:locale="de"` | `MissingTranslation` 只查「默认有、翻译没有」，查不出「德语被挪出了默认目录」 |
| `checkComposeHardcodedText` | Compose 里的 `Text("…")` / `text = "…"` / `contentDescription = "…"` | `HardcodedText` 只查 XML 布局属性，对全 Compose 项目空转 |

两个都挂在 `check` 上，也都在 CI 里单独成步（失败信息更直接）。
实现在 `build-logic/…/{GermanDefaultLocale,ComposeHardcodedText}.kt`，
类注释里写了各自的检查范围与已知的不精确。

## 已知事项

- `:app:lintDebug` 会打印一行 `Lint will treat :core:model as an external dependency
  and not analyze it` —— `core:model` 是纯 Kotlin 模块，Android Lint 本来就不分析它。
  它的约束由 Kotlin 编译器保证（那里根本没有 `android.*` 可 import）。
- ktlint 关掉了 `multiline-expression-wrapping`（`.kt`）与 `chain-method-continuation`
  （仅 `.kts`），理由写在 [`.editorconfig`](.editorconfig) 里。其余 `ktlint_official`
  规则全部保留。
