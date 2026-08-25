# infra

基础设施即代码。

| 目录 | 内容 | 交付任务 |
|---|---|---|
| [`compose/`](compose/) | `docker-compose.{base,prod,staging}.yml` | T-003 |
| [`ansible/`](ansible/) | Hetzner 主机配置与部署 playbook | T-012 |
| [`caddy/`](caddy/) | Caddyfile：自动 TLS + 安全响应头 | T-012 |
| [`vault/`](vault/) | Vault policy 与初始化脚本 | T-005 |
| [`monitoring/`](monitoring/) | prometheus.yml、Grafana dashboards、Loki | T-405 |

## 全局硬性约束

- **`.env` 绝不入库**。生产/staging 配置用 `sops`（age）加密后随 Ansible 下发。
- Postgres / Redis / Vault **不映射宿主机端口**，仅 Docker 内网可达（§7.4）。
- Vault unseal key（Shamir 3-of-5）离线保管，**绝不进 CI / Ansible secrets**。auto-unseal 关闭（Q6）。
- staging **只用合成数据**，严禁复制生产数据，哪怕脱敏。
- 全部服务在 Hetzner **Nürnberg**（EU 境内，GDPR）。
