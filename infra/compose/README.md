# infra/compose

Docker Compose 栈（T-003 交付）。

| 文件 | 用途 |
|---|---|
| [`docker-compose.base.yml`](docker-compose.base.yml) | 全部服务定义。**单独就能跑本地栈** |
| [`docker-compose.prod.yml`](docker-compose.prod.yml) | 生产覆盖层。必须叠在 base 之上 |
| [`docker-compose.staging.yml`](docker-compose.staging.yml) | staging 覆盖层。叠在 base **和** prod 之上 |

服务：`caddy` · `app`（FrankenPHP + Symfony）· `postgres:16-alpine` ·
`redis:7-alpine`（`appendonly yes`）· `vault:1.18` ·
`vault-init`（一次性，T-005）。

## 怎么起

**本地** —— 在仓库根：

```bash
cp infra/compose/.env.example infra/compose/.env
export COMPOSE_FILE=infra/compose/docker-compose.base.yml   # 本 shell 内免敲 -f
docker compose up -d
```

> **`.env` 必须与 compose 文件同目录。** compose 的 project directory 默认取 `-f`
> 的第一个文件所在目录，所以 `infra/compose/.env` 会被自动加载，本地与下面的
> staging/prod 叠加链读到的是**同一份**。
>
> 别把它挪到仓库根：那样本地那条读得到、叠加链读不到，而本栈所有变量都写了
> `${VAR:-default}` 兜底 —— 它不会报错，只会静默用默认值起来。这种失败最难查。
> （真要放别处，每条叠加链命令都得记得加 `--env-file`，那就是在给自己留坑。）

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
  配置见 [`../vault/vault.hcl`](../vault/vault.hcl)、
  [ADR-0004](../../docs/adr/0004-manual-vault-unseal.md) 与
  [`docs/runbooks/vault-unseal.md`](../../docs/runbooks/vault-unseal.md)。
- `vault-init` 是**一次性**容器（`restart: "no"`），跑完 `../vault/bootstrap.sh` 就退出。
  它自建镜像（[`../vault/Dockerfile`](../vault/Dockerfile)）—— 脚本要 curl / jq / openssl，
  而 `backing` 网络 `internal: true` 装不了包，只能构建期装好。
  **生产没有它**：`.prod.yml` 用 `profiles: ["disabled"]` 关掉，
  那里的初始化是解封之后的人工步骤（要一个用完即吊销的 root token）。

## 三处容易踩的坑

**1. `app` 的源码是 `:ro` 绑定挂载的。**
先在 `backend/` 跑过 `composer install` —— `vendor/` 用的是宿主机那份。
镜像里也烤了一份，但会被挂载盖掉。

**2. `/app/var` 是 tmpfs，不是 `/app/var/cache`。**
分别挂 `var/cache` 与 `var/log` 需要这两个子目录在挂载点上已经存在，而 `/app`
是只读挂载，Docker 建不出来，容器会以
`make mountpoint: read-only file system` 起不来。子目录改由
`backend/docker/entrypoint.sh` 在 tmpfs 里现场建，Symfony 缓存也在那里预热
（tmpfs 每次容器启动都是空的）。

**3. `docker compose restart vault` 会清空 Transit —— 需要重跑 `vault-init`。**
本地 Vault 是 **dev 模式**，用的是**内存**后端。容器一重启，Transit 引擎、三把 key、
JWT 密钥、policy、AppRole 全部消失（`vault_data` 卷在 dev 模式下根本没被用到）。

症状是集成测试整组失败并提示 `transit/encrypt/ncards-card` 返回 404。修法：

```bash
docker compose up vault-init
```

⚠️ **`/health/ready` 此时仍然是 200** —— 就绪探针打的是免认证的 `sys/health`，
它只知道 Vault 活着、没被封印，不知道 Transit 有没有初始化
（这是 [ADR-0004](../../docs/adr/0004-manual-vault-unseal.md) 记录的已知缺口）。
所以别拿探针当「加密可用」的判据。

生产不存在这个问题：那里是 file 后端，数据持久化在 `vault_data` 卷里，
重启后只需要**解封**，不需要重新初始化。

## 验收（T-003）

下面的命令假设已 `export COMPOSE_FILE=infra/compose/docker-compose.base.yml`。

```bash
docker compose ps                                   # 五个常驻服务全部 healthy
                                                    # （vault-init 跑完即退出，不在列）
curl -i http://localhost/health/live                # 200，四个安全头齐全
curl -i http://localhost/health/ready               # 200

docker compose stop postgres
curl -i http://localhost/health/ready               # 503 {"status":"unavailable"}
docker compose start postgres

docker compose exec app id                          # uid=1000(ncards)
docker compose exec app touch /app/probe            # Read-only file system
docker compose exec app bin/console app:seed        # 退出码 0
```

## 验收（T-005）

```bash
docker compose logs vault-init                      # 幂等完成，退出码 0
docker compose up vault-init                        # 再跑一次：全部「已存在，跳过」

docker compose stop vault
curl -o /dev/null -w '%{http_code}\n' http://localhost/health/ready    # 503
docker compose start vault && docker compose up vault-init             # dev 模式重启会清空，见坑 3
curl -o /dev/null -w '%{http_code}\n' http://localhost/health/ready    # 200

# 加解密往返、200 条 batch 基准、AppRole policy 正反面
docker compose exec app vendor/bin/phpunit --testsuite Integration
docker compose exec app cat var/vault-benchmark.txt
```

## 尚未交付

§14.2 的服务清单里还有 `worker`、`scheduler`、`prometheus`/`grafana`/`loki`、
`backup`。它们的依赖还没装，写出来也起不来，所以在
`docker-compose.prod.yml` 末尾以注释槽位留位并标注了归属任务
（T-004 / T-113 / T-405 / T-406）。
