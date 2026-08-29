# 0007. Android 端的密钥存储自建 Keystore 门面，不用 androidx.security 的 EncryptedSharedPreferences

- **Status**: Accepted
- **Date**: 2026-08-29
- **Deciders**: Android 负责人
- **规格引用**: §3.4、§7.2（T08）、§7.3
- **影响**：T-009（数据库 passphrase）、T-150（令牌存储）、T-450（生物识别应用锁）

## Context

§3.4 对本地库加密给了一条具体路径：

> Room **必须**使用 **SQLCipher**，passphrase 为 32 字节随机值，通过 Android Keystore
> 的 AES-GCM 密钥包裹后存于 `EncryptedSharedPreferences`。

§7.3 的 MUST 清单里还有一条相关的：「令牌存储使用 Tink / `EncryptedSharedPreferences`，
**不得**明文写入 SharedPreferences 或 Room」。

T-009 是全项目第一次真的要引入它。落地时撞上两件事：

1. **`androidx.security:security-crypto` 已被 Google 标记为 deprecated。**
   整个 Jetpack Security 库停止维护，官方指引是直接用 Android Keystore 或 Tink。
   在一个要维护到明年、且这条依赖直接托着「用户全部会员码」的项目里，
   引入一个上游已经不修 bug 的加密库，是需要一个理由的，而不是默认动作。

2. **在这个设计里它其实是第二层容器。** 规格已经要求 passphrase 先经 Keystore
   的 AES-GCM 密钥包裹，包裹之后拿到的就是一段没有密钥就无意义的密文。
   再把这段密文放进 `EncryptedSharedPreferences`，等于用另一把 Keystore 密钥
   把它又加密一遍。**防护强度不变** —— 两层都锚在同一个 Keystore 上，
   攻击者能拿下其中一把就能拿下另一把。

换句话说：规格里真正承载安全性的是「**经 Keystore 包裹**」这半句，
`EncryptedSharedPreferences` 那半句是容器选型，不是安全属性的来源。

## Decision

**在 `core:crypto` 里自建一个 Keystore 门面，落盘用普通 `SharedPreferences`。**

三个类型，实现全部 `internal`，对外只暴露接口：

- `KeyWrapper` / `KeystoreAesGcmKeyWrapper` —— AndroidKeyStore 里一把 AES-256-GCM
  密钥（别名 `ncards_db_master_v1`），密文布局 `[版本][12B IV][密文||tag]`。
- `SecretStore` / `KeystoreSecretStore` —— 加密后写普通 `SharedPreferences`
  （文件 `ncards_secrets_v1`），用 `commit()` 而非 `apply()`。
- `DbPassphraseProvider` / `KeystoreDbPassphraseProvider` —— 32 字节 `SecureRandom`
  passphrase 的生成、复用与**恢复**。

**`SecretStore` 就是 §7.3 那条「令牌只存 EncryptedSharedPreferences」的落地点**：
T-150 存 access / refresh token 时用同一个门面，不再引第二套东西。

两条被这个决策逼出来、但本来就该有的东西：

- **密文里带格式版本字节。** 没有它，「换了密文格式」与「密钥真的失效了」
  在解密失败时不可区分 —— 而后者的处理方式是把用户的数据库清掉。
- **显式的恢复路径。** Keystore 密钥会因系统还原、锁屏凭据变更、ROM 行为而失效，
  此时旧密文**永远**解不开。`DbPassphraseProvider` 捕获这条分支，清掉旧条目与
  密钥、生成新 passphrase，并通过 `Passphrase.isNewlyGenerated` 告诉
  `core:database` 去删掉那个已经打不开的库文件。没有这条路径，一次 Keystore
  失效 = 应用每次启动都崩，用户除了卸载重装别无他法。

**Keystore 密钥不绑定用户认证**（`setUserAuthenticationRequired(false)`）——
这条不是本 ADR 的决定，是 §3.4 的既有取舍，在此重述是因为它是本模块最容易
被"顺手改对"的一行：Widget（T-254）与 FCM 后台同步（T-250）必须在无用户交互时
读写数据库。相应地，防护目标是「设备丢失且未解锁」与「应用间越权」，
**不是**取证级攻击。§7.2 的 T08 已按 §3.4 的要求补上这句。

## Consequences

**正面**

- 不引入停止维护的依赖。加密路径上少一个我们不控制、上游也不再修的组件。
- 少一层重复加密：一次 Keystore 往返而不是两次，冷启动开库路径更短。
- `SecretStore` 是一个只有四个方法的接口，T-150 直接复用，不必再选一次型。
- 拆成 `KeyWrapper` / `SecretStore` 两个接口后，`DbPassphraseProvider` 的三条分支
  （首次 / 复用 / 恢复）能在 **JVM 单测**里跑 —— Android Keystore 在 JVM 上不存在，
  不拆的话这三条只能靠仪器测试，而 `core:*` 吃 Kover 的 70% 行覆盖门禁。

**负面 / 代价**

- **AES-GCM 的调用、密文布局与恢复路径现在是我们自己的代码，测试责任也是我们的。**
  这是本决策最实在的成本。缓解：`KeystoreAesGcmKeyWrapperTest` 在真 Keystore 上
  逐条验往返、IV 不重复、篡改/截断/换密钥后必须失败、以及
  `isUserAuthenticationRequired == false`；逻辑分支另有单测。
- 落盘容器是普通 `SharedPreferences`。**看起来**比 `EncryptedSharedPreferences` 弱，
  评审时容易被当成疏忽 —— 这就是本 ADR 存在的理由，也是
  `KeystoreSecretStore` 类注释开头就解释这件事的理由。
- 密钥别名与 prefs 文件名带了版本后缀，换格式时要写迁移代码。这是有意的摩擦：
  原地改参数会把旧密文变成解不开的垃圾，而调用方只会看到「密钥失效」。

**不受影响的**

- 威胁模型 T08 的结论不变：SQLCipher + Keystore + `allowBackup=false` +
  可选生物识别锁，防的是设备丢失与应用间越权。
- §3.4 的其余每一条（32 字节随机、AES-GCM 包裹、不绑用户认证、生物识别只锁 UI
  不锁数据库）全部照做。

## Alternatives considered

- **`androidx.security:security-crypto`（§3.4 的字面表述）**：输在它已 deprecated，
  且在本设计里只是包裹之外的第二层容器 —— 用一个不再维护的依赖换一层
  不提升防护强度的加密，代价与收益不成比例。
- **Tink（§3.4 提到的另一个选项）**：Google 对 security-crypto 的官方替代路径之一，
  是这里唯一认真的竞争者。输在体量：我们需要的是「包裹/解包一个 32 字节的值」，
  而 Tink 带来的是一整套 keyset 管理概念与约 1 MB 的产物。恢复路径
  （密钥失效后清理重来）这类真正的难点，Tink 也不替我们解决。
- **passphrase 只放内存、每次启动重新派生**：不成立 —— 派生源要么还是得存
  （问题原样搬家），要么来自用户输入（§3.4 明确不做，Widget 与后台同步需要
  无交互访问）。
- **不加密本地库，只靠 `allowBackup=false` 与应用沙箱**：§3.4 已把这条判为
  【严重】问题并否决 —— 服务端信封加密的强度会被客户端明文库整体拉平。
