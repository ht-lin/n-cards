# 0009. 部署用 Ansible over SSH + sops(age) 下发配置，部署逻辑放 reusable workflow

- **Status**: Accepted
- **Date**: 2026-09-02
- **Deciders**: 全员（T-012）
- **规格引用**: §7.4、§14.1、§14.2、§14.3
- **影响**：全部后续的部署与运维任务（T-405 监控、T-406 备份、T-407 安全收尾）

## Context

T-011 之后，CI 已经能把后端镜像推到 GHCR（`backend-image` job，tag 是 commit sha
与 `main`）。缺的是最后一段：**镜像怎么到主机上去**。`infra/ansible/` 当时只有一个
README，`main.yml` 末尾是一段注释占位。

约束是既定的，不是这条 ADR 选的：

- **单主机**（§4.1）。Hetzner Nürnberg，Docker Compose 起步，没有编排系统。
  实机是 2 vCPU / 4 GB / 40 GB —— 比 §4.1 假设的 CCX23 小一档。
- **主机要加固**（§7.4）：仅密钥登录、禁 root、`fail2ban`、非 22 端口。
  这些是**主机层**的事，不是容器层的。
- **`.env` 不入库**（§14），而生产配置必须能被版本化、被 review、被回溯。
  §14.3 原文指定了办法：「生产环境变量由 `sops`（age 密钥）加密后提交入库，
  部署时在目标主机解密」。
- **Vault unseal key 绝不进 CI**（ADR-0004）。于是首启流程里必然有几步是人工的，
  自动化能覆盖的边界要划清楚。

一句话：需要的既不只是「把文件拷过去」，也不是「跑个容器」，而是**幂等地把一台
裸机变成一台符合 §7.4 的应用主机，然后可重复地在它上面换镜像**。

## Decision

**用 Ansible over SSH 做主机配置与部署，配置凭据用 sops(age) 加密后入库，
部署逻辑写成一条可复用的 GitHub Actions workflow。**

三件事分别是：

**1. Ansible（`infra/ansible/`）。** 三个 role（`hardening` / `docker` /
`ncards_stack`）与三条 playbook：`site.yml`（一次性 provision，人工跑）、
`deploy.yml`（可重复部署，CI 每次 main 合入跑）、`rollback.yml`（人工回滚）。
inventory 分 staging 与 production 两套，非敏感变量明文入库（域名、SSH 端口、
ACME 目录），凭据走 sops。

**2. sops(age)，两把密钥。** staging 与 production 用**不同**的 age 密钥对
（仓库根 `.sops.yaml` 按路径分两条 `creation_rules`）：

| | 私钥放在哪 |
|---|---|
| staging | GitHub repository secret `SOPS_AGE_KEY` |
| production | `production` Environment 的 environment secret，**同名覆盖** |

environment secret 只在 job 声明了 `environment: production` 时注入，而那个
Environment 挂着 required reviewers。于是「只有过了 approval 的 job 才解得开生产
配置」由 GitHub 的权限模型强制，不靠谁记得遵守约定。

**主机上不放 age 私钥**，只有渲染出来的 `.env`（0600，deploy 用户）。

**3. 部署逻辑在 reusable workflow 里**（`.github/workflows/deploy.yml`，
`on: workflow_call`），两个调用方：`main.yml` 的 `deploy-staging`（staging，全自动）
与 `deploy-manual.yml`（`workflow_dispatch`，staging 排练或 production 发布）。
这样「生产走的是不是 staging 验过的那条路」不需要靠人记得 —— 它们共用同一份定义。
形态与 ADR-0008 的四条 reusable workflow 一致。

**PR 侧不新开流水线。** T-012 唯一需要的 PR 检查是「sops 文件确实是密文」，
它挂进了本来就无条件运行的 `shared.yml`。ADR-0008 记的唯一失效模式是
「加了新流水线却忘了加进 `pr-gate` 的 `needs`」—— 每加一条就多一次踩坑机会，
而这条检查是几十毫秒的纯文本扫描，不值得为它动拓扑。
`pr.yml` 与 `scripts/setup-branch-protection.sh` 因此一个字未改。

## Consequences

### 变容易了

- **主机配置是幂等的、可 review 的、可重跑的。** 「这台机器为什么是这样」有一份
  可执行的答案，而不是某人两个月前的 shell 历史。换机器就是改一行 inventory。
- **凭据变更走 PR。** 改数据库口令是一次 `sops` 编辑 + 一条 PR，有 diff（虽然是
  密文的 diff）、有 review、有回溯。比「谁登上去改了 .env 但没人知道」强得多。
- **staging 与生产共用一条代码路径。** 生产发布不是一条没人跑过的新路径，
  而是 staging 每天在跑的那条，只多了 approval 与另一把解密密钥。
- **不等 main 合入就能演练整条部署链路。** `deploy-manual.yml` 选 staging 即可，
  这是把 secrets、known_hosts、sops 解密、collection 安装全验一遍的唯一办法。

### 变难了 / 新增的负担

- **⚠️ deploy 用户实际拥有 root 等价权限。** 它在 `docker` 组里，
  而 `docker run -v /:/host` 一条命令就拿到宿主机根文件系统 ——
  **这一点在给不给 sudo 之前就成立了**。既然如此，我们**给了它 NOPASSWD sudo**：
  不给换不来任何实质隔离（docker 那条路照样通），只换来「provision 之后再也改不了
  这台机器」—— 因为加固之后 root 的 SSH 登录已经关了，没有第二条路进得来。

  所以「禁 root 登录」在这里的作用是**缩小攻击面与改善审计**（少一个人人都知道
  名字的账号；日志里能看出是谁登进来的），**不是权限隔离**。
  **真正的安全边界是谁持有 `DEPLOY_SSH_KEY`。**

  想真正收紧只有两条路，都不通：`authorized_keys` 的 `command=` 强制命令与
  Ansible 不兼容（它要跑任意 Python 模块）；把 deploy 移出 docker 组则等于
  它没法部署。真要做权限隔离，得是「CI 只能触发一个受限的部署服务」那种形态 ——
  单主机上不值得，记在这里留给将来。
- **多了一条要跟版本的工具链。** ansible-core、四个 galaxy collection、sops 二进制，
  都在 CI 里钉死了版本（sops 还钉了 sha256，照 `shared.yml` 里 gitleaks 的先例）。
  升级是一次显式的 PR，而不是某天早上流水线自己红了。
- **age 私钥丢了 = 配置解不开。** 两把私钥都必须离线备份，且**与 Vault 的
  unseal key 由不同的人保管** —— 同一个人同时持有「配置解密权」与「数据解密权」
  的话，ADR-0004 的 3-of-5 门限就白设了。
- **主机不放私钥的代价**：应急时必须有一台装了 sops 与私钥的运维笔记本。
  凌晨三点从手机上是改不了配置的。这是刻意的取舍 —— 少一处泄露面。
- **首启有 13 步，其中 3 步（vault init / unseal / bootstrap）不可自动化且不可重试。**
  边界很清楚：**凡是需要 unseal key 或 root token 的，永远是人工。**
  步骤写在 `docs/runbooks/staging-first-boot.md`。
- **gitleaks 多了一条豁免。** 密文的高熵串会触发 `generic-api-key`，所以
  `.gitleaks.toml` 对 `secrets.sops.yaml` 豁免了那一条规则。防「忘了加密就提交」的
  是另一道门（`scripts/ci/check-sops-encrypted.sh`），两者是配套的，
  `sensitive-scan-selftest.sh` 里有三条断言在守这个边界。

### 关于 §8.3 子处理者

**无需新增。** Hetzner Online GmbH 已在 §8.3 的表内（「全部托管与备份」，德国）。
GitHub（Actions / GHCR）不处理个人数据 —— 镜像里没有用户数据，CI 里没有生产数据，
staging 只用合成数据（§14.1）—— 沿用 T-011 的既有认定。sops 与 age 是本地运行的
加密工具，没有服务方。

## Alternatives considered

**1. GitHub Actions 里直接写 `ssh` 脚本。** 最省事，但没有幂等性、没有 dry-run、
没有「这台机器该是什么样」的声明式描述。主机加固（sshd drop-in、ufw、fail2ban、
swap）用 shell 写出来就是一堆 `grep -q || echo >>`，重跑一次会不会坏没人敢打包票。
输在：把 §7.4 的清单变成了一次性的、不可验证的操作。

**2. Docker context over SSH（`docker --context` / `DOCKER_HOST=ssh://…`）。**
部署那一半确实够用，而且不需要在主机上装 Ansible。但它**完全不覆盖主机层** ——
SSH 加固、fail2ban、ufw、swap、日志轮转一件都做不了，那些恰恰是 §7.4 的硬要求。
最后还是要再引一个工具，那不如从一开始就只用一个。

**3. Ansible Vault 而不是 sops。** Ansible 自带，少装一个二进制。输在两点：
它是**口令式**的共享密钥，无法按环境分权 —— 拿到那个口令就同时解开了 staging 与
生产；而 age 的公钥加密让我们能把生产私钥锁进 GitHub Environment，由 approval
守着。另外 §14.3 的原文直接指定了 sops(age)。

**4. Terraform + Nomad / K8s。** 在**一台** 2 vCPU 的机器上引入编排系统是纯负债：
控制平面自己就要吃掉这台机器可观的一部分内存，而换来的调度、副本、滚动更新在
单节点上一个都用不上。§9.3 的扩容路径是「垂直升配 → 拆 Postgres → 多副本」，
真到了第三步再谈编排也不迟。

**5. 把部署逻辑直接写在 `main.yml` 里，不做 reusable。** 少一个文件。输在生产
发布流水线会变成一份**复制粘贴出来的副本**，两边从此各自演化 —— 而「生产用的是不是
staging 验过的那条路」正是这条 ADR 最想保证的事。ADR-0008 的备选方案 3
（单文件 `pr.yml`）输在同一个地方。
