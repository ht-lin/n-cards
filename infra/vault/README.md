# infra/vault

Vault 配置。T-003 交付了生产形态的服务器配置 [`vault.hcl`](vault.hcl)，
让 `docker-compose.prod.yml` 里的 vault 能以非 dev 模式起来；
**策略文件与初始化脚本由 T-005 交付**。

| 文件 | 内容 | 交付任务 |
|---|---|---|
| [`vault.hcl`](vault.hcl) | 服务器配置：file 后端 + 内网 listener，**无 seal stanza** | T-003 ✅ |
| [`policies/ncards-app.hcl`](policies/ncards-app.hcl) | 应用运行时的最小权限策略（§17.4） | T-005 ✅ |
| [`policies/ncards-ops.hcl`](policies/ncards-ops.hcl) | 轮换与 rewrap 的策略，**不给应用** | T-005 ✅ |
| [`policies/ncards-policy.hcl`](policies/ncards-policy.hcl) | **只能读写 policy 本身**的策略，给部署流水线用，**没有任何 transit** | T-114 ✅ |
| [`bootstrap.sh`](bootstrap.sh) | 幂等初始化：Transit 引擎、三把 key、kv-v2 与 JWT 密钥、三份 policy、两个 AppRole | T-005 ✅ |
| [`policy-sync.sh`](policy-sync.sh) | **policy 的下发执行者与漂移检测**：把 Vault 里的对齐到 `policies/*.hcl` 并逐字对账 | T-114 ✅ |
| [`_vault_api.sh`](_vault_api.sh) | 上面两个脚本共用的 HTTP 助手（`api` / `status_of` / `body_of` / `wait_for_vault`） | T-114 ✅ |
| [`Dockerfile`](Dockerfile) | `vault-init` 与 `vault-policy` 两个一次性容器共用的镜像（只为装 curl / jq / openssl） | T-005 ✅ |

> ⚠️ **`bootstrap.sh` 与 `policy-sync.sh` 的分工别混。**
> 前者要 **root**（建 mount、生成密钥材料、开 auth method），只在首启跑；
> 后者**只碰 policy**、用最小权限的 AppRole `ncards-policy`，**每次部署都跑**。
> 长期运行的环境上让仓库里的 `*.hcl` 真的生效的是后者 —— 少了它就是 T-114
> 的那次故障：staging 的 `ncards-app` 停在首启那天的版本，登录整个不通，
> 而 `/health/ready` 与冒烟测试全绿。

> 本地开发跑的是 **dev 模式**（内存后端、自动 unseal、固定 root token），
> 不读 `vault.hcl`。生产每次重启后都是**封印**状态，必须人工 unseal ——
> 见 [ADR-0004](../../docs/adr/0004-manual-vault-unseal.md) 与
> [`docs/runbooks/vault-unseal.md`](../../docs/runbooks/vault-unseal.md)。

## 怎么跑初始化

**本地**：`docker compose up -d` 会自动跑 `vault-init` 服务，无需额外操作。
单独重跑（脚本幂等）：

```bash
docker compose -f infra/compose/docker-compose.base.yml up vault-init
```

**CI**：`.github/workflows/backend.yml` 在 phpunit 之前直接跑 `infra/vault/bootstrap.sh`
（runner 上有 curl / jq / openssl，且 vault service 映射了 8200）。

**生产**：**没有** `vault-init` 服务（`docker-compose.prod.yml` 用
`profiles: ["disabled"]` 关掉了）。初始化是 unseal 之后的人工步骤，
需要一个用完即吊销的 root token —— 步骤见 runbook。

## policy 怎么保持最新（T-114）

`bootstrap.sh` 在长期运行的环境上**只跑过首启那一次**，而 `policies/*.hcl`
会随每张卡演进。补上这条缝的是 `policy-sync.sh`，由每次部署调用：

```bash
# 部署时自动跑（ansible 的 vault-policy 任务，跑在 compose 的一次性容器里）
ansible-playbook -i inventory/staging.yml deploy.yml --tags vault-policy

# 只对账不写，排查时用
ansible-playbook -i inventory/staging.yml deploy.yml --tags vault-policy \
  -e ncards_vault_policy_mode=check

# 本地
docker compose -f infra/compose/docker-compose.base.yml \
  --profile ops run --rm -e VAULT_TOKEN=dev-only-root-token vault-policy check
```

退出码：`0` 一致 · `1` 漂移 · **`3` Vault 封印或未初始化（跳过，不是失败）**。

⚠️ `ncards-policy` 这份 policy **它自己改不了**（只给了 `read`，有 `update` 就能
自提权到 `sudo`）。所以改 `ncards-policy.hcl` 本身要走一次
`vault operator generate-root` —— 见
[`staging-first-boot.md`](../../docs/runbooks/staging-first-boot.md)
的「已经首启过的环境怎么补上」。
改 `ncards-app.hcl` 与 `ncards-ops.hcl` 不受此限，部署会自己下发。

### 三份 policy、两个 AppRole，谁是谁

| policy | 绑在哪个 AppRole 上 | 谁在用 | 节奏 |
|---|---|---|---|
| `ncards-app` | `ncards-app` | 后端 app / worker | 每个请求 |
| `ncards-ops` | 还没有（T-404 会建） | 轮换与 rewrap | 一年几次，人工 |
| `ncards-policy` | `ncards-policy` | 部署流水线（`policy-sync.sh`） | 每次部署，自动 |

⚠️ **`ncards-policy` 与 `ncards-ops` 是刻意分开的两个身份，别合并。**
`ncards-ops` 持有 `transit/keys/+/rotate`，而那个通配符覆盖到 `ncards-hmac` ——
轮换它会让全部 `email_hash` 查找失效且不可补救。把 policy 下发挂在它身上，
等于让**每次部署都要用到**的那份凭据连带握着那条命令。
完整论证在 [`policies/ncards-policy.hcl`](policies/ncards-policy.hcl) 抬头。

## Transit key（§5.3）

| Key | 算法 | 轮换 |
|---|---|---|
| `transit/ncards-card` | AES-256-GCM96 | 是（12 个月，或事故时立即） |
| `transit/ncards-pii` | AES-256-GCM96 | 是（12 个月） |
| `transit/ncards-hmac` | HMAC-SHA256 | **否** —— 轮换会让所有 HMAC 查找失效 |

JWT 签名密钥（Ed25519）存 KV：`secret/ncards/jwt/current`。
T-005 只负责生成并放好，读取侧由 T-104 实现。

`ncards-hmac` 不轮换这条纪律有三道防线：
① `ncards-app` policy 没有 `rotate` 权限；
② `CryptoKey::isRotatable()` 返回 false，T-404 的 rewrap 任务据此跳过；
③ `docs/runbooks/key-rotation.md`（T-404）会写在最前面。

## AppRole policy（§17.4）

最小权限：仅对指定 key 的 `encrypt` / `decrypt` / `hmac` / `verify`，外加
`auth/token/renew-self`。**显式不授予** `keys/*`（不可读密钥）、`rotate`、`rewrap` ——
后三者由独立的 `ncards-ops` policy 持有。

Token TTL 1h，剩余 10 分钟时自动续期（`AppRoleTokenProvider`），
`token_max_ttl` 24h 到顶后重新登录。

> ⚠️ `secret_id_ttl=0`（不过期），与 §7.4 字面的「secret_id TTL 24h 自动续期」不一致。
> 这是一处已知且刻意的偏差 —— 24h 的 secret_id 需要 Vault Agent 一类的自动投递机制，
> 而 T-005 不交付那个，配上就等于让服务在部署 24 小时后集体认证失败。
> 完整理由见 [ADR-0004](../../docs/adr/0004-manual-vault-unseal.md) 的 Consequences，
> 正解归 T-406。

policy 的真实效果由 `backend/tests/Integration/Shared/Vault/` 下的**两个**用例类验证，
两个都**正反两面测**（只测正面的话，一个 `path "*"` 通配也能全绿）：

| 用例类 | 守哪个身份 | 反面断言的是 |
|---|---|---|
| `AppRolePolicyTest` | `ncards-app` | rotate / rewrap / export / `sys/seal` / 读 key 元数据 / 写 policy 一律 403 |
| `PolicyAppRoleTest` | `ncards-policy`（T-114） | **一条 transit 都没有**，且改不了自己那份 policy、没有通配、没有 list |

这是全仓库仅有的两处验证本目录下 `.hcl` 文件真实效果的地方 ——
别的测试都用 dev 模式的 root token，policy 对它们不起作用。

## 硬性约束

- auto-unseal **关闭**（Q6 / ADR-0004）。unseal key（Shamir 3-of-5）离线保管，**绝不进 CI**。
- **批量解密是硬要求**：用 Transit 的 `batch_input`，**禁止在循环里逐条调 Vault**。
  强制点是 `VaultBatchDecryptorTest::testDecryptsTwoHundredItemsInASingleRequest()`。
- **不缓存明文**。若压测不达标，只允许单个 HTTP 请求生命周期内的进程内 memo，
  请求结束即销毁。当前实测远优于目标，**未实现任何 memo**。
- 操作手册见 [`docs/runbooks/vault-unseal.md`](../../docs/runbooks/vault-unseal.md)。

## 实测：200 条 batch decrypt

§9.1 的预算是 **P95 ≤ 80 ms**（同主机容器内网）。T-005 交付时实测（20 次采样）：

| 环境 | P95 | 中位数 |
|---|---|---|
| compose 栈内（app 容器 → vault 容器） | **3.53 ms** | 1.99 ms |
| 宿主机 → 映射端口（CI 的形态） | **2.85 ms** | 2.38 ms |

两个数量级的余量，因此**没有实现任何明文 memo**（§5.3 只在压测不达标时才允许）。

数据由 `backend/tests/Integration/Shared/Crypto/VaultBatchDecryptorTest` 生成，
写在 `backend/var/vault-benchmark.txt`（不入库）。
用例断言的是一个宽松的 2000 ms 兜底上限而不是 80 ms —— 抓的是「有人把实现改回循环」
这种数量级的回归，而不是让 CI 因为 runner 抖动随机变红。
§9.1 的正式达标判定属于 T-203 的压测。
