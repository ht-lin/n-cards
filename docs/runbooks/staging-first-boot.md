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
ssh root@<主机IPv4>

# 2. 确认发行版与规格。期望：Ubuntu 24.04，≥ 2 vCPU / ≥ 3400 MB / ≥ 15 GB 可用
lsb_release -d && nproc && free -m | awk '/Mem:/{print $2" MB"}' && df -h /

# 3. 主机有公网 IPv4。期望：一行 inet <公网地址>
ip -4 addr show scope global | grep inet

# 4. 本机装好 ansible-core、sops、age
#    期望：core ≥ 2.15（见下），sops ≥ 3.8，age ≥ 1.0
ansible --version | head -1 && sops --version && age-keygen --version
```

> ⚠️ **发行版自带的 ansible 大概率太旧，且失败得不像版本问题。**
> `requirements.yml` 里钉的三个 collection 都要求 **ansible-core ≥ 2.15**，
> 而 Ubuntu 22.04 的 `apt install ansible` 给的是 2.12。更麻烦的是它配的
> `python3-resolvelib` 是 0.8.1，2.12 只兼容 `<0.6` —— 于是第 4 步的
> `ansible-galaxy collection install` 直接崩在依赖解析器上：
> `CollectionDependencyProvider.find_matches() got an unexpected keyword
> argument 'identifier'`。这条报错跟「版本太旧」看不出任何关系，别顺着它查。
>
> 装进 venv，别用 apt：
>
> ```bash
> sudo apt install -y python3-venv
> python3 -m venv ~/.venvs/ansible
> ~/.venvs/ansible/bin/pip install -U pip 'ansible-core>=2.17,<2.18'
> sudo apt remove -y ansible-core        # 否则 PATH 里先命中的还是 2.12
> echo 'export PATH="$HOME/.venvs/ansible/bin:$PATH"' >> ~/.bashrc
> ```
>
> 上限 2.17 是被控制端 Python 卡住的：22.04 是 Python 3.10，
> 而 ansible-core 2.18 起要求控制端 ≥ 3.11。换更新的发行版再放开上限。

> ⚠️ **Hetzner Cloud Firewall 里必须有 2242 —— 它是独立于主机 ufw 的另一层。**
> 建机时选了防火墙模板的话，入站允许列表通常是 22/80/443；而第 4 步会把 SSH
> 搬到 2242，于是 playbook 走到「确认新 SSH 端口已在监听」就卡死 ——
> 主机上 sshd 好好听着，包在云防火墙那层就被丢了。
>
> 进 Console → Firewalls → 这台机器挂的规则集，入站加两条
> （Source 都是 `0.0.0.0/0, ::/0` —— GitHub runner 的出口 IP 是漂的，收不窄）：
> **TCP 2242**，以及 **UDP 443**（HTTP/3；模板一般只给 TCP 443，漏了它的现象是
> 「偶发首包慢」，非常难查）。**22 先留着**，等 2242 验证通了再删。
>
> 分不清卡在云防火墙还是主机上，看失败方式：主机拒绝会立刻回 RST
> （`nc` 瞬间返回），云防火墙是静默丢包（等满超时）。
>
> ```bash
> # 期望：22 立刻 refused（主机可达），2242 succeeded
> nc -zv -w5 api.staging.n-cards.de 22
> nc -zv -w5 api.staging.n-cards.de 2242
> ```

> ⚠️ **IPv4 不是可选项，尽管 Hetzner Cloud 建机时可以取消勾选。** 两条硬依赖：
> **入站** —— GitHub Actions 的托管 runner 至今没有 IPv6，
> [`deploy.yml`](../../.github/workflows/deploy.yml) 的 SSH 部署与冒烟测试
> 都是从 runner 打过来的；**出站** —— `ghcr.io` 与 `github.com` 都没有 AAAA 记录，
> 主机拉镜像走的是 IPv4。IPv6-only 的机器上这两件事都不成立（能靠
> Hetzner 的 DNS64/NAT64 兜住出站，但那是一条藏在 `/etc/resolv.conf` 里的依赖，
> 谁改了 DNS 谁就会看到一条看不出跟网络有关的 `i/o timeout`）。
> 每月约 €0.50 的 primary IP 比绕过它的任何方案都便宜。
>
> IPv6 反过来是可选的：Hetzner 默认还会给一个 /64，配不配 AAAA 都行 —— 见第 1 步。

需要准备好、且**不在**这台机器上的东西：

- 域名 `n-cards.de` 的 DNS 管理权限
- GitHub 仓库的 Settings 权限（建 Environment、填 secret）
- **三个能保管 Vault unseal key 的人**（Shamir 3-of-5，[ADR-0004](../adr/0004-manual-vault-unseal.md)）

---

## 步骤

分界线只有一句：**凡是需要 unseal key 或 root token 的，永远是人工；其余全自动。**

下面 1–11 步是**一次性人工操作**，第 12 步之后就全自动了。

### 1. DNS（人工，一次性）

给 `api.staging.n-cards.de` 加 A 记录指向主机 IPv4。主机上那个 IPv6 /64
要不要一并配 AAAA，随意 —— 只配 A 是完全正常的配置。

> ⚠️ **规则不是「都配」或「都不配」，而是「配了的必须都通」。**
> Let's Encrypt 的 HTTP-01 挑战按解析结果挑地址族，且**优先挑 v6**。
> 于是配了 AAAA 而主机 IPv6 实际没通的话，挑战会一路超时 ——
> 现象是「证书一直签不出来」而 `curl -4` 一切正常，很费时间。
> 拿不准 v6 通不通就别配 AAAA；A 记录一条足够。

```bash
# 期望：A 返回主机 IPv4。没生效就等，别往下走 —— 后面 ACME 会失败。
dig +short A api.staging.n-cards.de
# 配了 AAAA 才需要看这条；返回什么就得保证那个地址真的能从公网连上 80。
dig +short AAAA api.staging.n-cards.de
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

把私钥放到 ssh 找得到的地方，并配上端口与用户 —— 否则第 4 步之后
ansible 与本文档里所有 `ssh` 命令都会撞上 `Permission denied (publickey)`：
ssh 默认只提供 `~/.ssh/id_*`，而 `authorized_key` 是 `exclusive: true` 的，
主机上除了这把钥匙没有第二把，root 登录也已经关了。

```bash
mv ncards-deploy ~/.ssh/ncards-deploy && chmod 600 ~/.ssh/ncards-deploy
cat >> ~/.ssh/config <<'EOF'
Host api.staging.n-cards.de api.n-cards.de
    User deploy
    Port 2242
    IdentityFile ~/.ssh/ncards-deploy
    IdentitiesOnly yes
EOF
chmod 600 ~/.ssh/config
```

> 写进 `~/.ssh/config` 而不是 `ansible.cfg` 的 `private_key_file`：后者是仓库里的
> 文件，钉一条 `/home/<你>/…` 的绝对路径进去，换台机器或换个人就不成立了。
> 第 4 步**之前**这条配置不生效也无所谓 —— 那一次是 `root@22`。
>
> 命令行参数照样赢过 config，所以验证用的 `ssh -p 22 root@…` 一类命令不受影响。

### 4. 主机 provision（人工，一次性）

> ⚠️ **另开一个 root 会话并保持它开着。** 这一步会改 SSH 端口、禁 root 登录、
> 开防火墙。改砸了还能从那个会话救回来；否则只能走 Hetzner Console 的网页终端。

```bash
cd infra/ansible
ansible-galaxy collection install -r requirements.yml

# 首次：主机还是 root@22
ansible-playbook -i inventory/staging.yml site.yml -e ansible_user=root -e ansible_port=22
```

> ⚠️ **这一步中途失败后重跑，命令不一样 —— 那两个 `-e` 必须去掉。**
> 只要 playbook 跑过了 sshd 那几步（即使后面失败了），root 登录就已经禁了、
> 22 也不再监听，再指定 `ansible_user=root -e ansible_port=22` 必然连不上。
> 重跑一律用默认值，role 开头的探测会自己认出 2242 并切过去：
>
> ```bash
> ansible-playbook -i inventory/staging.yml site.yml
> ```

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
| Environment | `production` | deployment branches = 仅 `main`（见下） |
| Repository secret | `DEPLOY_SSH_KEY` | 第 3 步的**私钥全文** |
| Repository secret | `DEPLOY_KNOWN_HOSTS` | 上面的 `ssh-keyscan` 输出 |
| Repository secret | `SOPS_AGE_KEY` | 第 2 步 **staging** 的 `AGE-SECRET-KEY-1…` |
| Environment secret（`production`） | 同上三个 | 生产主机就绪后再填 |

> ⚠️ **`production` 上没有 required reviewers，这不是漏配。** Free 计划的私有仓库
> 建不出这条保护规则（API 以 billing plan 拒绝）。同一页上另外两项 Free 可用、
> 也确实配上了：Deployment branches = 仅 `main`，以及三个 environment secret
> （同名覆盖 repository secret —— 生产的 age 私钥只在生产部署的 job 里存在）。
> approval 这一半降级到了 `deploy-manual.yml` 的 guard 里：生产发布要在
> `confirm` 框原样输入 `deploy-production`。取舍与解除条件见
> [ADR-0010](../adr/0010-rollback-image-tag-only.md) 的「其他」段。
>
> UI 里那两项是灰的就对了。API 侧可以一次配好：
>
> ```bash
> gh api -X PUT repos/ht-lin/n-cards/environments/production --input - <<'EOF'
> {"deployment_branch_policy":{"protected_branches":false,"custom_branch_policies":true}}
> EOF
> gh api -X POST repos/ht-lin/n-cards/environments/production/deployment-branch-policies \
>   -f name=main -f type=branch
> ```

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

接着第 8 步那个 ssh 会话（`COMPOSE_FILE` 已经导出）。整步用一个**用完即吊销**的
root token，先把它放进这个 shell —— 下面四条命令都要用：

```bash
read -rs VAULT_TOKEN && export VAULT_TOKEN
# 粘贴第 8 步 operator init 输出里的 Initial Root Token（hvs.… 开头），回车。
# 不回显、不进 history。⚠️ 别粘成 5 把 Unseal Key 之一 —— 它们挨在一起。

# 期望：policies 里有 root。403 = 粘错了或粘空了，别往下走
docker compose exec -e VAULT_TOKEN="$VAULT_TOKEN" vault vault token lookup
```

> ⚠️ **空的 `VAULT_TOKEN` 报的是 403 `permission denied`，不是 400。**
> 看到 403 的第一反应该是「token 没带进去」，而不是「权限配错了」——
> root token 对任何路径都不可能被拒。先 `echo "len=${#VAULT_TOKEN}"` 看是不是 0。

跑一次 `bootstrap.sh`：

```bash
docker run --rm --network ncards_backing \
  -e VAULT_ADDR=http://vault:8200 \
  -e VAULT_TOKEN="$VAULT_TOKEN" \
  -v /opt/ncards/infra/vault:/vault/bootstrap:ro \
  $(docker build -q /opt/ncards/infra/vault)
# 期望：末尾打印 VAULT_ROLE_ID=… 与手工签发 secret_id 的命令
```

签发 `secret_id`（脚本刻意不自动生成、不打印）。
**⚠️ 是两个 AppRole，两对值，一个都不能少**：

| AppRole | 写进 secrets.sops.yaml 的 | 谁用 |
|---|---|---|
| `ncards-app` | `VAULT_ROLE_ID` / `VAULT_SECRET_ID` | 应用运行时（加解密、读 JWT 签名密钥） |
| `ncards-policy` | `VAULT_POLICY_ROLE_ID` / `VAULT_POLICY_SECRET_ID` | 部署流水线下发并对账 policy（T-114）。**没有任何 transit** |

```bash
for ROLE in ncards-app ncards-policy; do
  echo "--- $ROLE"
  docker compose exec -e VAULT_TOKEN="$VAULT_TOKEN" vault \
    vault read -field=role_id "auth/approle/role/$ROLE/role-id"
  echo
  docker compose exec -e VAULT_TOKEN="$VAULT_TOKEN" vault \
    vault write -f -field=secret_id "auth/approle/role/$ROLE/secret-id"
  echo
done
# 期望：四行裸值。两个 secret_id 只出现这一次
# role_id 不是秘密，滚没了随时能再读；secret_id 滚没了只能重新签发一枚
```

> ⚠️ **别省掉 `ncards-policy` 那一对。** 少了它，policy 就只有今天被下发过一次，
> 之后每一次 `infra/vault/policies/*.hcl` 的改动都到不了这台机器 —— 那正是
> T-114 的故障：T-104 在 9-07 给 `ncards-app.hcl` 加的 JWT 路径始终没到
> staging，`POST /auth/otp/verify` 503，而 `/health/ready` 与冒烟测试全绿。
> 而且补起来很贵：root token 在本步末尾就吊销了，之后要补这一对得重新找
> **3 位 unseal key 持有人**跑一次 `generate-root`（见本文末尾那一节）。

> ⚠️ **`bootstrap.sh` 打印的那条 `curl` 不能直接贴到宿主机上。** 它里面的
> `http://vault:8200` 是**容器视角**的地址 —— `vault` 这个名字只在 compose 的
> `ncards_backing` 网络里解析得出来，而那个网络是 `internal: true`，vault 也刻意
> 没有 `ports:`。在宿主机 shell 里跑必然是 `Could not resolve host: vault`。
> 上面的 `docker compose exec` 版本等价，且不需要另起容器。

**四个值都拿到之后**才收尾 —— `revoke -self` 必须是本步最后一条：

```bash
docker compose exec -e VAULT_TOKEN="$VAULT_TOKEN" vault vault token revoke -self
# 期望：Success
unset VAULT_TOKEN
```

> ⚠️ 先吊销再签发的话，后面每条命令都会 403，且**没有退路能拿回同一个 token**。
> 补救要重新找 3 位 unseal key 持有人跑 `vault operator generate-root`
> （见 [`vault-unseal.md`](vault-unseal.md)）。

### 10. 写回真凭据（人工，一次性）

在**本机**。这是整份 runbook 里**第一次需要解密**，所以要把第 2 步存到离线的
staging **私钥**取回来用一次 —— 第 6 步的加密只用到公钥，缺私钥不会报错，
问题拖到这里才暴露：

```
Failed to get the data key required to decrypt the SOPS file.
  age1…: FAILED - identity did not match any of the recipients
```

从离线备份里取 staging 那把的 `AGE-SECRET-KEY-1…` **那一行**
（不要 `# created:` / `# public key:` 注释行）：

```bash
read -rs SOPS_AGE_KEY && export SOPS_AGE_KEY   # 粘贴后回车。不回显、不进 history、不落盘

# 先确认拿的是 staging 那把而不是 production 的。
# 期望：与 .sops.yaml 里 ncards_staging 那条规则的 age: 值逐字相同
printf '%s\n' "$SOPS_AGE_KEY" | age-keygen -y
```

对上了再编辑：

```bash
sops infra/ansible/inventory/group_vars/ncards_staging/secrets.sops.yaml
# 把**四个** VAULT_* 占位符换成第 9 步拿到的真值，存盘即自动重新加密：
#   VAULT_ROLE_ID / VAULT_SECRET_ID          （ncards-app，应用运行时）
#   VAULT_POLICY_ROLE_ID / VAULT_POLICY_SECRET_ID  （ncards-policy，policy 下发，T-114）

unset SOPS_AGE_KEY
head -3 infra/ansible/inventory/group_vars/ncards_staging/secrets.sops.yaml
# 期望：看到 ENC[AES256_GCM,… 而不是明文
```

> ⚠️ **别写进 `~/.config/sops/age/keys.txt`。** sops 确实会自动读那里，但那等于
> 把私钥长期留在一台日常开发机上，正好抵消第 2 步「离线保管、且与 unseal key
> 分人」的整个设计。用 `SOPS_AGE_KEY` 传一次、用完 `unset`。

提交 → PR → 合入。密文入库是刻意的（[ADR-0009](../adr/0009-ansible-sops-deploy-topology.md)）。

### 11. 完整部署并验收（人工，验证一次）

```bash
cd infra/ansible
ansible-playbook -i inventory/staging.yml deploy.yml \
  -e ncards_app_image=ghcr.io/ht-lin/n-cards-backend:main
# 期望：判定 ok
# 期望：中间那条 “Vault policy 对账结果” 打出 “✓ policy 与仓库一致”
```

⚠️ 如果对账那一条说的是「跳过」，回第 10 步看 `VAULT_POLICY_*` 是不是还留着占位符 ——
**跳过不是通过**，那意味着这台机器上仓库与 Vault 之间没有任何门禁。

最后真机走一遍登录，这是唯一能证明「AppRole + policy + JWT 签名密钥」三者
都通的判据（`/health/ready` 200 证明不了，它打的是免认证的 `sys/health`）：

```bash
# 在本机。收件地址用第 12 步那个运维专用别名
SMOKE_OTP_EMAIL=smoke-staging@n-cards.de \
SMOKE_IMAP_HOST=... SMOKE_IMAP_USER=... SMOKE_IMAP_PASSWORD=... \
  scripts/ci/smoke-otp.py https://api.staging.n-cards.de
# 期望：verify 200
```

### 12. 冒烟收件箱与 GitHub secret（人工，一次性）

T-114 的 OTP 冒烟要**读信**。这个邮箱套餐只有一个账号 + 任意多个只收别名
（[`email-dns.md`](email-dns.md) 的「一个账号 + 只收别名」），所以：

1. 在 dogado 面板建一个**只收别名** `smoke-staging@n-cards.de`，转进
   `no-reply@n-cards.de` 那个唯一的邮箱账号；
2. 加三个 **repository secret**：`SMOKE_IMAP_HOST`（面板上抄，别凭印象写）、
   `SMOKE_IMAP_USER`（就是主账号地址）、`SMOKE_IMAP_PASSWORD`；
3. `main.yml` 里 `smoke_otp_email` 已经填了这个地址 —— 三个 secret 配齐之前
   那一步会红。

> ⚠️ 这条冒烟**有写副作用**：每次 `main` 合入发一封信，且首次 `verify` 成功会
> 在 staging 库里**建一行 users**（§6.2：首次验证即注册）。合成数据，可接受。
> 但 staging 与生产**共用同一个发信账号与配额**，所以别把它挪去压测。
>
> ⚠️ 生产（`deploy-manual.yml`）刻意留空不跑 —— 往生产塞一个合成账号
> 要单独决定，不该跟着一次手工部署顺手发生。

### 13. 之后全自动

每次 `main` 合入，`.github/workflows/main.yml` 的 `deploy-staging` 自动跑同一条
`deploy.yml`。无需人工介入 —— 其中包括**每次都把 `infra/vault/policies/*.hcl`
下发进 Vault 并逐字对账**（T-114 的 `vault-policy` 任务）。

### 14. 主机重启之后（人工，按需）

Vault 会**重新封印**，`/health/ready` 变 503，这是期望行为
（[ADR-0004](../adr/0004-manual-vault-unseal.md)）。
按 [`vault-unseal.md`](vault-unseal.md) 找 3 个人 unseal 即可，
**不需要**重新部署，也不需要重跑本 runbook。

（封印期间的部署里，policy 对账那一步会打「跳过，这不是漂移」并放行 ——
解封之后下一次部署自己会对上，同样不需要为它单独做什么。）

---

## 已经首启过的环境怎么补上（`generate-root` 仪式）

**触发条件**：这台机器在 T-114 之前就首启过，于是
`secrets.sops.yaml` 里没有 `VAULT_POLICY_*`，Vault 里也没有 `ncards-policy`
这份 policy 和这个同名 AppRole。症状是每次部署打「跳过 policy 对账」。

**为什么非要 root**：第 9 步末尾已经 `revoke -self` 了，而这里要做的两件事 ——
写 `ncards-policy` 这份新 policy、建 `ncards-policy` 这个 AppRole —— 都在
`ncards-app` 现有的权限之外（这台机器上还没有任何身份能写 policy，
本来就是本卡要修的那个洞）。**这是最后一次需要 root 的 policy 变更**：
之后每一次 `*.hcl` 的改动都由部署流水线自己下发（`ncards-policy.hcl`
自己除外，见下面那条注）。

**需要**：3 位 unseal key 持有人到场（[ADR-0004](../adr/0004-manual-vault-unseal.md) 的 3-of-5）。

```bash
ssh -p 2242 deploy@api.staging.n-cards.de
cd /opt/ncards
export COMPOSE_FILE=infra/compose/docker-compose.base.yml:infra/compose/docker-compose.prod.yml:infra/compose/docker-compose.staging.yml

# 1) 生成一个一次性 root token（三条命令，见 vault-unseal.md）
docker compose exec vault vault operator generate-root -init   # 记下 OTP 与 nonce
docker compose exec vault vault operator generate-root         # 3 人各输一把 key + 同一个 nonce
docker compose exec vault vault operator generate-root -decode=<encoded-token> -otp=<OTP>

read -rs VAULT_TOKEN && export VAULT_TOKEN   # 粘上一条解出来的 token
docker compose exec -e VAULT_TOKEN="$VAULT_TOKEN" vault vault token lookup
# 期望：policies 里有 root

# 2) 重跑一次 bootstrap.sh。⚠️ 它是幂等的，**不会**碰任何已存在的密钥材料
#    （transit key 与 JWT 签名密钥都是「已存在就跳过」）。
#    这一趟做的就两件新事：写 ncards-policy 这份 policy、建同名 AppRole。
#    （顺带把 ncards-app / ncards-ops 也对齐到仓库当前版本 —— 那正是积压的漂移。）
docker run --rm --network ncards_backing \
  -e VAULT_ADDR=http://vault:8200 \
  -e VAULT_TOKEN="$VAULT_TOKEN" \
  -v /opt/ncards/infra/vault:/vault/bootstrap:ro \
  $(docker build -q /opt/ncards/infra/vault)
# 期望：末尾打印 VAULT_ROLE_ID=… 与 VAULT_POLICY_ROLE_ID=… 两行

# 3) 签发 ncards-policy 的 secret_id（ncards-app 那一对没变，不用动）
docker compose exec -e VAULT_TOKEN="$VAULT_TOKEN" vault \
  vault write -f -field=secret_id auth/approle/role/ncards-policy/secret-id

# 4) ⚠️ 立刻吊销。顺序反了就得再来一次 generate-root
docker compose exec -e VAULT_TOKEN="$VAULT_TOKEN" vault vault token revoke -self
unset VAULT_TOKEN
```

回本机，按第 10 步把 `VAULT_POLICY_ROLE_ID` / `VAULT_POLICY_SECRET_ID` 写进
`secrets.sops.yaml`，提交合入，然后：

```bash
cd infra/ansible
ansible-playbook -i inventory/staging.yml deploy.yml \
  -e ncards_app_image=ghcr.io/ht-lin/n-cards-backend:main --tags vault-policy
# 期望：“✓ 已下发 ncards-app” + “✓ policy 与仓库一致”
#       —— 第一次跑必然有东西要下发，那就是积压到今天的全部漂移
```

最后真机走一遍登录确认（`scripts/ci/smoke-otp.py`，见第 11 步）。

> **生产同样适用。** 生产此刻还没开机，所以按本 runbook 从第 1 步走下来就会
> 顺带把 `ncards-policy` 建好，用不上这一节。

> ⚠️ **以后改 `ncards-policy.hcl` 本身，还要再走一次这个仪式。**
> 那份 policy 对自己那条路径**只有 `read`** —— 有 `update` 就能把自己改写成
> `path "*" { capabilities = [..., "sudo"] }`，那等于它不存在。
> 代价是真实的、也是刻意的。改 `ncards-app.hcl` 与 `ncards-ops.hcl` 不受此限，
> 部署会自己下发。

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
ssh -p 2242 deploy@api.staging.n-cards.de 'sudo ufw status numbered'  # 期望：8 行（见下）
ssh -p 2242 deploy@api.staging.n-cards.de 'swapon --show'             # 期望：/swapfile 2G
```

> ufw 那条是**四条**规则（2242/tcp、80/tcp、443/tcp、443/udp），但主机有 IPv6 时
> 每条会列两遍 —— 一条 v4、一条带 `(v6)` 后缀 —— 所以看到的是 8 行。
> Ubuntu 的 `/etc/default/ufw` 默认 `IPV6=yes`，而 Hetzner 默认给每台机器一个 /64，
> 于是 8 行才是常态；主机真没有 IPv6 时才是 4 行。数字对不上先看是不是把 v6 那半算漏了，
> 再怀疑 playbook。

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
| 证书签不出来 | 先自查 DNS（尤其「配了 AAAA 但主机 v6 不通」，见第 1 步）+ ufw 80 + `docker compose logs caddy`；仍不行看 Let's Encrypt 速率限制（换 `ACME_CA` 到 staging 目录排练） |
| Vault init / unseal | 3 位 unseal key 持有人 —— **没有任何技术手段能绕过** |
| 加固后 2242 连不上 | 先分 RST 还是静默超时：静默 = Hetzner Cloud Firewall 少了 2242（见前置检查）；RST = 主机上 sshd 没起来，看 `journalctl -u ssh` |
| SSH 把自己锁在门外 | Hetzner Cloud Console 的网页终端（不走 SSH） |
