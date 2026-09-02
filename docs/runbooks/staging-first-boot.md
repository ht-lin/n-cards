# Runbook：staging 主机首启

一台新开的 Hetzner 主机 → 一个能被 `main` 合入自动部署的 staging 环境。
**从头到尾约 60–90 分钟**，其中等 DNS 生效与等证书签发占大半。

> 这套流程对**生产**同样适用，差别只有两处：域名、以及
> [ADR-0010](../adr/0010-rollback-image-tag-only.md) 里那条
> 「生产启用前必须先由 T-406 的加密备份替换部署前快照」。

## 触发条件

- 新开了一台 staging / 生产主机
- 现有主机被重装或迁移
- **不适用于**日常部署（那是自动的）与主机重启后的恢复
  （那只需要 [`vault-unseal.md`](vault-unseal.md)）

## 前置检查

```bash
# 1. 主机开着、能用 root + 口令或密钥登录（Hetzner 开机邮件里有）
ssh root@<主机IP>

# 2. 确认发行版与规格。期望：Ubuntu 24.04，≥ 2 vCPU / ≥ 3400 MB / ≥ 15 GB 可用
lsb_release -d && nproc && free -m | awk '/Mem:/{print $2" MB"}' && df -h /

# 3. 本机装好 ansible-core、sops、age
ansible --version && sops --version && age-keygen --version
```

需要准备好、且**不在**这台机器上的东西：

- 域名 `n-cards.de` 的 DNS 管理权限
- GitHub 仓库的 Settings 权限（建 Environment、填 secret）
- **三个能保管 Vault unseal key 的人**（Shamir 3-of-5，[ADR-0004](../adr/0004-manual-vault-unseal.md)）

---

## 步骤

分界线只有一句：**凡是需要 unseal key 或 root token 的，永远是人工；其余全自动。**

下面 1–11 步是**一次性人工操作**，第 12 步之后就全自动了。

### 1. DNS（人工，一次性）

给 `api.staging.n-cards.de` 加 A 记录指向主机 IPv4。

> ⚠️ **要么 A/AAAA 都配，要么都不配。** 只配 AAAA 而主机 IPv6 没通的话，
> Let's Encrypt 的 HTTP-01 挑战会优先挑 v6，然后超时 —— 现象是「证书一直签不出来」
> 而 `curl -4` 一切正常，很费时间。

```bash
# 期望：返回主机 IP。没生效就等，别往下走 —— 后面 ACME 会失败。
dig +short api.staging.n-cards.de
```

### 2. age 密钥对（人工，一次性）

**两把，不是一把。** 理由见 [ADR-0009](../adr/0009-ansible-sops-deploy-topology.md)：
生产私钥要锁进 GitHub Environment，由 approval 守着。

```bash
age-keygen -o staging.age
age-keygen -o production.age
# 期望：各打印一行 "Public key: age1…"
grep 'public key' staging.age production.age
```

把两个**公钥**填进仓库根 [`.sops.yaml`](../../.sops.yaml) 对应的 `age:` 字段。

> ⚠️ 两个**私钥**文件：离线保管（密码管理器 / 加密 U 盘），
> 且**与 Vault 的 unseal key 由不同的人保管**。同一个人同时持有「配置解密权」
> 与「数据解密权」的话，ADR-0004 的 3-of-5 门限就白设了。
> 保管好之后从磁盘删掉：`shred -u staging.age production.age`

### 3. 部署 SSH 密钥（人工，一次性）

```bash
ssh-keygen -t ed25519 -f ncards-deploy -C 'ncards-deploy@github-actions' -N ''
cat ncards-deploy.pub
```

把**公钥**填进
[`infra/ansible/inventory/group_vars/ncards_staging/main.yml`](../../infra/ansible/inventory/group_vars/ncards_staging/main.yml)
的 `ncards_deploy_authorized_keys`。

> 加固会禁掉 root 登录与口令登录。这个列表是空的时候 playbook 会**拒绝执行**
> 并告诉你原因 —— 那道断言就是防「把自己锁在门外」。

### 4. 主机 provision（人工，一次性）

> ⚠️ **另开一个 root 会话并保持它开着。** 这一步会改 SSH 端口、禁 root 登录、
> 开防火墙。改砸了还能从那个会话救回来；否则只能走 Hetzner Console 的网页终端。

```bash
cd infra/ansible
ansible-galaxy collection install -r requirements.yml

# 首次：主机还是 root@22
ansible-playbook -i inventory/staging.yml site.yml -e ansible_user=root -e ansible_port=22
```

做了什么：deploy 用户 + authorized_keys、sshd 加固（端口 2242、禁 root、仅密钥）、
关掉 `ssh.socket`、ufw（只开 2242/80/443/443udp）、fail2ban（`backend=systemd`）、
Docker CE + compose plugin、日志轮转、**2 GB swapfile**、目录骨架。

验证（**别关那个 root 会话**）：

```bash
ssh -p 2242 deploy@api.staging.n-cards.de 'docker version --format "{{.Server.Version}}"'
# 期望：打印版本号，且全程没问口令
ssh -p 22 -o ConnectTimeout=5 root@api.staging.n-cards.de 2>&1 | tail -1
# 期望：Connection timed out（22 已关）
```

确认无误后再关掉 root 会话。

### 5. known_hosts（人工，一次性）

```bash
ssh-keyscan -p 2242 api.staging.n-cards.de 2>/dev/null
```

整段输出填进 GitHub repository secret `DEPLOY_KNOWN_HOSTS`。

> 部署流水线刻意**不用** `StrictHostKeyChecking=no` —— 那等于「连上什么都行」，
> 一次 DNS 劫持就能把部署密钥和整份 `.env`（含数据库口令与 Vault secret_id）
> 交给别人。

同时建好 GitHub 侧的其余配置：

| 类型 | 名字 | 值 |
|---|---|---|
| Environment | `staging` | 无保护规则 |
| Environment | `production` | required reviewers = 你；deployment branches = 仅 `main` |
| Repository secret | `DEPLOY_SSH_KEY` | 第 3 步的**私钥全文** |
| Repository secret | `DEPLOY_KNOWN_HOSTS` | 上面的 `ssh-keyscan` 输出 |
| Repository secret | `SOPS_AGE_KEY` | 第 2 步 **staging** 的 `AGE-SECRET-KEY-1…` |
| Environment secret（`production`） | 同上三个 | 生产主机就绪后再填 |

还要把 GHCR 上的 `n-cards-backend` 包设为 **public**（Packages → Package settings
→ Change visibility）。理由：镜像里不含任何凭据（`.env` 由 sops 单独下发，
`.dockerignore` 剥掉了 tests），公开镜像层清单的风险小于长期持有一个 classic PAT。
不想公开的话，改用 `GHCR_PULL_USER` / `GHCR_PULL_TOKEN`（`read:packages`）
并在 `ncards_stack` 里加一步 `docker_login`。

### 6. 写入凭据（人工，一次性）

```bash
cd infra/ansible/inventory/group_vars/ncards_staging
cp secrets.sops.yaml.example secrets.sops.yaml

# 生成真值填进去（VAULT_* 两个先留占位符不动）
openssl rand -hex 32                              # → APP_SECRET
openssl rand -base64 33 | tr -d '/+=' | head -c 40 # → POSTGRES_PASSWORD

sops -e -i secrets.sops.yaml     # 就地加密
head -3 secrets.sops.yaml        # 期望：看到 ENC[AES256_GCM,… 而不是明文
```

> ⚠️ **`VAULT_ROLE_ID` / `VAULT_SECRET_ID` 这一步保持
> `pending-bootstrap-see-runbook` 不变。** Vault 还没初始化，真值还不存在；
> 而 `docker-compose.prod.yml` 把这两个变量设成了必填（`${VAR:?}`），
> 留空的话 compose 直接报错起不来。占位值能让整个栈先起来 ——
> `VaultHealthCheck` 只打**免认证**的 `sys/health`，所以 unseal 之后
> `/health/ready` 照样 200，只有真正的涉密请求才 503。

### 7. 第一次部署（人工，一次性）

Vault 还没 init，所以跳过就绪判定与迁移：

```bash
cd infra/ansible
ansible-playbook -i inventory/staging.yml deploy.yml \
  -e ncards_app_image=ghcr.io/ht-lin/n-cards-backend:main \
  -e ncards_wait_for_ready=false \
  -e ncards_run_migrations=false
```

```bash
# 期望：五个容器 Up（vault 是 Up 但未初始化）
ssh -p 2242 deploy@api.staging.n-cards.de \
  'cd /opt/ncards && docker compose -f infra/compose/docker-compose.base.yml -f infra/compose/docker-compose.prod.yml -f infra/compose/docker-compose.staging.yml ps'
```

证书这时应该已经签出来了（Caddy 在启动时就去要）：

```bash
curl -sSI https://api.staging.n-cards.de/health/live | head -1
# 期望：HTTP/2 200
```

签不出来的话看 `docker compose logs caddy`，八成是 DNS 没生效或 ufw 挡了 80。

### 8. Vault init + unseal（**人工，一次性，不可自动化**）

这一步需要 **3 个 unseal key 持有人到场**。详见
[`vault-unseal.md`](vault-unseal.md) 的「情形 A：首次初始化」。

```bash
ssh -p 2242 deploy@api.staging.n-cards.de
cd /opt/ncards
export COMPOSE_FILE=infra/compose/docker-compose.base.yml:infra/compose/docker-compose.prod.yml:infra/compose/docker-compose.staging.yml

docker compose exec vault vault operator init -key-shares=5 -key-threshold=3
```

> ⚠️ **输出里的 5 把 unseal key 与 1 个 root token 只出现这一次。**
> 5 把 key 分发给 5 个人离线保管（≥2 个物理位置）。
> **绝不**进 CI、Ansible secrets、聊天工具或任何仓库。
> Vault 数据丢失 = 全部卡数据永久不可解密（§9.4）。

```bash
docker compose exec vault vault operator unseal   # 跑 3 次，每次一个人输一把
docker compose exec vault vault status            # 期望：Sealed = false
```

### 9. Vault bootstrap + 签发 AppRole（**人工，一次性**）

用一个**用完即吊销**的 root token 跑一次 `bootstrap.sh`：

```bash
docker run --rm --network ncards_backing \
  -e VAULT_ADDR=http://vault:8200 \
  -e VAULT_TOKEN=<第 8 步的 root token> \
  -v /opt/ncards/infra/vault:/vault/bootstrap:ro \
  $(docker build -q /opt/ncards/infra/vault)
# 期望：末尾打印 VAULT_ROLE_ID=… 与手工签发 secret_id 的命令
```

按它打印的命令签发一个 `secret_id`（脚本刻意不自动生成、不打印），然后：

```bash
docker compose exec vault vault token revoke -self   # 期望：Success
```

### 10. 写回真凭据（人工，一次性）

在**本机**：

```bash
sops infra/ansible/inventory/group_vars/ncards_staging/secrets.sops.yaml
# 把 VAULT_ROLE_ID / VAULT_SECRET_ID 的占位符换成第 9 步拿到的真值，存盘即自动重新加密
```

提交 → PR → 合入。密文入库是刻意的（[ADR-0009](../adr/0009-ansible-sops-deploy-topology.md)）。

### 11. 完整部署并验收（人工，验证一次）

```bash
cd infra/ansible
ansible-playbook -i inventory/staging.yml deploy.yml \
  -e ncards_app_image=ghcr.io/ht-lin/n-cards-backend:main
# 期望：判定 ok
```

### 12. 之后全自动

每次 `main` 合入，`.github/workflows/main.yml` 的 `deploy-staging` 自动跑同一条
`deploy.yml`。无需人工介入。

### 13. 主机重启之后（人工，按需）

Vault 会**重新封印**，`/health/ready` 变 503，这是期望行为
（[ADR-0004](../adr/0004-manual-vault-unseal.md)）。
按 [`vault-unseal.md`](vault-unseal.md) 找 3 个人 unseal 即可，
**不需要**重新部署，也不需要重跑本 runbook。

---

## 验证

全部做完后，从**任意一台**机器上跑：

```bash
scripts/ci/smoke-staging.sh https://api.staging.n-cards.de
```

期望全绿，逐条对应 T-012 的验收标准：

- `/health/live` 经真实 TLS 返回 200 `{"status":"ok"}`
- 五个安全头齐全（HSTS / CSP / nosniff / Referrer-Policy / X-Frame-Options）
- 无 `Server` / `X-Powered-By` / `Via` 版本回显
- HTTP 自动 308 到 HTTPS

主机加固另外验一遍：

```bash
ssh -p 22 -o ConnectTimeout=5 root@api.staging.n-cards.de   # 期望：超时
ssh -p 2242 root@api.staging.n-cards.de                     # 期望：Permission denied
ssh -p 2242 -o PubkeyAuthentication=no deploy@api.staging.n-cards.de  # 期望：Permission denied
ssh -p 2242 deploy@api.staging.n-cards.de 'sudo ufw status numbered'  # 期望：只有 4 条规则
ssh -p 2242 deploy@api.staging.n-cards.de 'swapon --show'             # 期望：/swapfile 2G
```

fail2ban 真的在工作（连错 3 次后）：

```bash
# 期望：Currently banned 至少 1，且 backend 是 systemd
sudo fail2ban-client status sshd
```

## 失败回滚

**第 1–7 步失败**：无损。主机上还没有任何数据，修掉原因重跑即可
（playbook 是幂等的）。最坏情况在 Hetzner Console 里重装系统从头再来。

**第 8 步之后失败**：⚠️ 从这里开始 Vault 里有了密钥材料。**不要重装、不要
`docker compose down -v`** —— 那会删掉 `vault_data` 卷，等于让所有已加密数据
永久不可解密。此时只该往前修，不该重来。

**任何时候都不要**：

```bash
docker compose down -v      # ← 删卷。vault_data / pg_data / caddy_data 一起没
```

## 升级路径（找谁）

| 卡在哪 | 找谁 |
|---|---|
| DNS / 域名 | 域名注册商的管理账号持有人 |
| 主机开不了机、网络不通 | Hetzner 支持（工单里带 server ID） |
| 证书签不出来 | 先自查 DNS + ufw 80 + `docker compose logs caddy`；仍不行看 Let's Encrypt 速率限制（换 `ACME_CA` 到 staging 目录排练） |
| Vault init / unseal | 3 位 unseal key 持有人 —— **没有任何技术手段能绕过** |
| SSH 把自己锁在门外 | Hetzner Cloud Console 的网页终端（不走 SSH） |
