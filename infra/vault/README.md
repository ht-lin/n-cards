# infra/vault

Vault 配置。T-003 交付了生产形态的服务器配置 [`vault.hcl`](vault.hcl)，
让 `docker-compose.prod.yml` 里的 vault 能以非 dev 模式起来；
**策略文件与初始化脚本由 T-005 交付**。

| 文件 | 内容 | 交付任务 |
|---|---|---|
| [`vault.hcl`](vault.hcl) | 服务器配置：file 后端 + 内网 listener，**无 seal stanza** | T-003 ✅ |
| [`policies/ncards-app.hcl`](policies/ncards-app.hcl) | 应用运行时的最小权限策略（§17.4） | T-005 ✅ |
| [`policies/ncards-ops.hcl`](policies/ncards-ops.hcl) | 轮换与 rewrap 的策略，**不给应用** | T-005 ✅ |
| [`bootstrap.sh`](bootstrap.sh) | 幂等初始化：Transit 引擎、三把 key、kv-v2 与 JWT 密钥、两份 policy、AppRole | T-005 ✅ |
| [`Dockerfile`](Dockerfile) | `vault-init` 一次性容器的镜像（只为装 curl / jq / openssl） | T-005 ✅ |

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

policy 的真实效果由 `backend/tests/Integration/Shared/Vault/AppRolePolicyTest` 验证 ——
**正反两面都测**：能加解密（policy 不太紧），且 rotate / rewrap / export / sys/seal /
读 key 元数据一律 403（policy 不太松）。这是全仓库唯一验证本目录下 `.hcl` 文件
真实效果的地方，别的测试都用 dev 模式的 root token，policy 对它们不起作用。

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
