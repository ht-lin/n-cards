# infra/ansible

Hetzner（Nürnberg）主机配置与部署 playbook。**T-012 交付**。

设计取舍见 [ADR-0009](../../docs/adr/0009-ansible-sops-deploy-topology.md)
（为什么是 Ansible + sops）与
[ADR-0010](../../docs/adr/0010-rollback-image-tag-only.md)（回滚只回镜像 tag）。

## 三条 playbook

所有命令都**从本目录跑** —— `ansible.cfg` 只在这里生效。

```bash
cd infra/ansible
ansible-galaxy collection install -r requirements.yml   # 首次
```

| playbook | 谁跑 | 频率 | 做什么 |
|---|---|---|---|
| `site.yml` | 人工（笔记本） | 建新主机时 | SSH 加固、Docker、swap、目录骨架 |
| `deploy.yml` | **CI，每次 main 合入** | 自动 | 快照 → 拉镜像 → 起栈 → 迁移 → 健康判定 → 失败回滚 |
| `rollback.yml` | 人工 | 按需 | 回到 `state/previous-image` |

```bash
# 一次性 provision（首次主机还是 root@22）
ansible-playbook -i inventory/staging.yml site.yml -e ansible_user=root -e ansible_port=22

# 部署
ansible-playbook -i inventory/staging.yml deploy.yml \
  -e ncards_app_image=ghcr.io/ht-lin/n-cards-backend:<sha>

# 首启那一次（Vault 还没 init，/health/ready 必然 503）
ansible-playbook -i inventory/staging.yml deploy.yml \
  -e ncards_app_image=ghcr.io/ht-lin/n-cards-backend:main \
  -e ncards_wait_for_ready=false -e ncards_run_migrations=false

# 回滚
ansible-playbook -i inventory/staging.yml rollback.yml
```

完整首启顺序（含 Vault 的三步人工操作）见
[`docs/runbooks/staging-first-boot.md`](../../docs/runbooks/staging-first-boot.md)。
部署红了见
[`docs/runbooks/deploy-and-rollback.md`](../../docs/runbooks/deploy-and-rollback.md)。

## 变量在哪

| 文件 | 放什么 |
|---|---|
| `inventory/group_vars/all/main.yml` | 两个环境共用：路径、compose 链、保留份数、超时 |
| `inventory/group_vars/<env>/main.yml` | 非敏感的环境差异：域名、SSH 端口、ACME、日志级别 |
| `inventory/group_vars/<env>/secrets.sops.yaml` | **凭据**，sops(age) 加密，**入库** |
| `roles/*/defaults/main.yml` | role 单独被调用时的兜底值（优先级最低） |

改凭据：`sops inventory/group_vars/ncards_staging/secrets.sops.yaml` —— 解密到编辑器，
存盘时自动重新加密。**不要** `sops -d` 到文件里。

**SSH 端口明文入库是刻意的。** 它不是秘密：换端口的价值是把互联网背景扫描的噪声
降下来，**不是安全边界**。做成 secret 只会让 inventory 读不懂。

⚠️ **deploy 用户有 NOPASSWD sudo，而且在 `docker` 组里（≡ root）。**
真正的安全边界是**谁持有 `DEPLOY_SSH_KEY`** —— 不是端口、不是文件权限、
也不是「没给 sudo」。完整推理见
[ADR-0009](../../docs/adr/0009-ansible-sops-deploy-topology.md) 的负面 Consequences。

## 三条禁令

1. **绝不 `docker compose down`，更绝不 `down -v`。**
   后者会删掉 `vault_data`（= 全部卡数据永久不可解密，§9.4）、`pg_data`、
   以及 `caddy_data`（证书没了，重签可能撞 Let's Encrypt 速率限制）。
2. **绝不在主机上构建镜像。** 部署的必须是 CI 验证过的那一个。三道锁：
   `build: never`、主机上没有 `backend/` 源码（`sync.yml` 只同步 `infra/`）、
   以及部署前独立的一步 `docker_image_pull`。
3. **绝不把生产数据搬进 staging**，哪怕脱敏（§14.1）。

另外：`docker system prune -af` 会删掉回滚目标，永远不要用
（`prune.yml` 只清 dangling + 保留最近 3 个 app tag）。

## 这台机器的余量账

实机 **2 vCPU / 4 GB / 40 GB**，比 §4.1 假设的 CCX23（4c/16G/160G）小一档 ——
这是 T-012 记录在案的第一条偏差。playbook 不假设规格，`preflight.yml` 每次量一遍
并落盘 `/opt/ncards/state/host-facts.json`。

- **内存**：staging 限额 app 512M + pg 1G + redis 256M ≈ 1.75G，加 caddy/vault
  约 1.85G，余 ~2G。够，但没有富余 —— 所以 `docker` role 装了 **2 GB swapfile**
  （Hetzner 云镜像默认无 swap，部署峰值会触发 OOM killer 杀掉 postgres，
  而部署流程会把那读成 `broken` 然后做一次无意义的回滚）。
- **CPU**：app 1.0 + postgres 1.0 正好吃满 2 vCPU。limits 是上限不是预留，
  平时无碍，但部署那几十秒会打满 —— 所以超时按小机器给（`wait_timeout: 120`），
  别按开发机的手感调小。
- **磁盘**（最紧的一项）：基础镜像合计 ≈ 1 G，保留 3 个 app tag 再 +~600M。
  快照前断言可用 > 5 G；`daemon.json` 的日志轮转（10m×3）在这块盘上不是可选项。

## 与 CI 的接法

`.github/workflows/deploy.yml`（reusable）装好 ansible-core + collections + sops，
配好 SSH，然后跑 `deploy.yml` 与冒烟测试。两个调用方：`main.yml` 的
`deploy-staging`（自动）与 `deploy-manual.yml`（人工，production 需 approval）。

⚠️ **Vault unseal key 绝不进 CI 或 Ansible secrets**（§14.3 / ADR-0004）。
Vault 封印时部署照样能完成，只是判定为 `vault_sealed` —— job 红着等人来 unseal，
**不触发回滚**。
