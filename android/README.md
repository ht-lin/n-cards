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

30 个模块里目前只有 `app`、`core:designsystem`、`core:crypto`、`core:database`
有实质内容，其余是空壳，由各自的任务填充（每个 `build.gradle.kts` 顶部写了归属任务）。

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

**另有五个内部插件**，任务书没点名，理由写在各自实现类的头部注释里：
`ncards.module.graph`（依赖规则强制点）、`ncards.quality`（ktlint + detekt）、
`ncards.android.compose`（只给真的有 UI 的模块）、`ncards.jvm.library`（只给
`core:model` —— 它是纯 Kotlin，连 `android.*` 都 import 不到）、
`ncards.openapi`（只给 `core:network:api`，见下方「契约代码生成」）。

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
- **本地库加密**：Room + SQLCipher，passphrase 经 Android Keystore 包裹
  （T-009 已交付，见下方「本地库加密」）。**永远不要**给 `NcardsDatabase` 加
  `fallbackToDestructiveMigration()` —— 离线优先意味着本地攒着还没推上去的写入。
- **release 不输出任何日志**：Timber 只在 debug 种 `DebugTree`（§7.3，见 `NcardsApplication`）。
- `core:network:api` 是 openapi-generator 产物，**禁止手改**（T-010）。改接口先改
  `docs/api/openapi.yaml`，然后 `./gradlew :core:network:api:generateApiClient`。
- **网络层的 `Json` 必须 `ignoreUnknownKeys = true`**（§3.10 / §13.6）。离线优先意味着
  旧客户端长期存在，服务端加一个响应字段是**允许**的。落点在
  `core:network:impl` 的 `NetworkModule.provideJson()`。

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
> `:core:network:api` 已经有一条记好理由的豁免（`Coverage.kt` 的 `COVERAGE_EXEMPT`）：
> 整个模块是生成产物，为它写测试等于在测 openapi-generator。`core:database` 那一条
> 仍待 T-011 决策。
>
> 同样待 T-011 补的还有：instrumentation 测试（Gradle Managed Device，api 26 + 34）、
> `assembleRelease` 与 APK 大小回归。**生成代码 diff 已由 T-010 落地**
> （`checkApiClientUpToDate`，见下方「契约代码生成」）。

## 本地库加密（T-009）

```
core:crypto    KeyWrapper ──► KeystoreAesGcmKeyWrapper   AndroidKeyStore 里一把 AES-256-GCM
               SecretStore ─► KeystoreSecretStore        密文写普通 SharedPreferences
               DbPassphraseProvider                      32B SecureRandom，首次生成/复用/恢复
core:database  SqlCipher.openHelperFactory(passphrase) ──► Room
```

**为什么落盘容器是普通 `SharedPreferences` 而不是 `EncryptedSharedPreferences`**：
后者已被 Google 停止维护，且在这个设计里只是「Keystore 包裹」之外的第二层容器 ——
两层都锚在同一个 Keystore 上，不提升防护强度。完整理由见
[ADR-0007](../docs/adr/0007-android-secret-storage-without-jetpack-security.md)。
`SecretStore` 也是 §7.3「令牌只存加密存储」的落地点，T-150 复用它。

**三条不要顺手改的**

| 位置 | 别改成 | 为什么 |
|---|---|---|
| `KeystoreAesGcmKeyWrapper` 的 `setUserAuthenticationRequired(false)` | `true` | Widget（T-254）与 FCM 后台同步（T-250）要在无用户交互时读写数据库。改了只在**真机锁屏**时复现，本地功能测试完全看不出来。这是 §3.4 的取舍，威胁模型 T08 已记录边界 |
| `DatabaseModule` 里没有 `fallbackToDestructiveMigration()` | 加上它 | 会把 `sync_outbox` 里还没推上去的写入连同用户手输的码值一起静默抹掉 |
| `SqlCipher.openHelperFactory` 里的 `passphrase.copyOf()` | 直接传原数组 | 工厂一直持有它。调用方 wipe 之后**第二次**开库会拿到一串 0 —— 首次安装是好的，bug 只在用户第二次打开应用时出现 |

**验证方式**

三条验收标准由 31 个仪器测试覆盖，已在 API 34 模拟器上跑绿：

```bash
./gradlew :core:crypto:connectedDebugAndroidTest    # 14 个：真 Keystore 上的加解包与 passphrase 持久化
./gradlew :core:database:connectedDebugAndroidTest  # 17 个：加密落盘 + 四张表的 DAO 行为
```

| 验收标准 | 测试 |
|---|---|
| 外部工具打开 db 文件读不出明文 | `EncryptedDatabaseTest`：框架 `SQLiteDatabase` 打不开、文件头不是 SQLite 魔数、`.db`/`-wal`/`-shm` 三个文件里都找不到哨兵串 |
| 进程重启后能用 Keystore 解出 passphrase | `DbPassphrasePersistenceTest.survivesSimulatedProcessRestart` |
| 卸载重装后为全新空库 | `DbPassphrasePersistenceTest.freshInstallYieldsANewPassphrase` |

⚠️ **后两条只做到「重建全部对象」这一步。** 测试自己也活在被杀的进程里，
真·重启与真·卸载重装是仪器测试原理上做不到的。补齐它们要靠下面的手工步骤 ——

⚠️ **但手工步骤现在还跑不了**：`:app` 里**没有任何人注入 `NcardsDatabase`**，
所以运行时根本不建库（实测：安装并启动后 `databases/` 与 `shared_prefs/` 都不存在）。
`:app` 依赖这两个模块只是为了让 Hilt 在 DI 根上看见它们的 `@Module`。
第一个真实消费者是 **T-153 的钱包列表**，从那时起下面这段才有意义：

```bash
./gradlew :app:installDebug
# 在应用里做一次会写库的操作（T-153 起：新建一张卡）之后：
adb shell run-as de.ncards.debug ls -l databases/
adb exec-out run-as de.ncards.debug cat databases/ncards.db > /tmp/ncards.db
sqlite3 /tmp/ncards.db .tables          # 期望：Error: file is not a database

adb shell am force-stop de.ncards.debug # 重启后仍能开库（Keystore 解出同一份 passphrase）
adb shell monkey -p de.ncards.debug -c android.intent.category.LAUNCHER 1

adb uninstall de.ncards.debug && ./gradlew :app:installDebug   # 重装后是全新空库
```

## 契约代码生成（T-010）

`core/network/` 一分为二：

| 模块 | 内容 |
|---|---|
| `core:network:api` | `openapi-generator` 的产物，**全部**在 `generated/` 下，禁止手改 |
| `core:network:impl` | 唯一手写的地方：OkHttp 配置、拦截器、错误映射、重试 |

### 改了契约之后要跑什么

```bash
# 1. 先改契约（唯一真相源）
$EDITOR docs/api/openapi.yaml
npm run lint:api                                        # 仓库根

# 2. 重新生成，然后**提交产物**
cd android
./gradlew :core:network:api:generateApiClient

# 3. 确认与契约一致（CI 跑的就是这一条）
./gradlew :core:network:api:checkApiClientUpToDate
```

`generateApiClient` 每次都真的跑（不做 up-to-date 判断），因为它**刻意没有**声明
`generated/` 为输出 —— 声明了，Gradle 会要求 `compileDebugKotlin` / ktlint / detekt
显式依赖它，而那条依赖正是不该建立的：编译自动触发生成，会让「产物提交入库」
变成一句空话（本地每次构建静默改写工作区，diff 检查在本机永远是绿的）。
形态与 `ktlintFormat` 改源码却不声明源码为输出相同。

### 三处偏离「开箱即用」的地方，都有理由

1. **生成器吃的不是 `openapi.yaml` 本体**，是 `build/openapi-input/openapi.json`
   —— 一份剥掉了 `additionalProperties: true` 的派生副本。不剥的话 15 个模型全部
   继承一个语法非法的 `HashMap<String, Any>()()`。契约本身一个字没动。
   细节见 `NcardsOpenApiPlugin` 的类注释与 `docs/api/README.md`。
2. **覆写了两个 mustache 模板各一行**（`{{^isEnumRef}}`），否则 `$ref` 出去的枚举
   会被打上 `@Contextual` 而在运行时找不到序列化器。未改动的上游原文一并入库，
   `diff` 应当只有一行差异 —— 见 `core/network/api/templates/README.md`。
3. **`.openapi-generator-ignore` 挡掉了生成器自带的 `ApiClient` / `HttpBearerAuth`
   与整套 Gradle 脚手架。** 前两个自己 new 一个 OkHttp + Retrofit，而横切语义全在
   `core:network:impl` 的拦截器里 —— 留着只会给人「原来还能这么用」的错觉。
   T-150 做认证时**不要**去捡 `HttpBearerAuth`。

### 升级 `openapi-generator` 是一次有工作量的操作

`libs.versions.toml` 里 `openapiGenerator` 是钉死的。升级步骤（含退出条件：
上游修了模板缺陷就把 `templates/` 整个删掉）写在
[`core/network/api/templates/README.md`](core/network/api/templates/README.md)。

## 两个自建门禁（lint 覆盖不到的地方）

| 任务 | 守什么 | 为什么 lint 不行 |
|---|---|---|
| `checkGermanIsDefaultLocale` | 不存在 `values-de/`；`values/strings.xml` 带 `tools:locale="de"` | `MissingTranslation` 只查「默认有、翻译没有」，查不出「德语被挪出了默认目录」 |
| `checkComposeHardcodedText` | Compose 里的 `Text("…")` / `text = "…"` / `contentDescription = "…"` | `HardcodedText` 只查 XML 布局属性，对全 Compose 项目空转 |

两个都挂在 `check` 上，也都在 CI 里单独成步（失败信息更直接）。
实现在 `build-logic/…/{GermanDefaultLocale,ComposeHardcodedText}.kt`，
类注释里写了各自的检查范围与已知的不精确。

## 已知事项

- **Kover 的 70% 门禁对 `core:database` 会失真（留给 T-011 决策）。** `Coverage.kt`
  让 `:core:*` 都吃 70% 行覆盖，但 SQLCipher 是 JNI，Robolectric 里加载不了 ——
  这个模块有意义的测试**全在 `androidTest`**，而 Kover 默认只统计单测。
  T-011 打开 `koverVerify` 前必须先选一个：把仪器测试的覆盖率并进来，
  还是给 `core:database` 记一条有理由的豁免。`core:crypto` 没有这个问题
  （逻辑分支拆到了接口后面，单测覆盖得到）。
- **debug APK 从 11.3 MiB 涨到 30.2 MiB（+18.9 MiB），全部是 `libsqlcipher.so`。**
  四个 ABI 各一份（arm64 5.2 MB / armeabi-v7a 3.6 MB / x86 4.9 MB / x86_64 5.7 MB）。
  §13.3 的「APK 大小回归 ≤ +500 KB」门禁由 T-011 建立，**基线取 T-009 之后的值**。
  发布走 AAB，Play 按 ABI 分发，用户实际下载增量约 3.5–5.7 MB，不是 18.9 MB ——
  别拿 APK 的数字去对上架体积。
- **`assembleRelease` 的 R8 尚未进 CI（T-011）。** SQLCipher 走 JNI 反射，是 R8 的
  经典断裂点。好消息是 AAR 自带 consumer proguard 规则（keep 了 native 方法、
  构造函数与 `mNativeHandle`），所以 `app/proguard-rules.pro` **不需要**额外条目 ——
  这一条是记下来的结论，别再去加一遍。
- **第一次改数据库 schema 的任务要先解决一个 AGP 9 的坑。** `MigrationTestHelper`
  需要把 `schemas/*.json` 放进 androidTest 的 assets，而常见写法
  `android { sourceSets.getByName("androidTest").assets.srcDir(...) }` 在 AGP 9 上
  **配置期崩**（`DefaultAndroidLibrarySourceSet_Decorated cannot be cast to
  com.android.build.gradle.api.AndroidLibrarySourceSet`）—— Kotlin DSL 的访问器
  指向旧类型，`getByName` 与 `named { }` 都一样。细节写在
  `core/database/build.gradle.kts` 的注释里。现在 `version = 1` 没有可迁移的东西，
  所以没有预先埋一段没人跑过的构建配置。
- `:app:lintDebug` 会打印一行 `Lint will treat :core:model as an external dependency
  and not analyze it` —— `core:model` 是纯 Kotlin 模块，Android Lint 本来就不分析它。
  它的约束由 Kotlin 编译器保证（那里根本没有 `android.*` 可 import）。
- ktlint 关掉了 `multiline-expression-wrapping`（`.kt`）与 `chain-method-continuation`
  （仅 `.kts`），理由写在 [`.editorconfig`](.editorconfig) 里。其余 `ktlint_official`
  规则全部保留。
- **AGP 9 的 lint 会在 `fun interface` 的 SAM 转换上崩（T-010 踩到）。**
  `androidx.annotation.experimental.lint.ExperimentalDetector` 抛
  `NoSuchElementException: Array contains no element matching the predicate`
  （`ExperimentalDetector.kt:890`），整个 `lintAnalyzeDebug` 失败，
  而报错只说文件名、不说行号 —— 定位靠二分注释。
  出路是把那一处写成 `object : Foo { ... }`，或者在 `lint.xml` 里关掉
  `UnsafeOptInUsage`（会连带失去一条真有用的检查）。
  现在只有 `NetworkModule.provideSleeper` 一处，写成了 `object :`，注释里有线索。
  **测试源集不受影响**，lint 不分析它。
- **`core:network:api` 不参与 Kover 门禁**（`Coverage.kt` 的 `COVERAGE_EXEMPT`）。
  整个模块是生成产物、禁止手改，为它写测试等于在测 openapi-generator ——
  该被测的是「契约与产物是否一致」，那由 `checkApiClientUpToDate` 守着。
  消费这些类型的行为覆盖在 `core:network:impl`（那个模块不豁免）。
