# android

Kotlin / Compose / Gradle 多模块客户端，`minSdk 26`。**由 T-008 交付**，当前目录为空。

## 结构（§12.3）

```
app · core/{model,common,designsystem,ui,database,datastore,crypto,network,barcode,testing}
data/{auth,card,friend,sharing,sync} · sync · feature/* · widget · benchmark
build-logic/convention/  ← ncards.android.{application,library,feature,hilt,room} 等
gradle/libs.versions.toml ← 唯一依赖声明处
```

## 模块依赖规则（Gradle 强制）

```
app → feature:* → data:* → core:*
feature:* 之间禁止互相依赖（跨 feature 导航经 app 的 NavHost + core:model 的路由定义）
core:* 不得依赖 data:* / feature:*
sync 可依赖 data:* 与 core:*，不得被 feature:* 直接依赖（feature 只经 Repository）
```

## 不可妥协的约束

- **离线优先**：本地数据库是 UI 的**唯一真相源**（§4.3）。网络失败绝不阻塞 UI。
- **德语是默认语言**：`values/` 放德语，`values-en/` 放英语。`MissingTranslation` 为 error。
- **本地库加密**：Room + SQLCipher，passphrase 经 Android Keystore 包裹（T-009）。
- `core:network:api` 是 openapi-generator 产物，**禁止手改**。

## 质量门禁（§13.3）

`ktlintCheck` / `detekt` 0 · Android Lint 0 error（`HardcodedText`、`MissingTranslation`、`ContentDescription` 提升为 error）· `core:*` 与 `data:*` 覆盖率 ≥ 70% · APK 大小回归 ≤ +500 KB。
