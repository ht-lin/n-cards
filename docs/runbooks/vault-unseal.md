# Runbook：Vault 手工 unseal

> **交付**：T-005 初版 · T-406 完善（补 Vault Agent、key 保管轮值、恢复演练）
> **相关**：[ADR-0004](../adr/0004-manual-vault-unseal.md)、§3.3、§5.3、§7.4、§17.5（Q6）

Vault 的 auto-unseal 是**关闭**的（ADR-0004）。unseal key 为 Shamir 3-of-5，离线保管。
这意味着 **Vault 每次重启后都处于封印状态，必须有人手工解封**。
这不是故障，是刻意的设计——理由见 ADR-0004 与 `infra/vault/vault.hcl` 顶部注释。

---

## 触发条件

出现以下任一情况时执行本手册：

- 生产/staging 部署后 `/health/ready` 持续返回 **503**
- 主机重启、内核升级、断电恢复之后
- `docker compose ps` 显示 vault 容器在跑，但应用日志里出现
  `Vault is sealed or uninitialised (HTTP 503)`
- 涉及卡片或登录的接口大面积返回 `503 service_unavailable`
- 首次部署一台新主机（此时是**未初始化**，走下面的「情形 B」）

**不适用**于：`secret_id` 失效、policy 配错。那两种的症状是应用报
`Vault denied access to "..."; check the ncards-app policy`，
而 `/health/ready` 是 **200** —— 见文末「升级路径」。

---

## 前置检查

```bash
ssh -p 2242 deploy@<主机>   # 端口与用户由 T-012 的 Ansible 定义，见 infra/ansible/inventory/
cd /opt/ncards             # 部署路径，由 T-012 坐实

# ⚠️ staging 是**三段**叠加链，生产是两段 —— 少叠一层不会报错，
# 只会静默地用另一套配置（比如 dev 模式的 Vault）。按环境选一行：
export COMPOSE_FILE=infra/compose/docker-compose.base.yml:infra/compose/docker-compose.prod.yml:infra/compose/docker-compose.staging.yml   # staging
export COMPOSE_FILE=infra/compose/docker-compose.base.yml:infra/compose/docker-compose.prod.yml                                            # production

# 1) vault 容器在跑吗
docker compose ps vault

# 2) 现在到底是什么状态 —— 这一步决定走 A 还是 B
docker compose exec vault vault status -address=http://127.0.0.1:8200
```

`vault status` 的判读：

| 输出 | 含义 | 去哪一节 |
|---|---|---|
| `Initialized: true` · `Sealed: true` | 已初始化、被封印 —— **最常见** | 情形 A |
| `Initialized: false` | 从未初始化（全新主机 / 数据卷丢了） | 情形 B |
| `Initialized: true` · `Sealed: false` | 已解封 —— 问题不在这里 | 见「升级路径」 |
| 连不上 / 容器不在 | 不是 unseal 问题 | 先修容器，见「失败回滚」 |

> ⚠️ `Sealed: true` 时退出码是 **2**，`Initialized: false` 是 **1**，正常是 **0**。
> `docker-compose.prod.yml` 的 healthcheck 刻意容忍 2（封印是等待人工处理的正常中间态，
> 不该让 compose 反复重启容器）。

---

## 情形 A：已初始化，需要解封

**需要 5 把 unseal key 中的任意 3 把**，来自 3 位不同的持有人。

```bash
# 依次执行三次，每次由一位持有人输入自己那把 key。
# -address 必须显式给：容器里没有 VAULT_ADDR。
docker compose exec vault vault operator unseal -address=http://127.0.0.1:8200
```

每执行一次，输出里的 `Unseal Progress` 会 `1/3` → `2/3` → `3/3`，
第三把之后 `Sealed` 变为 `false`。

> ⚠️ **不要**把 key 写在命令行参数里（`vault operator unseal hvs.xxxx`）——
> 那会进 shell history 与进程列表。不带参数运行，让它交互式读入。
>
> ⚠️ **不要**让一个人拿着 3 把 key 全输一遍。3-of-5 的意义就是没有单点。
> 如果现实中做不到（比如深夜只有一个人在），那是**流程问题**，
> 请在事后记录并交给 T-406 处理，不要通过把 key 集中保管来「解决」。

---

## 情形 B：从未初始化（全新主机 / 数据卷丢失）

> ⚠️⚠️ **先停下来想清楚：这台机器以前有数据吗？**
>
> 如果 `vault_data` 卷本来有数据却显示未初始化，说明**卷丢了**。
> 此时执行 `operator init` 会生成一套**全新的**密钥 ——
> 而数据库里所有既有密文都是用旧密钥加密的，将**永久不可读**。
>
> 这种情况**不要**继续本节，去走 `restore-from-backup.md`（T-406）。
> 只有确认是全新部署时，才继续。

```bash
docker compose exec vault vault operator init \
  -address=http://127.0.0.1:8200 \
  -key-shares=5 -key-threshold=3
```

输出包含 **5 个 Unseal Key** 与 **1 个 Initial Root Token**。

**立刻做这三件事，顺序不能变：**

1. **把 5 把 unseal key 分发给 5 位持有人离线保管。**
   绝不进 CI、Ansible secrets、sops、密码管理器的共享库、聊天工具、邮件、
   任何仓库。纸质或离线加密介质，分散存放。
2. 记下 root token，接着按情形 A 解封（用刚拿到的任意 3 把）。
3. 解封后立刻执行下一节的初始化，**然后吊销 root token**。

---

## 解封之后：初始化 Transit（仅首次部署需要）

`infra/vault/bootstrap.sh` 会启用 Transit 引擎、建三把 key（§5.3）、
建 kv-v2 与 JWT 签名密钥、写两份 policy、配 AppRole。
脚本是**幂等**的，重复执行安全，且**绝不覆盖已存在的密钥材料**。

先把 root token 放进这个 shell —— 本节每一条命令都要用它：

```bash
read -rs VAULT_TOKEN && export VAULT_TOKEN   # 粘贴上一步的 root token。不回显、不进 history

# 期望：policies 里有 root。403 permission denied 的第一嫌疑是 token 没带进去
# （空 token 报的就是 403，不是 400），先 echo "len=${#VAULT_TOKEN}" 看是不是 0
docker compose exec -e VAULT_TOKEN="$VAULT_TOKEN" vault vault token lookup
```

```bash
# 生产的 compose 里没有 vault-init 服务（被 profiles: ["disabled"] 关掉了）——
# 那个容器需要常驻一个 root 级 token，不该留在生产环境里。所以这里手工跑。
docker run --rm --network ncards_backing \
  -v /opt/ncards/infra/vault:/vault/bootstrap:ro \
  -e VAULT_ADDR=http://vault:8200 \
  -e VAULT_TOKEN="$VAULT_TOKEN" \
  $(docker build -q /opt/ncards/infra/vault)
```

脚本最后会打印 `VAULT_ROLE_ID=...`。接着生成一个 `secret_id`：

```bash
docker compose exec -e VAULT_TOKEN="$VAULT_TOKEN" vault \
  vault write -f -field=secret_id auth/approle/role/ncards-app/secret-id
```

> ⚠️ 脚本提示里的 `http://vault:8200` 是**容器视角**的地址，只在 compose 的
> `ncards_backing` 网络里解析得出来。在宿主机 shell 里贴 curl 会得到
> `Could not resolve host: vault`。用上面的 `docker compose exec`。

把 `role_id` 与 `secret_id` 写进 sops(age) 加密的配置，随 Ansible 下发为
`VAULT_ROLE_ID` / `VAULT_SECRET_ID`（T-012 已交付）。在**运维本机**：

```bash
sops infra/ansible/inventory/group_vars/ncards_staging/secrets.sops.yaml
# 改完存盘即自动重新加密，然后提交 → PR → 合入 → 下一次部署自动生效
```

首次初始化时这两个值是 `pending-bootstrap-see-runbook` 占位符 ——
完整顺序见 [`staging-first-boot.md`](staging-first-boot.md) 第 9–10 步。

**最后一步 —— 吊销 root token**（确认 `role_id` 与 `secret_id` 都已到手再跑）：

```bash
docker compose exec -e VAULT_TOKEN="$VAULT_TOKEN" vault vault token revoke -self
unset VAULT_TOKEN
```

> ⚠️ root token 不该长期存在。留着它等于在 §17.4 的最小权限体系旁边放一把万能钥匙。
>
> 但**顺序反了没有退路**：先吊销的话，后面每条命令都 403，且拿不回同一个 token。
> 补救只能重新找 3 位 unseal key 持有人生成一个新的：
>
> ```bash
> docker compose exec vault vault operator generate-root -init   # 记下 OTP 与 nonce
> docker compose exec vault vault operator generate-root         # 3 人各输一把 key + 同一个 nonce
> docker compose exec vault vault operator generate-root -decode=<encoded-token> -otp=<OTP>
> ```

---

## 验证

```bash
# 1) Vault 自身
docker compose exec vault vault status -address=http://127.0.0.1:8200
#    期望：Initialized: true · Sealed: false

# 2) 应用的就绪探针
curl -o /dev/null -w '%{http_code}\n' https://api.n-cards.de/health/ready
#    期望：200
#    仍然 503 → Vault 好了但别的依赖没好（PG / Redis），看应用日志

# 3) 加解密真的能用（这一步才真正证明 AppRole + policy 是通的）
docker compose exec app php -r '
  require "/app/vendor/autoload.php";
  // 用应用自己的容器跑一次加解密往返
' 2>/dev/null || \
docker compose exec app bin/console debug:container --parameter=kernel.environment >/dev/null
#    更直接的办法：调一个真实的涉密端点（T-109 之后）并确认返回 200 而非 503
```

> ⚠️ `/health/ready` 返回 200 **不代表**加解密可用 ——
> 探针打的是免认证的 `sys/health`，不验证 AppRole 凭据（ADR-0004 的偏差 2）。
> 真正的确认是第 3 步。

---

## 失败回滚

**unseal 输错 key**
无害。`Unseal Progress` 不前进，重来即可。连续输错不会锁定。
若进度卡在中间想重置：`vault operator unseal -reset -address=http://127.0.0.1:8200`。

**只凑得齐 2 把 key**
没有技术手段可绕过 —— 这是 Shamir 的设计。只能找到第 3 位持有人。
**不要**尝试 `operator init`（会毁掉全部既有密文，见情形 B 的警告）。

**vault 容器起不来**
```bash
docker compose logs --tail=50 vault
```
常见原因：`vault.hcl` 挂载路径不对、`vault_data` 卷权限、缺 `IPC_LOCK`
（`disable_mlock = false` 需要它）。修好后重启容器，然后**重新走一遍解封** ——
容器一重启就又是封印状态。

**解封后应用仍然 503**
Vault 好了但应用侧的 token 是坏的。见下面「升级路径」。

**回滚部署不会解决 unseal 问题。** 镜像 tag 回退到上一版，vault 依然是封印的。
不要把时间花在回滚上。

---

## 升级路径（找谁 / 什么情况该升级）

| 情况 | 处置 |
|---|---|
| 凑不齐 3 把 key | **技术负责人**。这是一次可能的数据永久丢失事件，按事故处理 |
| `vault_data` 卷丢失且有既有数据 | **技术负责人** + `restore-from-backup.md`（T-406）。**不要**自行 `operator init` |
| `/health/ready` 是 200，但应用报 `Vault denied access to "..."` | 不是 unseal 问题。是 `secret_id` 失效或 policy 不对：重新生成 secret_id 并下发；仍不行则比对 `infra/vault/policies/ncards-app.hcl` 与 Vault 里的实际 policy（`vault policy read ncards-app`）。这类故障**探针看不见**，是 ADR-0004 记录的已知缺口 |
| 应用报 `Vault is unreachable` 但 vault 容器健康 | 网络问题，不是 unseal。查 compose 的 `backing` 网络与 `VAULT_ADDR` |
| 怀疑 unseal key 泄露 | **技术负责人**，按 `incident-response.md`（T-407）处理。需要 `operator rekey` + 全量 rewrap |

---

## 已知的粗糙之处（留给 T-406）

- **每次重启都要人工介入**是本项目最主要的单点人工依赖（ADR-0004 的 Consequences）。
  Vault Agent 能让 `secret_id` 自动轮换，但**不能**代替 unseal —— 那是刻意的。
- `secret_id` 目前不过期（`secret_id_ttl=0`），与 §7.4 字面的「24h 自动续期」不一致。
  偏差与理由记录在 ADR-0004，正解是 Vault Agent，归 T-406。
- key 持有人的轮值与交接流程尚未定义。
- 本手册的第 3 步验证还比较将就，等 T-109 有真实涉密端点后应替换为一条具体的 curl。
