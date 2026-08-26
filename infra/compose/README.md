# infra/compose

Docker Compose 栈（T-003 交付）。

| 文件 | 用途 |
|---|---|
| [`docker-compose.base.yml`](docker-compose.base.yml) | 全部服务定义。**单独就能跑本地栈** |
| [`docker-compose.prod.yml`](docker-compose.prod.yml) | 生产覆盖层。必须叠在 base 之上 |
| [`docker-compose.staging.yml`](docker-compose.staging.yml) | staging 覆盖层。叠在 base **和** prod 之上 |

服务：`caddy` · `app`（FrankenPHP + Symfony）· `postgres:16-alpine` ·
`redis:7-alpine`（`appendonly yes`）· `vault:1.18`。

## 怎么起

**本地** —— 在仓库根，`compose.yaml` 会 include 进 base：

```bash
cp .env.example .env
docker compose up -d
```

**staging / production** —— 由 T-012 的 Ansible playbook 调用，用显式叠加链：

```bash
docker compose -f infra/compose/docker-compose.base.yml \
               -f infra/compose/docker-compose.prod.yml up -d

docker compose -f infra/compose/docker-compose.base.yml \
               -f infra/compose/docker-compose.prod.yml \
               -f infra/compose/docker-compose.staging.yml up -d
```

改完覆盖文件先验一遍语法与合并结果：

```bash
docker compose -f docker-compose.base.yml -f docker-compose.prod.yml config
```

## 约束（改动前先读 §7.4）

- Postgres / Redis / Vault **没有 `ports:`**。只有 `caddy` 映射宿主机端口。
  要连库用 `docker compose exec postgres psql -U ncards`，**不要**加端口映射。
- `app` 容器：非 root（uid 1000）、`read_only: true`，可写点只有 tmpfs 的
  `/tmp` 与 `/app/var`。
- `backing` 网络是 `internal: true` —— 三个后端服务连出站都没有。
- Vault 用**独立数据卷**（`vault_data`），不与 app 共卷。
- 本地 Vault 跑 dev 模式；生产是 file 后端 + **人工 unseal**（Q6，auto-unseal 关闭），
  配置见 [`../vault/vault.hcl`](../vault/vault.hcl)。

## 两处容易踩的坑

**1. `app` 的源码是 `:ro` 绑定挂载的。**
先在 `backend/` 跑过 `composer install` —— `vendor/` 用的是宿主机那份。
镜像里也烤了一份，但会被挂载盖掉。

**2. `/app/var` 是 tmpfs，不是 `/app/var/cache`。**
分别挂 `var/cache` 与 `var/log` 需要这两个子目录在挂载点上已经存在，而 `/app`
是只读挂载，Docker 建不出来，容器会以
`make mountpoint: read-only file system` 起不来。子目录改由
`backend/docker/entrypoint.sh` 在 tmpfs 里现场建，Symfony 缓存也在那里预热
（tmpfs 每次容器启动都是空的）。

## 验收（T-003）

```bash
docker compose ps                                   # 五个服务全部 healthy
curl -i http://localhost/health/live                # 200，四个安全头齐全
curl -i http://localhost/health/ready               # 200

docker compose stop postgres
curl -i http://localhost/health/ready               # 503 {"status":"unavailable"}
docker compose start postgres

docker compose exec app id                          # uid=1000(ncards)
docker compose exec app touch /app/probe            # Read-only file system
docker compose exec app bin/console app:seed        # 退出码 0
```

## 尚未交付

§14.2 的服务清单里还有 `worker`、`scheduler`、`prometheus`/`grafana`/`loki`、
`backup`。它们的依赖还没装，写出来也起不来，所以在
`docker-compose.prod.yml` 末尾以注释槽位留位并标注了归属任务
（T-004 / T-113 / T-405 / T-406）。
