# 0002. 品牌名与域名暂定 NCards / ncards.de

- **Status**: Proposed
- **Date**: 2026-08-25
- **Deciders**: 创始人（决策截止：M0 结束）
- **规格引用**: §17.5 Q1、§14.1、§14.3

## Context

开放问题 **Q1（最终品牌名与域名）** 阻塞 T-001 与 T-012：仓库骨架要写 README、package name、脚本；staging 部署要申请域名与签发 TLS 证书。但 Q1 的决策人是创始人，截止日为 M0 结束 —— 也就是说 T-001 必须在 Q1 定案**之前**完成。

不定一个值就没法开工；定了值又要防止它变成"事实上的最终决定"而无人回头确认。

规格书 v1.1 通篇使用 **NCards**，§14 的环境定义已写死 `api.staging.ncards.de` 与 `api.ncards.de`，仓库目录也叫 `n-cards`。

## Decision

在 Q1 定案前，全仓统一使用 **NCards** 作为品牌名、`ncards.de` 作为域名，与规格书保持一致。

本 ADR 的 Status 保持 `Proposed`，作为 Q1 未决的**唯一挂账处**。创始人确认后：

- 若沿用 → 把 Status 改为 `Accepted`，本 ADR 到此结束。
- 若更名 → 写 ADR-NNNN 记录新名，把本条标为 `Superseded by ADR-NNNN`，并按下方"更名影响面"逐项替换。

**更名影响面**（M0 阶段的完整清单，随任务推进需同步扩充）：

| 位置 | 内容 |
|---|---|
| `README.md` | 标题、一句话定义 |
| `package.json` | `name: ncards-monorepo` |
| `commitlint.config.js` | 无（scope 与品牌无关） |
| `docs/TECHNICAL_SPEC.md` | 全文 |
| `scripts/setup-branch-protection.sh` | `REPO` 默认值 |
| GitHub 仓库名 | `ht-lin/n-cards` |
| T-005 起 | Vault transit key 名 `ncards-card` / `ncards-pii` / `ncards-hmac`、AppRole policy 名 |
| T-008 起 | Android `applicationId`、convention plugin 前缀 `ncards.*` |
| T-012 起 | 域名、TLS 证书、Ansible inventory |

**在 Q1 定案前，不注册域名、不申请证书、不在 Play Console 建应用** —— 这三项更名成本最高且不可逆（Play Console 的包名永久不可改）。T-012 执行前必须先看本 ADR 的 Status。

## Consequences

**正面**

- T-001 与 T-002/T-007/T-008 可以立即开工，不被一个非技术决策阻塞。
- 暂定值与规格书一致，若最终沿用则零迁移成本。
- 更名影响面集中记录在一处，替换时不必靠 `grep` 加运气。

**负面**

- 存在"暂定变既定"的惯性风险：写进代码的名字越多，改名的心理成本越高，最后很可能因为麻烦而默认沿用 —— 而这未必是最好的商业决策。缓解手段是把 Status 卡在 `Proposed` 并设死截止日，但这只是提醒，不是强制。
- Android `applicationId` 与 Play Console 包名一旦发布即永久不可更改，因此 T-008 的 `applicationId` 实际上会把品牌名钉死在 M0 阶段。若创始人在 M0 结束前无法定案，**必须**在 T-008 前追加决策，不能顺延。

## Alternatives considered

- **用 `<BRAND>` / `<DOMAIN>` 占位符**：看似最安全，实际会让 README 无法阅读、脚本无法执行、`package.json` 无法安装，并且占位符同样会被遗忘（还更难 grep 出全部位置，因为有人会写 `TODO` 而不是占位符）。
- **等 Q1 定案再开工 T-001**：M0 只有一周，T-002/T-007/T-008 全部依赖 T-001，为一个非技术决策阻塞整条关键路径不可接受。
- **用一个明显是临时的内部代号（如 `project-x`）**：能有效防止"暂定变既定"，但代价是全仓要做一次真实的重命名，且期间所有文档与规格书不一致 —— 用一周的阅读混乱换一个提醒，不划算。
