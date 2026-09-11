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

CI 见 [`.github/workflows/android.yml`](../.github/workflows/android.yml)（质量门禁，
由 `pr.yml` 与 `main.yml` 调用）与
[`android-release.yml`](../.github/workflows/android-release.yml)（`assembleRelease`
+ APK 大小回归）。仪器测试（GMD，api 26 + 34）只在 `main` 合入后跑，见 `main.yml`。

> **覆盖率门禁自 T-011 起在 CI 里跑**（`./gradlew koverVerify`）。阈值、四条豁免与
> 「Dagger/Hilt 生成代码不进分母」的过滤器都在
> [`Coverage.kt`](build-logic/convention/src/main/kotlin/de/ncards/buildlogic/Coverage.kt)。
> **加第五条豁免之前先读那段注释** —— 每一条都写了为什么，那张表一旦松了就是后门。
> 实测数字与两处纠正见下方「已知事项」。

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

## 令牌与网络认证（T-150）

```
core:network:impl  di/AuthSlots.kt ──► @BindsOptionalOf Authenticator
                                       @Multibinds @AuthInterceptors Set<Interceptor>
                   NetworkModule ────► @Unauthenticated OkHttpClient ─► … ─► @Unauthenticated AuthApi
                                       OkHttpClient = 前者 .newBuilder().dispatcher(Dispatcher())
data:auth          SessionStore ─────► core:crypto 的 SecretStore（两个键）
                   BearerAuthInterceptor / SessionAuthenticator ─► 填进上面两个插槽
                   RefreshGate ───────► Mutex + 快速通道，刷新的唯一入口
                   AuthRepository ────► OTP / magic / me / logout + sessionState
```

插槽在 `core:network:impl`、实现在 `data:auth`，是因为 §12.3 不允许
`core:*` 依赖 `data:*`。形状与后端 ADR-0018 的第一个反转端口相同。

**四条不要顺手改的**

| 位置 | 别改成 | 为什么 |
|---|---|---|
| `SessionStore.clear()` 里的两次 `secrets.remove(...)` | `secrets.clear()` | 那会连 Keystore 包裹密钥一起丢弃，而 SQLCipher 的 `db_passphrase` 在同一个门面下 —— 一次登出就让用户整个本地库不可解。**功能测试完全看不出来**（重新登录时库已被当成「首次安装」重建） |
| `NetworkModule.provideOkHttpClient` 里的 `.dispatcher(Dispatcher())` | 删掉（`newBuilder()` 反正会继承） | 继承正是问题：`maxRequestsPerHost` 默认 5，而 `Authenticator` 运行时这条 call 还占着槽位。5 个并发 401 会让刷新请求永远排不进去，**集体卡到读超时** |
| `PublicEndpoints` 那张逐条列出的路径表 | `startsWith("auth/")` | `auth/logout` 在那个前缀下但**需要** Bearer。用前缀的后果是 logout 永远发不出去而没有任何迹象 |
| `RefreshGate.idempotencyKeyFor()`（由 refresh token 导出） | `UUID.randomUUID()` | 随机 key 只盖得住单次调用内的重试。跨调用重试时旧令牌配新 key = 命中服务端重放检测 = 用户收到「令牌可能被窃」并被登出 |

**刷新失败只有 `token_invalid` 会登出。** 429 / 503 / 409 / 426 / 网络 / 500 一律
保留会话 —— 把 503 压成登出，一次维护窗口就会把全体用户踢回登录页。

**接入方要知道的两件事**

- `:app` 启动时调一次 `AuthRepository.restoreSession()`（协程里）。令牌是懒加载的，
  不调它 `sessionState` 会一直停在 `Unknown`。
- `403 username_required` **不**由认证层处理（它不是 401）。处置是跳 username
  设定页，归 T-151。

```bash
./gradlew :data:auth:testDebugUnitTest :core:network:impl:testDebugUnitTest
```

## 两个自建门禁（lint 覆盖不到的地方）

| 任务 | 守什么 | 为什么 lint 不行 |
|---|---|---|
| `checkGermanIsDefaultLocale` | 不存在 `values-de/`；`values/strings.xml` 带 `tools:locale="de"` | `MissingTranslation` 只查「默认有、翻译没有」，查不出「德语被挪出了默认目录」 |
| `checkComposeHardcodedText` | Compose 里的 `Text("…")` / `text = "…"` / `contentDescription = "…"` | `HardcodedText` 只查 XML 布局属性，对全 Compose 项目空转 |

两个都挂在 `check` 上，也都在 CI 里单独成步（失败信息更直接）。
实现在 `build-logic/…/{GermanDefaultLocale,ComposeHardcodedText}.kt`，
类注释里写了各自的检查范围与已知的不精确。

## 已知事项

- **`koverVerify` 已由 T-011 打开，四个模块记了豁免。** 理由逐条写在
  `build-logic` 的 [`Coverage.kt`](build-logic/convention/src/main/kotlin/de/ncards/buildlogic/Coverage.kt)，
  加第五条之前先读那段。两处纠正了此前的记载：
  - **T-008 说「空壳模块分母为 0 会假绿」—— 反了。** 实测 `main` 里没有 `.kt` 的
    模块直接通过，而**有源码、没单测**的模块会红在 `0.000000`。
  - **本文件此前说「`core:crypto` 没有这个问题（单测覆盖得到）」—— 那句话是错的。**
    实测 `:core:crypto` 只有 27.2%：`KeystoreAesGcmKeyWrapper` 与 `KeystoreSecretStore`
    要的是真的 AndroidKeyStore，它们的 14 个测试全在 `androidTest`。
    它与 `:core:database`（0%）是同一个问题，因此拿到同一条豁免。
  - 更要紧的是分母：**Dagger/Hilt 的生成代码原本整个计进来**，把
    `:core:network:impl` 摁在 64.5%（手写的 `RetryInterceptor` 其实是 32/33）。
    `Coverage.kt` 现在按 `@DaggerGenerated` / `@Generated` / `@Module` 与几条名字模式
    排除生成代码，该模块随即过线。分母错了，阈值调多少都没意义。
- **debug APK 从 11.3 MiB 涨到 30.2 MiB（+18.9 MiB），全部是 `libsqlcipher.so`。**
  四个 ABI 各一份（arm64 5.2 MB / armeabi-v7a 3.6 MB / x86 4.9 MB / x86_64 5.7 MB）。
  §13.3 的「APK 大小回归 ≤ +500 KB」门禁由 T-011 建立：基线在
  [`app/apk-size-baseline.txt`](app/apk-size-baseline.txt)，取 T-009 之后的
  **release** APK = 20,352,900 字节（19.41 MiB，R8 + 资源压缩之后）。
  发布走 AAB，Play 按 ABI 分发，用户实际下载增量约 3.5–5.7 MB，不是 18.9 MB ——
  别拿 APK 的数字去对上架体积。
- **`assembleRelease` 已进 CI（T-011），R8 一次通过。** SQLCipher 走 JNI 反射，是 R8 的
  经典断裂点，但 AAR 自带 consumer proguard 规则（keep 了 native 方法、构造函数与
  `mNativeHandle`），`app/proguard-rules.pro` **不需要**额外条目 —— T-009 记下的这条
  结论已被实测证实，别再去加一遍。
- **Gradle Managed Device 有三个坑，都已配好，别当成多余的配置删掉**（见
  [`ManagedDevices.kt`](build-logic/convention/src/main/kotlin/de/ncards/buildlogic/ManagedDevices.kt)
  与 [`gradle.properties`](gradle.properties)）：
  - AGP **默认拒绝 API ≤ 26** 的 GMD，要
    `android.experimental.testOptions.managedDevices.allowOldApiLevelDevices=true`。
    而 api 26 正是 §14.3 点名的一档，也是 `minSdk`。
  - **`testedAbi` 必须显式写 `x86_64`。** AGP 9 默认就是它，但会警告 AGP 10 要改成
    `arm64-v8a` —— 而这两个 system image 不支持 NDK translation，到那时
    `:core:database` 的 17 个 SQLCipher 测试会整组跑不起来。
    ⚠️ 设了之后**那条警告照样打**（已确认 setter 真的被调用，是 AGP 9.3.2 的警告
    没读 DSL 值）。别因为警告还在就把那行删掉。
- **T-011 实测：31 个仪器测试在 api 26 与 api 34 上全绿**（crypto 14 + database 17，
  两档共 62 次）。这是 T-009 之后第一次在 `minSdk` 那一档上验证 —— Keystore 与
  SQLCipher 在 Android 8.0 上的行为与 api 34 没有差异。
  api 26 上会打一句 `additionalTestOutput is not supported on ... API level < 29`，
  无害（那是测试产物收集的一个可选功能）。
  - **一次只能跑一台模拟器**（`maxConcurrentDevices=1`）。两个模块 × 两档设备会起
    **四台**模拟器，在 16 GB 机器上把 Gradle daemon 挤死，报错是
    `Gradle build daemon disappeared unexpectedly`，一个字都不提内存。
    GitHub 的标准 runner 也是 16 GB。
- **CI 上跑 GMD 必须先腾磁盘，否则 runner 会被撑爆。** T-011 首跑死在这里，
  症状是 **job 红了但一行日志都没有** —— 磁盘满到 runner 连自己的诊断日志都写不下，
  日志上传自然也没了。真正的原因只在 job 的 annotation 里：`No space left on device`。
  **「失败 + 没有日志」这个组合本身就是磁盘满的signature。**
  两个 system image 约 2 GB，加上 30 个模块的 androidTest APK，标准 runner 装不下。
  **出厂只有 9.4 G 可用**（72 G 的盘，GitHub 的预装镜像自己占掉 63 G），别以为余量很宽。

  ⚠️ **腾到 17 G 仍然不够** —— 那一版能让 api 26 跑起来，但 api 34 建 snapshot 时
  磁盘见底，emulator **零输出地静默死亡**（见下面「第四个坑」）。现在删的东西多得多
  （加上 `$ANDROID_HOME/ndk`、docker 预拉镜像、swift、powershell、hostedtoolcache
  里除 Java 外的运行时），实测：

  | | 17 G 那版 | 现在 |
  |---|---|---|
  | 清理后可用 | 17 G | **39 G** |
  | 全程最低点 | 未采样 | **21 G** |
  | job 收尾 | 2.7 G（97 %） | **21 G（72 %）** |
  | 结果 | api 34 静默死 | 62 个测试全过 |

  峰值吃掉约 18 G（下两个 image + 建两个 AVD + snapshot + 29 个模块的 androidTest APK）。
  `main.yml` 里有个后台采样器每 10 秒记一次余量，**那是诊断设施不是调试残留**，别删 ——
  两档 setup 都在同一次 gradle 调用内部，没法在它们之间插 step，只能这么采。
- **CI 上还必须先放开 `/dev/kvm` 的权限。** 这是与磁盘**无关的第二个坑**，T-011 重跑
  （磁盘已经够用）死在这里。runner 上 `/dev/kvm` 是 `crw-rw---- 1 root kvm`，
  runner 用户不在 kvm 组 —— **节点存在，但打不开**。emulator 于是回落到无硬件加速，
  而上面 `testedAbi = "x86_64"` 两档都需要它，直接拒跑：
  `x86_64 emulation currently requires hardware acceleration!`
  api 26 与 api 34 各重试 5 次全挂，收尾 `Deleting unbootable snapshot`。
  `main.yml` 里用一条 udev 规则把它改成 0666。
  - ⚠️ **守卫要查 `-r`/`-w`，不能只查 `-e`。** 最早那版写的是 `test -e /dev/kvm`，
    在失败 run 里**通过了**，然后真报错拖到三分钟后的 `api26Setup` 才炸，
    而那条报错一个字都不提 KVM。
- **CI 上还必须显式指定 GPU 模式,否则两档模拟器一台都起不来。** 这是与前两条**无关的
  第三个坑**,前两条修好之后才露出来。AGP 启动 GMD 时硬编码传 `-gpu auto-no-window`,
  而这个模式**已经不合法了**(emulator 37 的 `-help-gpu` 只认 `auto` / `host` /
  `software` / `lavapipe` / `swiftshader` / `swangle`)。emulator 于是回退到 `auto`,
  而 `auto` 要真实 GPU,无头 runner 上没有,进程随即退出:
  `Selected GPU option 'auto-no-window' is not valid, switching to 'auto' mode.`
  `main.yml` 里用 `-Pandroid.testoptions.manageddevices.emulator.gpu=swiftshader` 覆盖。
  - ⚠️ **「本地全绿」不能证明 CI 上可用。** 这条报错**本地也会打**,只是不致命 ——
    本地有显示设备,回退到 `auto` 照样能起。上面 `ManagedDevices.kt` 里记的
    「62 次测试在 api 26 与 api 34 上全绿」就是在这种环境下测出来的。
  - ⚠️ **前两条修好后,报错的「形状」几乎不变,变的是内层那句。** T-011 排查时正是因此
    把这句 GPU 报错当成噪音、写进注释说「不是死因」,绕了一圈(见 #22)。
    三层各自的判据:磁盘看**有没有日志**;KVM 看
    `x86_64 emulation currently requires hardware acceleration!` 是否出现;
    GPU 看 `gpuChoiceBasedOnGpuOptions`。
  - ⚠️ **只有真正跑到启动阶段的设备才算数。** `api26Setup` 在某次 run 里没报错,
    不等于它通过了 —— 它可能还在下载镜像时,`api34Setup` 先失败把整个构建中止了。
    判据是日志里有没有出现该设备名(`dev26_…` / `dev34_…`),而不是有没有 FAILED。
- **第四个坑仍然是磁盘,只是换了张脸。** 前三条修好后,api 26 能跑了,但 `api34Setup`
  的 `aosp_atd` 模拟器**静默死亡** —— `Error message from emulator process = []`,
  emulator 进程一个字都不输出,5 次重试全挂。把清理加狠(17 G → 39 G)之后**直接就好了**,
  没有改任何镜像或渲染器配置。
  - ⚠️ **`aosp_atd` 镜像是清白的,别去动它。** 排查中两次把 ATD 当成病因,两次都错:
    第一次是把「api 26 没跑到」误读成「api 26 通过了」,于是把两档的差异归给镜像;
    第二次是看到 api 26 能起、api 34 不能起,又觉得 ATD 可疑。**真实差异是执行顺序**
    —— api 34 排在后面,轮到它建 snapshot 时磁盘已经被前面吃光了。
  - ⚠️ **「零输出的静默死亡」优先怀疑磁盘,而不是渲染器。** 有报错文本反而说明
    emulator 活到了能打日志的时候;什么都没有,更像是写不下东西。

> **T-011 的 GMD 门禁一共踩了四层,每修一层才露出下一层，而且报错的「形状」几乎不变。**
> 判据要看内层那句,不能看形状:
>
> | 层 | 判据 | 修法 |
> |---|---|---|
> | 磁盘不足(一) | job 红了但**没有日志** | 清理 dotnet / ghc / boost / CodeQL |
> | `/dev/kvm` | `x86_64 emulation currently requires hardware acceleration!` | udev 规则改 0666 |
> | GPU 模式 | `gpuChoiceBasedOnGpuOptions` | `-P…emulator.gpu=swiftshader` |
> | 磁盘不足(二) | `Error message from emulator process = []`(**零输出**) | 清理加狠到 39 G |
>
> 全部修完后:62 个测试(31 × 两档)在 CI 上首次真正执行并全过,耗时约 23 分钟。
> **在此之前 CI 上从未跑过任何一个仪器测试**,门禁一直是空的。
- **本地跑仪器测试挂掉之后，下一次会卡在设备锁上。** 报错说「4 are active」，
  而此刻一台模拟器都没在跑 —— 计数存在 `~/.android/avd/gradle-managed/`，
  构建被杀时不回滚。出路：

  ```bash
  pkill -f qemu-system-x86_64-headless
  ./gradlew cleanManagedDevices
  ```

  CI 上不会遇到（每个 job 是全新 runner）。
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
