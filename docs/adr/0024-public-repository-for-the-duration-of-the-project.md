# 0024. 项目期间仓库转 public，完成后转回 private；公开期才成立的配置一律「叠加」而非「替换」

- **Status**: Accepted
- **Date**: 2026-09-19
- **Deciders**: 全员
- **规格引用**: §13.2、§13.3、§14.3
- **影响**：CI 预算假设、分支保护、生产发布审批、GHCR 配额

## Context

仓库此前是 **GitHub Free 的私有仓**。这个组合不是中性的——它已经在实打实地扭曲工程决策，而且其中一条是在本次调查里才发现的：

1. **Actions 2000 min/月。** `main.yml` 的头注释里算过账：instrumentation 实测 25 分钟，每夜兜底 = 750 min/月，比 paths 判断省下来的还多。于是全量兜底被降频成**每周**。`pr.yml` 整套 `changes` paths 过滤拓扑同样有预算动机。

2. **⚠️ 分支保护根本配不上——而这一点此前没有被记录下来。**
   `gh api repos/ht-lin/n-cards/rulesets` 当场返回 403：

   > Upgrade to GitHub Pro or make this repository public to enable this feature.

   也就是说 `scripts/setup-branch-protection.sh` 从写出来那天起就**从未成功执行过**，[ADR-0008](0008-ci-gate-topology.md) 设计的整套「`pr-gate` 是唯一 required check」**是空转的**，main 处于**零保护**状态。这个事实与 ADR-0008 的行文给人的印象不符，特此记录。

3. **生产 approval 降级。** [ADR-0010](0010-rollback-image-tag-only.md) 的未缓解风险段写着：required reviewers 在 Free 私有仓建不出来，approval 降级成 `deploy-manual.yml` 的输入确认串。该段同时写明解除条件是「账号升 Pro，**或仓库转 public**」。

4. **GHCR 私有包只有 500 MB。** `main.yml` 每次合入推一个 sha tag 的后端镜像。

转 public 一次性解锁全部四条。代价是**不可逆的披露**：公开期间存在过的每个 commit 都会被 clone、被第三方归档、被抓取；转回 private 时，公开期产生的 fork **不会消失**，会被拆进一个独立的网络继续公开存在。

## Decision

**一、项目期间仓库为 public，交付完成后转回 private。**

翻开关**之前**完成的核查（全部留痕于此，不需要重做）：

| 核查项 | 手段 | 结论 |
|---|---|---|
| **git 全历史** secret | `gitleaks git --log-opts="--all"`，v8.30.1 + sha256 校验 | 51 个 commit、7.13 MB，**0 命中** |
| 工作树 secret | `scripts/ci/check-gitleaks.sh` | 960 个跟踪文件，0 命中 |
| sops 密文完整性 | `scripts/ci/check-sops-encrypted.sh` | 通过；production 只有 `.example`，无真值 |
| 历史删除文件 | `git log --diff-filter=D` | 仅 `.gitkeep`、旧 workflow、被重构掉的类与模板 |
| CI 日志 | 抽查 run 34761061128 全部 18950 行 | 唯一凭据是 service container 的 `POSTGRES_PASSWORD=ncards` 与 `VAULT_TOKEN: dev-only-root-token`，均为 CI 假值；无 IP、无真实密钥 |
| 镜像内容 | `backend/Dockerfile` + `.dockerignore` | `.env.local` / `.env.*.local` 已排除；入镜像的 `backend/.env` 全是 `dev-only-not-a-secret` 之类占位 |
| fork PR 拿不到 secrets | `grep -rn pull_request_target .github/workflows/` | 无输出。全仓只有 `pr.yml:23` 的 `pull_request` |

⚠️ **第一行是本次唯一的真实未知数**：`scripts/ci/check-gitleaks.sh:10` 明写「为什么不扫 git 历史」，历史在此之前**从未被扫过**。

**二、`pr.yml` 必须永远用 `pull_request`，不得改成 `pull_request_target`。**

这是「fork PR 拿不到 secrets」的**结构性**保证，不是配置项。`DEPLOY_SSH_KEY` / `SOPS_AGE_KEY` 目前都在**仓库级**（不是 Environment 级，与 `deploy.yml` 头注释的说法不符），一旦换成 `pull_request_target`，任意陌生人的 PR 就能在有 secrets 的上下文里跑他写的代码。

**三、转公开当天立刻完成的收敛**（缺一不可）：

| 设置 | 值 | 备注 |
|---|---|---|
| Fork PR 审批 | `all_external_contributors` | ⚠️ 该端点**私有仓不可用**（422 `Fork PR approval is not allowed for private repositories`），只能在翻开关**之后**配 —— 存在一个短暂窗口，期间默认值是 `first_time_contributors` |
| secret scanning | enabled | |
| secret scanning push protection | enabled | 把 `shared.yml` 里**事后**跑的 gitleaks 提前到**推送前**拦截 |
| `default_workflow_permissions` | `read` | 早已是该值，无需改动 |

**四、公开期才成立的配置，一律「叠加」而非「替换」。**

这是本决策最重要的一条。转回 private 时下列能力会**静默失效**——不会有任何报错，只是保护消失：

- ruleset / 分支保护 → 失效，main 回到零保护
- production 的 required reviewers → 失效
- secret scanning / push protection → 失效
- Actions 分钟重新计费
- GHCR 公开包转回私有后重新计入 500 MB 配额

因此：

> ⚠️ **`deploy-manual.yml` 的输入确认串（必须原样输入 `deploy-production`）不得删除。**

[ADR-0010](0010-rollback-image-tag-only.md) 的解除条件段写着「之后配上 required reviewers、**删掉** guard 里的确认串那一步」。**本决策推翻那半句**：required reviewers 应当**叠加**在确认串之上。理由是这条 ADR 的前提——「转回 private」——在写 ADR-0010 时并不存在。删掉确认串，转回私有那天审批就从两道变成零道，而且没有任何信号。

同理，`main.yml` 的每周 cron 与 `pr.yml` 的 paths 过滤在公开期**只放宽、不拆除**，且必须在注释里标明「转回 private 时收回」。

**五、不做的三件事**（已评估，明确选择不做）：

- **不轮换密钥。** 密文强度足够，age key 从未离开 GitHub Secrets 与本机。
- **不加 LICENSE。** 法律效果与无 LICENSE 相同（默认保留所有权利）。
- **不改写 git 历史。** 首个 commit 的 author/committer 是真实邮箱 `linht2018@hotmail.com`（其余 43 个都是 GitHub noreply）。改写要对 44 个 commit force push，会让 46 个已合并 PR 的 commit 引用全部失效——代价大于一个邮箱被爬。

## Consequences

### 变容易的

- **CI 预算消失。** 标准 runner 不再计费，可以把兜底频率、paths 过滤、instrumentation 覆盖面按**工程价值**而不是按分钟数来定。
- **ADR-0008 第一次真正生效。** ruleset API 现在返回 200，`setup-branch-protection.sh` 可以执行了。
- **ADR-0010 的 approval 缺口可以关掉**（配上 required reviewers，确认串保留）。
- **push protection 是本次最实质的长期收益**：下一次泄漏会被挡在推送前，而不是在 PR 上被事后发现。

### 变难的 / 已接受的代价

1. **⚠️ 不可逆。** 公开期间的历史永久可得。转回 private 不撤回任何东西。
2. **⚠️ 公开期产生的 fork 在转回 private 后继续公开存在**，被拆进独立网络。
3. **133 个 artifact 与全部 run 日志变为公开可下载**（含 `android-release` 的 APK/AAB）。均为 unsigned release 构建，release 变体尚无 signingConfig，无签名密钥泄露。已抽查日志内容干净。
4. **46 个 PR / issue 变为公开。** 内容为技术中文，无个人信息。
5. **基础设施情报公开化。** `api.staging.n-cards.de`、`ncards_ssh_port` 的自定义端口、`jail.local.j2` 的 fail2ban 阈值、`10-ncards-hardening.conf.j2` 的 sysctl 全部可读。**改 SSH 端口的混淆价值归零** —— 前提条件（仅密钥登录、fail2ban 生效、Vault 不对外监听）必须靠自身强度成立，不能靠不为人知。
6. **staging 的 sops 密文永久留在外面。** 威胁模型变成：`SOPS_AGE_KEY` 将来若泄漏，攻击者手上**已有**全部历史密文，不需要仓库访问权。已选择不轮换（见决定五），此条为知情接受。
7. **⚠️ 转回 private 时的失效是静默的。** 这是本决策最容易出事的地方，缓解手段只有决定四的「叠加不替换」加上这份 ADR。

### 转回 private 时的清单

1. 确认 `deploy-manual.yml` 的确认串仍在（若被删，先补回再转）。
2. 收回 `main.yml` / `pr.yml` 在公开期放宽的触发范围。
3. 处理 GHCR 包可见性与 500 MB 配额。
4. 记录 main 重新进入零保护状态，或同时升级 Pro。

## Alternatives considered

**1. 升级 GitHub Pro，保持私有。** 一次买断上述全部能力，且无任何不可逆披露——这是**唯一没有信息代价的方案**。输在它是持续付费，而本项目对「代码公开」本身并无顾虑；Pro 的 3000 min/月也仍然是一个需要绕着走的额度，解决不了决定一里第 1 条的根本形状。

**2. 只公开一个剥离了 `infra/` 的镜像仓，用它跑 CI。** 保住基础设施情报。输在两份仓库的 workflow 会各自演化，而 CI 的价值恰恰在于它守的是**真实**的那棵树；且 `deploy.yml` / `main.yml` 的编排本身就在 `infra/` 的假设之上，剥了就跑不了部署路径。

**3. 转公开的同时轮换全部密钥。** 更彻底。输在成本与收益不匹配：age key 从未离开 GitHub Secrets 与本机，密文强度足够，而轮换要重新加密 secrets、换 `DEPLOY_SSH_KEY`、重跑一次 staging 首启验证。已记录为知情接受的风险（后果第 6 条），将来若 key 有任何暴露迹象，轮换仍然是第一动作。

**4. 转公开前用 `git filter-repo` 改写历史清掉真实邮箱。** 输在 46 个已合并 PR 的 commit 引用会全部失效，而收益只是一个邮箱不被爬——而该邮箱本来就出现在公开可见的 GitHub 提交记录语义里。

**5. 先删掉历史 run 日志再公开。** 原计划里有这一步。实测后**撤销**：抽查的 18950 行里唯一的凭据是 CI service container 的假值，出现的 `api.staging.n-cards.de` / `/opt/ncards` 本来就在 `inventory/staging.yml` 里。删日志会丢掉 24 次成功部署的可追溯性，换不到任何东西。
