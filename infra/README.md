# infra

基础设施即代码。

| 目录 | 内容 | 交付任务 |
|---|---|---|
| [`compose/`](compose/) | `docker-compose.{base,prod,staging}.yml` | T-003 ✅ |
| [`ansible/`](ansible/) | Hetzner 主机配置与部署 playbook | T-012 ✅ |
| [`caddy/`](caddy/) | Caddyfile：反代 + 安全响应头 + 自动 TLS | T-003 ✅ 初版 · T-012 ✅ ACME |
| [`vault/`](vault/) | `vault.hcl` 服务器配置 / policy 与初始化脚本 | T-003 ✅ 初版 · T-005 ✅ |
| [`monitoring/`](monitoring/) | prometheus.yml、Grafana dashboards、Loki | T-405 |

## 全局硬性约束

- **`.env` 绝不入库**。生产/staging 配置用 `sops`（age）加密后随 Ansible 下发。
- Postgres / Redis / Vault **不映射宿主机端口**，仅 Docker 内网可达（§7.4）。
- Vault unseal key（Shamir 3-of-5）离线保管，**绝不进 CI / Ansible secrets**。auto-unseal 关闭（Q6）。
- staging **只用合成数据**，严禁复制生产数据，哪怕脱敏。
- 全部服务在 Hetzner **Nürnberg**（EU 境内，GDPR）。
- **绝不 `docker compose down -v`** —— 会连 `vault_data`（= 全部卡数据永久不可解密，
  §9.4）、`pg_data`、`caddy_data` 一起删。
- **绝不在主机上构建镜像**，部署的必须是 CI 验证过的那一个（T-012）。

## 部署

`main` 合入 → CI 自动部署到 `api.staging.n-cards.de`。
入口是 [`ansible/`](ansible/)，处置手册见
[`docs/runbooks/staging-first-boot.md`](../docs/runbooks/staging-first-boot.md) 与
[`docs/runbooks/deploy-and-rollback.md`](../docs/runbooks/deploy-and-rollback.md)。

⚠️ staging 实机是 **2 vCPU / 4 GB / 40 GB**，小于 §4.1 假设的 CCX23 ——
余量账见 [`ansible/README.md`](ansible/README.md)。
