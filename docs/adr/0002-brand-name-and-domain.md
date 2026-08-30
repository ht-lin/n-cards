# 0002. 品牌名与域名采用 N-Cards / n-cards.de

- **Status**: Accepted
- **Date**: 2026-08-25（提出）／2026-08-30（定案）
- **Deciders**: 创始人
- **规格引用**: §17.5 Q1、§14.1、§14.3

## Context

开放问题 **Q1（最终品牌名与域名）** 阻塞 T-001 与 T-012：仓库骨架要写 README、package name、脚本；staging 部署要申请域名与签发 TLS 证书。但 Q1 的决策人是创始人，截止日为 M0 结束 —— 也就是说 T-001 必须在 Q1 定案**之前**完成。

不定一个值就没法开工；定了值又要防止它变成"事实上的最终决定"而无人回头确认。

本 ADR 最初（2026-08-25）的形态是一条**暂定决策**：在 Q1 定案前全仓统一使用 `NCards` / `ncards.de`，与规格书 v1.1 保持一致，Status 卡在 `Proposed` 作为 Q1 未决的唯一挂账处。

### 实际经过（2026-08-30 补记）

暂定决策原文写明："若创始人在 M0 结束前无法定案，**必须**在 T-008 前追加决策，不能顺延"，理由是 Android 的 `applicationId` 一旦发布即永久不可改。

**这条止损线没有生效。** T-008（`cde8b92`）、T-009、T-010 相继合入，`applicationId = "de.ncards"` 与约 30 个模块的 `de.ncards.*` namespace 已经落地，期间没有发生追加决策。这正是本 ADR "负面后果"一栏预言的惯性风险，它如期发生了 —— 说明"把 Status 卡在 Proposed 并设死截止日"作为提醒机制是无效的，它没有任何强制力。

真正阻止损失扩大的不是这条提醒，而是另外三道**物理闸门**（不注册域名、不签发证书、不建 Play Console 应用）。定案时后两道仍关着，T-012 未启动，所以 `applicationId` 实际仍可自由更改。

定案值为 `N-Cards` / `n-cards.de`，与暂定值不同。

## Decision

品牌显示名 **N-Cards**，域名 **n-cards.de**（已注册）。

品牌显示名与域名逐字符一致，包括连字符。理由是用户获取域名的主要途径是从品牌名推断，两者不一致会在口头传播、名片、应用商店搜索三处持续产生损耗。

### 命名分层与判据

品牌名有两种形态，判据是**谁在读**：

> 人读的地方写 `N-Cards`，机器读的地方写 `ncards`。

| 层 | 形态 | 覆盖 |
|---|---|---|
| 人读 | `N-Cards` | 显示文本（README 标题、`app_name`、OpenAPI `info.title` 与 `contact.name`、代码注释）、域名及全部子域 `n-cards.de`、应用商店名称、法律文件标题 |
| 机器读 | `ncards` | 包名与命名空间 `de.ncards`（含 `applicationId`、约 30 个模块的 `namespace`、`de.ncards.buildlogic`）、Gradle 插件 id `ncards.*`、Vault key 与 policy `ncards-*`、Android 资源名 `Theme.NCards`、postgres 角色与容器用户 `ncards`、本地库文件 `ncards.db` |

**判据不是"语法允不允许连字符"。** 这点值得写死，因为它是条容易走错的岔路：机器读的那一层里，只有包名（`de.n-cards` 非法）、Android 资源名（`Theme.N-Cards` 非法）与 postgres 未加引号的标识符是语法硬禁止的；Gradle 插件 id 与 Vault key **都允许**连字符。但按"语法允许就带"来画边界有两个问题：

- **边界肉眼不可见。** 新建标识符的人得先知道当前生态的字符集规则（Gradle 插件 id 允许 `-` 吗？Room schema 目录名呢？Ansible 组名呢？），查错了不会立刻报错，只会悄悄多出一种拼法。而"谁在读"不查文档就能判断。
- **不一致会更密集，不是更少。** 若插件 id 改成 `n-cards.android.library`，实现它的类仍是 `de.ncards.buildlogic.NcardsAndroidLibraryPlugin`，应用它的模块仍是 `namespace = "de.ncards.core.crypto"` —— 每个 `build.gradle.kts` 都会在相邻几行里同时出现两种拼法。保持整个机器层齐平反而更一致。

此外 `ncards-card` 只有一个连字符且作用明确（分隔项目与用途）；`n-cards-card` 有三个而读者分不出哪个是分隔符，`n-cards-hmac` 可以被读成「项目 n 的 cards-hmac」。Vault key 会出现在 policy、审计日志与事故排查命令里，这种歧义有实际代价。

**唯一的跨层例外：SPDX `LicenseRef-N-Cards-Proprietary`。** 它形式上是机器标识符，但指代的是一份人读的法律文件，标识符照抄文件标题。判据仍是"谁在读"—— 它命名的对象属于人读层，而 Vault key 命名的是一个密钥槽位。

**这套分层带来一个此前未预料到的好处**：机器层统一取 `ncards`，恰好与 T-008 已钉死的 `de.ncards` 一致，包名无需改动。定案时看似最贵的那部分成本（约 30 个模块的包名重构）实际为零，改动被完全限制在人读层与域名字符串上。

包名与域名不一致（`de.ncards` vs `n-cards.de`）是这套分层的直接结果，可接受 —— Android 生态中包名与域名不完全对应本就是常态。

### 已执行的改动（2026-08-30）

| 位置 | 改动 |
|---|---|
| `infra/caddy/Caddyfile` + README | `api.staging.` / `api.` 域名 |
| `backend/.env` | `PROBLEM_TYPE_BASE_URI` |
| `backend/src/Shared/**` | `ApiSurface`、`ApiProblemFactory` 中的域名 |
| `backend/tests/**` | 断言中的 problem type URI 与基址 |
| `backend/composer.json` + `.lock` | `n-cards/backend`；`content-hash` 随之重算 |
| `docs/api/openapi.yaml` | `servers`、`info.title`、`contact.name`、全部 problem type URI |
| `docs/api/schemas/problem-details.schema.json` | `$id` |
| `android/app/build.gradle.kts` | 两个 buildType 的 `API_BASE_URL` |
| `android/**/res/values*/strings.xml` | `app_name`、`skeleton_title` |
| `android/core/network/impl/src/test/**` | 断言中的基址与 problem type URI |
| `package.json` + `package-lock.json` | `n-cards-monorepo` |
| `docs/api/openapi.yaml`、`docs/api/README.md` | SPDX `LicenseRef-N-Cards-Proprietary` |
| `docs/TECHNICAL_SPEC.md` | 全文品牌名、§14 环境定义、§17.5 Q1 结论 |
| `README.md`、`docs/**/*.md` | 品牌名与域名 |
| `docs/adr/0001-*.md` | 正文品牌名（为全仓字面统一，破例改动已 Accepted 的 ADR 正文） |

**RFC 9457 `type` URI 一并改为新域名。** 这组 URI 是标识符、不要求可解析，技术上可以不动，但留着就等于把一个非本项目持有的域名永久焊进 API 契约。改它是破坏性契约变更（客户端按 `type` 做错误分支），M1 上线后再改会让旧版本 App 的错误处理静默失效 —— 因此必须在上线前完成，此刻成本为零。

**尚未执行**：TLS 证书、Ansible inventory、Play Console 应用创建，均属 T-012 与 M4 范围，届时直接使用新域名。

## Consequences

**正面**

- 品牌名与域名完全一致，无需长期维护"用户记住的名字"与"能到达的地址"之间的偏差。
- 机器层统一取 `ncards`，`de.ncards` 与全部内部标识符不受影响，改动面被限制在人读层与域名字符串，未触碰任何一行 Kotlin/PHP 的 package 声明。
- problem type URI 在上线前完成变更，M1 之后的契约稳定性不受影响。
- Q1 出清，`docs/adr/README.md` 与 §17.5 不再有本项挂账。

**负面**

- 显示名与包名不一致（`N-Cards` vs `de.ncards`），新人可能误以为其中一个写错了。缓解手段是上方的分层表 —— 它是这条不一致的唯一权威说明，新增任何带品牌名的标识符前应先查表，判据是"谁在读"。
- 连字符会在口头传播中丢失（"n cards" 听不出连字符），用户可能输入无连字符的域名而无法到达本站。这是接受该域名时的已知代价。

**关于本 ADR 的机制失效，供后续 ADR 参考**

Status 字段加截止日不构成任何强制力。T-008 在无人追加决策的情况下正常合入并通过全部 CI —— 因为没有任何自动化检查会读 ADR 的 Status。若某项决策确实不能被顺延，必须落成 CI 检查或物理闸门（如"域名未注册"），不能落成文档里的一句提醒。

本次损失为零，但不能记成机制起了作用：`applicationId` 之所以不用改，是因为定案后选定的机器层形态恰好仍是 `ncards`。若创始人选了一个与 `ncards` 无关的名字，T-008 至 T-010 的三次合入就会实打实地变成一次全模块包名重构。

## Alternatives considered

- **保留品牌名 `NCards`，域名用 `n-cards.de`**：品牌名与域名不一致，用户从品牌名推断出的地址无法到达本站，且该偏差会在整个产品生命周期内持续产生损耗。
- **只改域名、不改 problem type URI**：能少改十几处，但把非本项目持有的域名永久留在公开 API 契约里，且这是**唯一**能零成本修改的时间窗口 —— M1 上线后即为破坏性变更。省下的工作量与代价完全不成比例。
- **凡语法允许连字符处一律带上**（即插件 id 改 `n-cards.*`、Vault key 改 `n-cards-*`，只在包名与 Android 资源名处退回 `ncards`）：这是本次定案时认真考虑过的方案，否决理由见上方"命名分层与判据"—— 边界落在各生态的字符集规则上，肉眼不可见且需逐个查文档；且它会让 `n-cards.android.library` 与 `de.ncards.buildlogic.NcardsAndroidLibraryPlugin` 在同一个文件里并存，不一致反而更密集。
- **连字符贯穿到包名与资源名**：语法上不可能，`de.n-cards` 与 `Theme.N-Cards` 都非法，postgres 未加引号的角色名同理。
- **另写一份 ADR 记录定案、把本条标为 Superseded**：本 ADR 原文规定改名走这条路。未采用，因为本 ADR 自我定位为"Q1 未决的唯一挂账处"，在同一份文档里开账并销账、连同止损线失效的经过一并留档，比拆成两份更完整；且暂定值与定案值的差异仅为一个连字符，不足以支撑一份独立 ADR。原暂定决策的内容完整保留在下方，未被抹去。

### 以下为 2026-08-25 暂定决策的原始记录（已被上方 Decision 取代，保留备查）

暂定采用 `NCards` / `ncards.de`，与规格书 v1.1 一致。当时列出的更名影响面涵盖 `README.md`、`package.json`、`backend/composer.json`（仅包名）、`docs/TECHNICAL_SPEC.md`、`scripts/setup-branch-protection.sh`、GitHub 仓库名、T-005 起的 Vault key 与 policy 名、T-008 起的 `applicationId` 与 convention plugin 前缀、T-012 起的域名与证书。当时曾判断 `backend/` PHP 源码不受影响（T-002 刻意把根命名空间定为 Symfony 默认的 `App\` 而不是 `NCards\`）—— 该判断经本次实际改名验证成立，PHP 侧只改了字符串常量，未触碰命名空间。

当时考虑并否决的方案：用 `<BRAND>` / `<DOMAIN>` 占位符（README 不可读、脚本不可执行、`package.json` 不可安装，且占位符同样会被遗忘）；等 Q1 定案再开工 T-001（M0 只有一周，为非技术决策阻塞关键路径不可接受）；用明显临时的内部代号如 `project-x`（能防"暂定变既定"，但代价是全仓再做一次真实重命名，且期间文档与规格书不一致）。
