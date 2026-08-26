# backend

Symfony 7.4 (LTS) / PHP 8.3+ **模块化单体**。骨架与质量门禁由 T-002 交付。

根命名空间是 Symfony 默认的 `App\`（`App\Shared\…`、`App\Module\Identity\…`）。
刻意不用品牌名 —— 品牌名（Q1）在 [ADR-0002](../docs/adr/0002-brand-name-and-domain.md) 里还挂着
`Proposed`，用 `App\` 则将来改名对 `backend/` 的源码零影响。

持久化目前只有 **Doctrine DBAL**（T-003 为了 `/health/ready` 探活引入），
没有 ORM —— 实体映射与第一个迁移属 T-101。
`doctrine/doctrine-bundle` 钉在 **2.x**：3.x 起要求 PHP ^8.4，而本项目按 §12.2
跑 8.3（`composer.json` 的 `config.platform.php` 与 CI 的 `php-version` 都是 8.3）。

## 常用命令

```bash
composer install

composer qa            # 本地全套门禁：cs + stan + deptrac + selftest + test
composer cs            # php-cs-fixer 干跑（§13.3：0 差异）
composer cs:fix        # 就地修复风格
composer stan          # 预热容器 + PHPStan level 8
composer deptrac       # 模块与分层边界（§4.2）
composer test          # PHPUnit
composer test:coverage # 带覆盖率并校验 §13.3 阈值（需 pcov 或 xdebug）
composer audit         # 依赖漏洞（§13.3：0 高危）
```

底层命令同样可用（与仓库根 README 一致）：

```bash
vendor/bin/php-cs-fixer check --diff
vendor/bin/phpstan analyse
vendor/bin/deptrac analyse
vendor/bin/phpunit
```

> `composer test:coverage` 需要 **pcov** 或 **xdebug** 扩展。开发机上通常没装，
> CI（`.github/workflows/backend.yml`）用 pcov 跑，日常本地开发跑 `composer qa` 即可。
> 想在本地复现覆盖率判定，用容器：dev 镜像里装了 pcov（见下）。

## 在容器里跑（T-003 起）

裸机上 `composer qa` 全绿就够日常用，但需要真实 Postgres 的集成测试会 skip
——后端服务按 §7.4 不映射宿主机端口，裸机连不上。要连真库跑，先在仓库根
`docker compose up -d`，然后：

```bash
docker compose exec app bin/console --env=test doctrine:database:create --if-not-exists
docker compose exec app vendor/bin/phpunit          # 0 skip
docker compose exec app composer test:coverage      # dev 镜像里有 pcov
docker compose exec app bin/console app:seed
```

镜像定义在 [`Dockerfile`](Dockerfile)（`dev` / `prod` 两个 target），
栈定义在 [`../infra/compose/`](../infra/compose/README.md)。

> 容器里 compose 注入的 `APP_ENV=dev` 是**真实环境变量**，会落进 `$_ENV` 并赢过
> `phpunit.xml.dist` 里的 `<server>`。所以那里额外写了一行 `<env name="APP_ENV">`，
> 否则 `docker compose exec app vendor/bin/phpunit` 会拿 dev 内核跑测试。

## 结构（§12.2）

```
src/
├── Kernel.php
├── Shared/{Domain,Application,Infrastructure,Http}
└── Module/{Identity,Wallet,Sharing,Social,Sync,Notification,Compliance}/
    └── {Domain,Application/{Port,Dto},Infrastructure,Http}

tests/{Unit,Integration,Api}      # 见 §12.2：Unit 无容器 / Integration 带 DB / Api 端到端
tests/Double/                     # 跨用例复用的测试替身
config/{packages,routes,services}
migrations/
docker/                           # entrypoint 与 prod PHP ini（T-003）
```

M0 阶段模块目录全是空壳（`.gitkeep`），由 T-004 起逐个填充。
`Shared/` 下目前只有 T-003 放的健康检查与种子命令骨架：

| 路径 | 内容 |
|---|---|
| `Shared/Application/Health/` | `HealthCheckInterface`、`ReadinessProbe` |
| `Shared/Application/Seed/` | `SeederInterface` |
| `Shared/Infrastructure/Health/` | `DatabaseHealthCheck`（唯一碰 Doctrine 的一层） |
| `Shared/Infrastructure/Console/` | `SeedCommand`（`app:seed`） |
| `Shared/Http/Controller/` | `HealthController`（`/health/live`、`/health/ready`） |

两个扩展点都是「实现接口即注册」：`config/services.yaml` 的 `_instanceof` 打标签，
收集端用 `#[AutowireIterator]`。加一项就绪检查（Redis → T-006、Vault → T-005）
或一个模块 seeder，都不需要回头改控制器或命令。

> ⚠️ **T-004 注意**：`ClientVersionListener`（缺 `X-Client` 即 400）**必须**把
> `/health/*` 排除在外。探活调用方是 Docker healthcheck / Caddy / Ansible，
> 它们不带这个 header —— 漏掉这条，compose 与 §14.3 部署健康检查会一起失效。

## 分层职责

| 层 | 允许做 | 禁止做 |
|---|---|---|
| `Http/Controller` | 反序列化请求、调用 Application、序列化响应 | 任何业务逻辑、直接用 Doctrine |
| `Application` | 编排、事务边界、权限检查、DTO 组装 | 直接写 SQL、了解 HTTP |
| `Domain` | 实体、值对象、不变量、领域服务、领域事件 | import 任何框架类型 |
| `Infrastructure` | Doctrine 映射与仓储实现、外部 HTTP 客户端、Vault、Mailer | 被 Domain 直接引用（只能实现其接口） |

## 模块边界（[`deptrac.yaml`](deptrac.yaml)）

Deptrac 同时强制**两个维度**，用「模块 × 分层」的交叉积图层在**一个** ruleset 里表达：

1. **模块间**：不得引用他模块的 `Domain` / `Application` / `Infrastructure` / `Http`，
   只能看到对方的 `Application\Port\*` 与 `Application\Dto\*`。
2. **层间**：`Http → Application → Domain`；`Infrastructure` 实现 `Domain` 定义的接口。
   `Framework.{Http,Persistence,Core}` 三个图层收集 vendor 里的框架类型，
   `*.Domain` 的允许列表里一个都没有 —— 这就是「Domain 不得 import 框架类型」的强制点。

拆成两份配置会让 `Wallet.Http → Identity.Domain` 这类跨维度组合从缝隙里漏过去，
所以坚持一个 ruleset。文件头部有「新增模块时怎么改」的清单。

唯一豁免：`Sync\Infrastructure\Doctrine\SyncReadModel`（§4.2 规则 5 的跨模块只读查询入口），
`deptrac.yaml` 末尾的 `skip_violations` 留了槽位与使用约束，T-202 实现时填。

**`composer deptrac:selftest`** 会临时写入一个跨模块 `Domain` 引用并断言 deptrac 拦得住。
`deptrac analyse` 只能证明「当前代码没违规」，证明不了「规则还有效」—— 谁把规则改松了，
自检会红而 `analyse` 依然是绿的。

## 质量门禁（§13.3）

| 检查 | 阈值 |
|---|---|
| `php-cs-fixer check` | 0 差异（`@Symfony` + `@PHP83Migration` + `declare_strict_types`） |
| `phpstan analyse` | level 8，`src/` `tests/` `tools/` 全量，0 error；[baseline](phpstan-baseline.neon) 只允许缩小 |
| `deptrac analyse` | 0 violation |
| `composer audit` | 0 高危 |
| 行覆盖率 | 整体 ≥ 70%；`Module/*/{Domain,Application}` ≥ 85%（由 [`tools/coverage-check.php`](tools/coverage-check.php) 校验 clover 报告） |

## 配置与密钥

`DATABASE_URL` / `REDIS_URL` / `VAULT_ADDR` 在容器里由 compose 注入（拼接来源是
仓库根 `.env`，见 `infra/compose/docker-compose.base.yml`）；下面这两个文件只提供
**裸机**上跑 `composer test` / `bin/console` 时的默认值。

`.env` 与 `.env.test` 是 Symfony 约定的**非密钥默认值**文件，入库
（仓库根 `.gitignore` 对这两个文件开了窄口，其余 `.env*` 一律忽略）。
真实密钥走 `.env.local` / 容器环境变量 / sops(age)，见 §14 与 T-003 / T-012。
