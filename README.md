# NCards

NCards 是一款面向德国及欧盟市场的移动卡券钱包：把散落在实体钱包里的会员卡、积分卡、优惠券的条码/二维码统一收纳，在收银台前用最少的操作调出屏幕，并可与家人朋友**持续共享同一张卡**。

> **North Star 场景**：用户在 REWE 收银台，队伍在后面排着，手机在裤兜里。从掏出手机到收银员的扫码枪读出条码，**必须 ≤ 5 秒**，且在超市地下层无网络时**必须同样可用**。

技术规格书是本项目的唯一真相源：[`docs/TECHNICAL_SPEC.md`](docs/TECHNICAL_SPEC.md)（v1.1）。任务拆分见 [`docs/tasks/`](docs/tasks/README.md)。

## 仓库结构

单仓（monorepo）。理由：契约 `docs/api/openapi.yaml` 被前后端同时消费，单仓保证一次 PR 内可以同步修改契约 + 双端实现 + 契约测试，是"契约优先"（§13.1）落地的前提。

| 路径 | 内容 | 交付任务 |
|---|---|---|
| [`docs/`](docs/) | 规格书、ADR、runbook、OpenAPI 契约 | — |
| [`backend/`](backend/) | Symfony 7.x / PHP 8.3+ 模块化单体 | T-002 |
| [`android/`](android/) | Android 客户端（Kotlin / Compose / Gradle 多模块） | T-008 |
| [`infra/`](infra/) | compose / ansible / caddy / vault / monitoring | T-003, T-012 |
| [`scripts/`](scripts/) | 仓库运维脚本 | T-001 |
| `.github/` | PR 与 issue 模板、CI 工作流 | T-001, T-011 |

## 如何起本地栈

前置：先在 `backend/` 跑一次 `composer install`（源码是**只读**挂进容器的，
`vendor/` 用宿主机那份）。然后在仓库根：

```bash
cp infra/compose/.env.example infra/compose/.env
export COMPOSE_FILE=infra/compose/docker-compose.base.yml   # 本 shell 内免敲 -f

docker compose up -d                       # caddy · app · postgres · redis · vault
                                           #   外加一次性的 vault-init（T-005）：
                                           #   启用 Transit、建三把 key、写 policy、配 AppRole
docker compose exec app bin/console app:seed
curl -f http://localhost/health/ready      # 期望 200
```

`vault-init` 跑完就退出，`docker compose ps` 里看不到它是正常的。
它是幂等的，每次 `up` 都会重跑一遍且**绝不覆盖已有密钥材料**；要看它做了什么：

```bash
docker compose logs vault-init
```

不 `export` 就每条都得写全：`docker compose -f infra/compose/docker-compose.base.yml …`。
下面各段命令都假设你 export 过。

> `.env` 与 compose 文件**同目录**是刻意的：compose 的 project directory 取 `-f`
> 第一个文件所在目录，所以本地与 staging/prod 叠加链读的是同一份 `.env`。
> 放到仓库根的话，本地那条读得到、叠加链读不到，而所有变量都有 `${VAR:-default}`
> 兜底 —— 不报错，只静默用默认值起来。

80 端口被占用时改 `infra/compose/.env` 里的 `HTTP_PORT`，**不要**给后端服务加端口映射 ——
Postgres / Redis / Vault **不映射宿主机端口**，仅 Docker 内网可达（§7.4）。
要连库用 `docker compose exec postgres psql -U ncards`。

验证一下就绪探针真的在探：

```bash
docker compose stop postgres
curl -o /dev/null -w '%{http_code}\n' http://localhost/health/ready   # 期望 503
docker compose start postgres
```

staging / production 用叠加文件，形态与本地不同（生产 Vault 需**人工 unseal**，
且没有 `vault-init` —— 初始化是解封之后的人工步骤）——
见 [`infra/compose/README.md`](infra/compose/README.md)、
[ADR-0004](docs/adr/0004-manual-vault-unseal.md) 与
[`docs/runbooks/vault-unseal.md`](docs/runbooks/vault-unseal.md)。

## 如何跑测试

**后端**（在 `backend/`，T-002 已交付）：

```bash
composer install
composer qa                                    # 一键跑下面四条 + deptrac 自检
vendor/bin/php-cs-fixer check --diff           # 0 差异
vendor/bin/phpstan analyse                     # level 8，0 error
vendor/bin/deptrac analyse                     # 0 violation
vendor/bin/phpunit                             # 单元 + 集成 + 契约
```

需要真实 Postgres / Redis / Vault 的集成测试在裸机上会 skip（后端服务没有宿主机端口）。
要连真库跑，起栈之后在容器里跑（同样先 `export COMPOSE_FILE=…`）：

```bash
docker compose exec app bin/console --env=test doctrine:database:create --if-not-exists
docker compose exec app vendor/bin/phpunit
docker compose exec app composer test:coverage   # dev 镜像里装了 pcov
```

细则见 [`backend/README.md`](backend/README.md)。

**Android**（在 `android/`）：

> ⏳ **尚未可用** —— Android 工程由 **T-008** 交付。届时：

```bash
./gradlew ktlintCheck detekt lint
./gradlew testDebugUnitTest
./gradlew assembleDebug
```

**仓库级**（现在就能跑）：

```bash
npm install                  # 安装 commitlint 与 husky 钩子
npx commitlint --from HEAD~1 # 校验最近一条 commit message
```

完整质量门禁阈值见 §13.3。

## 协作规范

- 分支：trunk-based，`main` 永远可发布，功能分支 ≤ 3 天。
- 提交：[Conventional Commits](https://www.conventionalcommits.org/)，由 `commitlint` 在本地 commit-msg 钩子与 CI 两处强制。
- PR：1 个 approve + 全部 CI 绿，squash merge。
- 契约优先：任何 API 变更**必须先改** `docs/api/openapi.yaml`，与双端实现同 PR 合入。

细则见 [`CONTRIBUTING.md`](CONTRIBUTING.md)。架构决策记录流程见 [`docs/adr/README.md`](docs/adr/README.md)。

## 开放问题

品牌名与域名（Q1）暂定为 **NCards** / `ncards.de`，见 [ADR-0002](docs/adr/0002-brand-name-and-domain.md)（Status: Proposed）。

Vault unseal 方案（Q6）已决：人工 Shamir 3-of-5，auto-unseal 关闭，见 [ADR-0004](docs/adr/0004-manual-vault-unseal.md)（Status: Accepted）。

其余开放问题见 §17.5 与 [`docs/tasks/README.md`](docs/tasks/README.md)。
