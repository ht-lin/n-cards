# 架构决策记录（ADR）

## 何时必须写 ADR（§13.7）

符合以下**任一**条件的决策必须写 ADR：

1. 改变 §2 中已有的架构决策（ADR-01 ~ ADR-11）
2. 引入新的基础设施组件或第三方服务 —— 若涉及 GDPR 子处理者，**还需更新 §8.3 子处理者清单**
3. 改变数据模型的核心语义（共享、同步、加密）
4. 引入新的跨模块依赖

不确定要不要写，就写。ADR 便宜，返工不便宜。

## 流程

1. 复制下方模板到 `docs/adr/NNNN-kebab-case-title.md`，`NNNN` 取当前最大编号 +1（四位，从 `0001` 起）。
2. 以 `docs(adr): …` 开头提 PR。ADR 可以与实现同 PR，也可以先行。
3. 由**技术负责人 + 至少一名工程师**批准。
4. ADR **只增不改**：已 Accepted 的决策若被推翻，写一篇新 ADR，并把旧的 Status 改为 `Superseded by ADR-NNNN`（这是唯一允许修改旧 ADR 的情形）。

## Status 取值

| Status | 含义 |
|---|---|
| `Proposed` | 已提出，尚未批准 —— 通常在等一个开放问题（§17.5）的决策 |
| `Accepted` | 已批准，当前生效 |
| `Superseded by ADR-NNNN` | 已被后续 ADR 取代 |
| `Deprecated` | 不再适用，也没有替代决策 |

## 模板

```markdown
# NNNN. 标题（一句话陈述决策，不是问题）

- **Status**: Proposed | Accepted | Superseded by ADR-NNNN | Deprecated
- **Date**: YYYY-MM-DD
- **Deciders**: 姓名
- **规格引用**: §x.x

## Context

是什么力量把我们推到这个决策点上？约束是什么（时间、人力、法规、既有架构）？
只写事实与约束，不写结论。

## Decision

我们决定做什么。用主动语态、肯定句："我们采用 X。"

## Consequences

这个决策之后，什么变容易了，什么变难了。**必须同时写正面与负面后果** ——
只有正面后果的 ADR 说明还没想清楚。

## Alternatives considered

考虑过的其他方案，以及为什么没选。每个方案至少一句"它输在哪"。
```

## 索引

| # | 标题 | Status |
|---|---|---|
| [0001](0001-record-architecture-decisions.md) | 采用 ADR 记录架构决策 | Accepted |
| [0002](0002-brand-name-and-domain.md) | 品牌名与域名采用 N-Cards / n-cards.de | Accepted |
| [0003](0003-problem-details-and-idempotency-semantics.md) | Problem Details 错误码表扩展 8 个 code，幂等冲突用 409/422，幂等 fail-open | Accepted |
| [0004](0004-manual-vault-unseal.md) | Vault 采用人工 unseal（Shamir 3-of-5），auto-unseal 关闭 | Accepted |
| [0005](0005-rate-limiting-topology.md) | 限流用自研 Redis 滑动窗口，默认 fail-closed，只对通用写限流开一个 allow 例外 | Accepted |
| [0006](0006-android-module-graph-enforcement.md) | Android 模块依赖规则用配置期的 Gradle 规则表强制，不用自定义 lint 规则 | Accepted |
| [0007](0007-android-secret-storage-without-jetpack-security.md) | Android 端的密钥存储自建 Keystore 门面，不用 androidx.security 的 EncryptedSharedPreferences | Accepted |
| [0008](0008-ci-gate-topology.md) | CI 用一个总是运行的 `pr-gate` 汇总，各条流水线改为 reusable workflow | Accepted |
| [0009](0009-ansible-sops-deploy-topology.md) | 部署用 Ansible over SSH + sops(age) 下发配置，部署逻辑放 reusable workflow | Accepted |
| [0010](0010-rollback-image-tag-only.md) | 自动回滚只回镜像 tag，迁移不自动回滚；部署前快照是护栏不是备份 | Accepted |
| [0011](0011-doctrine-orm-xml-mapping-and-module-owned-foreign-keys.md) | Doctrine ORM 用 XML 映射、实体非 final、外键只在模块内部建成 ORM 关联 | Accepted |
| [0012](0012-mail-channel-topology.md) | 邮件通道：~~Q3 悬置~~（决定 1 已被 0013 取代）、Doctrine 队列、消息体加密、发信收敛在两个新 deptrac 图层之后 | Accepted（决定 1 除外） |
| [0013](0013-mail-via-domain-mailbox.md) | **Q3 定案**：发信走 n-cards.de 的域名邮箱（dogado GmbH），不引入专业 ESP | Accepted |
| [0014](0014-otp-always-sends-a-code.md) | OTP 请求对**任意**邮箱都真发码，取消 decoy challenge —— 否则不存在任何注册路径 | Accepted |
| [0015](0015-access-token-verification.md) | Access token 验签：`alg` 白名单、按 `kid` 选密钥、「验两把签一把」、不做黑名单、免鉴权白名单按路由名 | Accepted |
> §2 中的 ADR-01 ~ ADR-11 是规格书内嵌的既有决策摘要，编号体系独立于本目录。本目录从 `0001` 起记录规格书**之后**的决策。
