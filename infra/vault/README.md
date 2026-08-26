# infra/vault

Vault 配置。**策略文件与初始化脚本由 T-005 交付**；T-003 先交付了生产形态的
服务器配置 [`vault.hcl`](vault.hcl)，好让 `docker-compose.prod.yml` 里的 vault
能以非 dev 模式起来。

| 文件 | 内容 | 交付任务 |
|---|---|---|
| [`vault.hcl`](vault.hcl) | 服务器配置：file 后端 + 内网 listener，**无 seal stanza** | T-003 |
| `ncards-app.hcl` 等 policy | AppRole 最小权限策略（§17.4） | T-005 |
| 初始化脚本 | 启用 Transit 引擎、建三把 key、建 AppRole | T-005 |

> 本地开发跑的是 **dev 模式**（内存后端、自动 unseal、固定 root token），
> 不读 `vault.hcl`。生产每次重启后都是**封印**状态，必须人工 unseal。

## Transit key（§5.3）

| Key | 算法 | 轮换 |
|---|---|---|
| `transit/ncards-card` | AES-256-GCM96 | 是 |
| `transit/ncards-pii` | AES-256-GCM96 | 是 |
| `transit/ncards-hmac` | HMAC-SHA256 | **否** —— 轮换会让所有 HMAC 查找失效 |

JWT 签名密钥存 KV。

## AppRole policy（§17.4）

最小权限：仅对指定 key 的 `encrypt` / `decrypt` / `rewrap`。**显式不授予** `keys/*`（不可读密钥）、`rotate`、`rewrap` 管理权 —— 后者由独立的 `ncards-ops` policy 持有。Token TTL 1h，自动续期。

## 硬性约束

- auto-unseal **关闭**（Q6）。unseal key（Shamir 3-of-5）离线保管，**绝不进 CI**。
- **批量解密是硬要求**：用 Transit 的 `batch_input`，**禁止在循环里逐条调 Vault**。目标 200 条 P95 < 80 ms。
- **不缓存明文**。若压测不达标，只允许单个 HTTP 请求生命周期内的进程内 memo，请求结束即销毁。
- 操作手册见 [`docs/runbooks/vault-unseal.md`](../../docs/runbooks/README.md)。
