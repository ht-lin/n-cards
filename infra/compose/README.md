# infra/compose

Docker Compose 栈。**由 T-003 交付**，当前目录为空。

文件：`docker-compose.base.yml` + `.prod.yml` + `.staging.yml`

服务：`caddy` · `app`（FrankenPHP + Symfony）· `postgres:16-alpine` · `redis:7-alpine`（`appendonly yes`）· `vault:1.x`

## 约束

- Postgres / Redis / Vault **不映射宿主机端口**（§7.4）。
- `app` 容器：非 root 用户、`read_only: true`、tmpfs 挂 `/tmp` 与 `var/cache`。
- Vault 使用**独立数据卷**（不与 app 共卷）。本地可用 dev 模式，但 compose 文件必须为 prod 留出人工 unseal 的形态。
- `/health/live` 与 `/health/ready` **不在 `/v1` 下**，且不暴露内部细节。PG 不可用时 `/health/ready` 返回 503。
