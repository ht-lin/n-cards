# 0004. Vault 采用人工 unseal（Shamir 3-of-5），auto-unseal 关闭

- **Status**: Accepted
- **Date**: 2026-08-28
- **Deciders**: 技术负责人
- **规格引用**: §3.3、§5.3、§7.4、§17.5（Q6）

## Context

§17.5 的 **Q6**「Vault unseal 方案（人工 vs 外部 KMS auto-unseal）」建议默认值是
「人工 + runbook」，决策人技术负责人，截止 M0 结束。T-005 依赖这个决策
（`docs/tasks/M0.md` 的 T-005 卡片写着「依赖 T-003（Q6 需先决策）」）。

决策的实质内容早已写在 §3.3 里，且 T-003 交付的 `infra/vault/vault.hcl` 与
`docker-compose.prod.yml` 已经按它落地（无 seal stanza、file 后端、封印状态下的
healthcheck 容忍）。但它至今只以**配置文件注释**的形式存在，散落在三处，
没有一个可评审、可引用的决策记录。§13.7 要求「引入新的基础设施组件」与
「改变数据模型的核心语义（共享、同步、**加密**）」必须写 ADR。

推动这个决策的约束：

- **§3.3 的整套论证建立在「unseal key 不在同一台在线主机上」之上。**
  服务端信封加密承诺防护数据库文件/备份泄露、只读凭据泄露、`pg_dump` 误操作、
  硬盘处置四档。若 unseal key 交给另一个在线系统，攻破那个系统即等于攻破 Vault，
  这四档承诺会一起塌掉。
- **对外文案有法律风险。** §3.3 明确禁止使用 *"Ende-zu-Ende-verschlüsselt"* /
  *"Zero Knowledge"* 一类表述，允许的只有「加密存储」。这条边界必须与技术实现一致，
  否则构成可诉的虚假宣传。
- **一期无 HA 要求**（§14.2：单主机 Compose、单容器、无副本），
  §9.2 的可用性目标是 99.5%（30 天 3.6 小时预算）。
- **团队 3–5 人**（§3.11），没有 7×24 值班。

## Decision

我们采用**人工 unseal**：Vault 的 auto-unseal 关闭，unseal key 为 Shamir 3-of-5,
离线保管，每次 Vault 容器重启后由人按 runbook 手工解封。

具体落法：

1. `infra/vault/vault.hcl` **不含** `seal` stanza（T-003 已交付）。
2. unseal key 与初始 root token 离线保管，**绝不**进 CI、Ansible secrets 或任何仓库。
3. 应用通过 **AppRole** 认证，policy 最小化（§17.4，见 `infra/vault/policies/ncards-app.hcl`），
   token TTL 1 小时、自动续期（`auth/token/renew-self`）。
4. 封印期间 `/health/ready` 返回 503 —— 这是**期望行为**，由
   `Shared\Infrastructure\Health\VaultHealthCheck` 实现。
5. 操作步骤见 `docs/runbooks/vault-unseal.md`（T-005 交付初版，T-406 完善）。

§17.5 的 Q6 据此关闭。

## Consequences

**变容易了**

- §3.3 的威胁边界论证成立且可对外陈述：拿到数据库备份的人解不开密文，
  因为密钥材料在 Vault 里，而 Vault 的解封凭据不在任何在线系统上。
- 加密的失效模式是**可见的**：封印 = `/health/ready` 503 = 部署健康检查失败，
  不会出现「以为加密了其实没有」的静默降级。
- 没有引入新的云服务依赖，因此 §8.3 的子处理者清单不变，
  也不需要为 KMS 供应商签 DPA。

**变难了 —— 这些是真实代价，不要在后续任务里悄悄绕开**

- **主机重启需要人工介入。** 每次 `docker compose up`、每次内核升级重启、
  每次机器意外断电之后，服务都处于不可用状态，直到有人拿着 3 把 unseal key 上线。
  这直接占用 §9.2 的可用性预算。一期可接受（无 HA 要求），
  但**它是本项目最主要的单点人工依赖**。
- **需要至少 3 个人（或 3 处离线保管）随时可达。** Shamir 3-of-5 意味着
  少于 3 份 key 就永远解不开 —— 而解不开就等于全部用户数据永久不可读。
  key 的保管与轮值属于运维流程问题，T-406 必须给出可操作的方案。
- **无法做全自动的滚动重启。** §14.3 的「失败自动回滚上一镜像 tag」对 app 容器成立，
  但涉及 vault 容器重启的变更一律需要人在场。
- 恢复演练必须包含 unseal 环节（§13.8 的不可裁剪项，归 T-406 的
  `restore-from-backup.md`）。

**两处与规格字面不一致的偏差，在此显式记录**

1. **`secret_id` TTL。** §7.4 的清单写「Vault AppRole：`secret_id` TTL 24h 自动续期」，
   而 §3.3 与 T-005 卡片写的是「token TTL 1 小时自动续期」。两者不是同一个东西。

   T-005 落地为：`token_ttl=1h`、`token_max_ttl=24h`、**`secret_id_ttl=0`（不过期）**。
   原因是 24 小时的 `secret_id` 需要一套自动投递机制（Vault Agent 或 response wrapping）
   才能用 —— 没有它，服务会在部署 24 小时后集体认证失败。而 T-005 不交付那套机制。

   当前 `secret_id` 由 sops(age) 加密后随 Ansible 下发（T-012），轮换是运维动作。
   **正解是 Vault Agent，归 T-406。** 在那之前，一个泄露的 `secret_id` 在被人工吊销前
   一直有效 —— 这是已知的、被接受的风险，其爆炸半径由 §17.4 的最小权限 policy 限制
   （只能加解密，不能读密钥、不能轮换、不能 rewrap）。

2. **就绪探针不验证凭据。** §17.4 的 policy 没有 `auth/token/lookup-self`，
   所以 `VaultHealthCheck` 只打免认证的 `sys/health`。

   覆盖到的：Vault 挂了、被封印、未初始化 —— 也就是本 ADR 关心的那些。
   覆盖不到的：`secret_id` 失效、policy 配错 —— 这类故障会在第一个涉密请求上
   以 503 + error 日志暴露，而不是被探针提前拦住。

   不为此放宽 policy：给探针加一条权限，换来的是在「最小权限」上开一个口子，
   而这个缺口本身有日志兜底。处置写在 runbook 的「升级路径」一节。

## Alternatives considered

- **外部 KMS auto-unseal（`seal "awskms"` / `seal "transit"`）。**
  运维负担低得多，重启即自愈。输在两点：① unseal key 等于交给另一个在线系统，
  §3.3 那套「服务端加密能挡住什么」的论证整个塌掉；② 会引入一个新的
  云服务子处理者，需要更新 §8.3 并签 DPA，而 §1.3 的一期范围里没有这项工作。
  §3.3 把它列为「若团队认为运维负担过大」的替代方案之一 —— 真要改，
  写一篇新 ADR 取代本篇，同时修订 §3.3 与对外文案。

- **应用层信封加密 + KEK 由 sops/age 在部署时注入内存。**
  §3.3 列出的另一个替代方案。输在：把密钥材料放进应用进程的内存，
  §5.3 「应用永远拿不到密钥材料，只拿加解密能力」不再成立；
  且失去 Transit 的 `rotate` / `rewrap`（§5.3 的轮换流程与 T-404 都建立在它们之上），
  轮换会变成一次「全量解密再加密」的高风险迁移。

- **systemd 明文保存 unseal key 自动解封。**
  §3.3 **明令禁止**（「不得默认 systemd 明文 unseal key」）。
  它同时具备 auto-unseal 的全部弱点和「密钥就在同一块磁盘上」这个额外的致命问题 ——
  拿到主机快照的人直接拿到 unseal key，加密退化为纯粹的心理安慰。
