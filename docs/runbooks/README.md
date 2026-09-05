# Runbooks

运维手册。**凡是需要人在深夜按步骤操作的事，都必须先有 runbook** —— 这是 Definition of Done 的一项（§13.8）。

每篇 runbook 的固定结构：**触发条件 / 前置检查 / 步骤（可复制粘贴的命令）/ 验证 / 失败回滚 / 升级路径（找谁）**。

## 清单（§12.1）

| 文件 | 内容 | 交付任务 | 状态 |
|---|---|---|---|
| [`vault-unseal.md`](vault-unseal.md) | Vault 手工 unseal（Shamir 3-of-5）。auto-unseal **关闭**，unseal key 离线保管，**绝不进 CI**（Q6，见 [ADR-0004](../adr/0004-manual-vault-unseal.md)） | T-005 初版 ✅ / T-012 补实际路径 ✅ / T-406 完善 | ✅ 初版 |
| [`staging-first-boot.md`](staging-first-boot.md) | 新主机从裸机到「main 合入自动部署」的 13 步。**其中 vault init / unseal / bootstrap 三步不可自动化** —— 分界线是「凡是需要 unseal key 或 root token 的，永远人工」 | T-012 | ✅ |
| [`deploy-and-rollback.md`](deploy-and-rollback.md) | 部署红了怎么处置。先读判定（`ok` / `vault_sealed` / `broken`）再动手 —— **`vault_sealed` 不该回滚**。含手工回滚、从快照恢复、磁盘满了的清理 | T-012 | ✅ |
| [`drill-rollback-and-seal.md`](drill-rollback-and-seal.md) | **主动演练**（不是故障处置）：快照路径、封印判定、自动回滚三段，在 staging 上各跑一次。§13.8 要求「需要运维介入的事必须演练过才算交付」 | T-012 | ✅ 已执行（2026-09-05，[结果见 M0](../tasks/M0.md)）|
| [`email-dns.md`](email-dns.md) | 发信域的 SPF / DKIM / DMARC（`p=none`→`quarantine`→`reject` 分三阶段推进）、**通道切换**（R1 的处置）与**发信配额天花板的判读**。Q3 已决：域名邮箱（dogado，[ADR-0013](../adr/0013-mail-via-domain-mailbox.md)）。✅ SPF / DKIM 已由 dogado 自动写入（selector `cloudpit`），⚠️ 仍欠 `-all` 收紧、DMARC 阶段 ① 与实发验证 `d=` | T-102 | ✅ 含 2026-09-06 实测的 zone 现状 |
| `key-rotation.md` | Transit key 轮换与 rewrap。注意 `ncards-hmac` **不轮换**（轮换会让所有 HMAC 查找失效） | T-406 | ⏳ |
| `restore-from-backup.md` | 从备份恢复 Postgres + Vault。**上线前必须完成一次真实恢复演练**（不可裁剪项） | T-406 | ⏳ |
| `esp-failover.md` | 邮件服务商故障时的处置。§3.2 已接受"email 是单点故障"，**不做双活**，本文写的是降级与对外沟通口径。⚠️ 处置步骤已由 T-102 写进 `email-dns.md` §3，本文接手时**吸收**它而不是另写一份 | T-407 | ⏳ |
| `incident-response.md` | 安全事件响应，含 GDPR **72 小时**通报义务的判定与流程 | T-407 | ⏳ |

## 硬性约束（写 runbook 时不要写错）

- Vault unseal key（Shamir 3-of-5）离线保管，**绝不**进 CI、Ansible secrets 或任何仓库。
- staging **只用合成数据**，严禁复制生产数据，哪怕脱敏。
- 生产变更走：前置数据库快照 → 滚动重启 → 迁移 → 健康检查 → 失败自动回滚上一镜像 tag。
