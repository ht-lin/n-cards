# backend

Symfony 7.4 (LTS) / PHP 8.3+ **模块化单体**。骨架与质量门禁由 T-002 交付。

根命名空间是 Symfony 默认的 `App\`（`App\Shared\…`、`App\Module\Identity\…`）。
刻意不用品牌名 —— 品牌名（Q1）在 [ADR-0002](../docs/adr/0002-brand-name-and-domain.md) 里还挂着
`Proposed`，用 `App\` 则将来改名对 `backend/` 的源码零影响。

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

## 结构（§12.2）

```
src/
├── Kernel.php
├── Shared/{Domain,Application,Infrastructure,Http}
└── Module/{Identity,Wallet,Sharing,Social,Sync,Notification,Compliance}/
    └── {Domain,Application/{Port,Dto},Infrastructure,Http}

tests/{Unit,Integration,Api}      # 见 §12.2：Unit 无容器 / Integration 带 DB / Api 端到端
config/{packages,routes,services}
migrations/
```

M0 阶段模块目录全是空壳（`.gitkeep`），由 T-004 起逐个填充。

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

`.env` 与 `.env.test` 是 Symfony 约定的**非密钥默认值**文件，入库
（仓库根 `.gitignore` 对这两个文件开了窄口，其余 `.env*` 一律忽略）。
真实密钥走 `.env.local` / 容器环境变量 / sops(age)，见 §14 与 T-003 / T-012。
