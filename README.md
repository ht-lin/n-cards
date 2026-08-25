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

> ⏳ **尚未可用** —— 本地 Docker Compose 栈由 **T-003** 交付。届时命令为：

```bash
cp .env.example .env                      # T-003 提供
docker compose -f infra/compose/docker-compose.base.yml up -d
docker compose exec app bin/console app:seed
curl -f http://localhost/health/ready     # 期望 200
```

Postgres / Redis / Vault **不映射宿主机端口**，仅 Docker 内网可达（§7.4）。

## 如何跑测试

> ⏳ 后端工具链由 **T-002** 交付，Android 工程由 **T-008** 交付。届时：

**后端**（在 `backend/`）：

```bash
composer install
vendor/bin/php-cs-fixer fix --dry-run --diff   # 0 差异
vendor/bin/phpstan analyse                     # level 8，0 error
vendor/bin/deptrac analyse                     # 0 violation
vendor/bin/phpunit                             # 单元 + 集成 + 契约
```

**Android**（在 `android/`）：

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

品牌名与域名（Q1）暂定为 **NCards** / `ncards.de`，见 [ADR-0002](docs/adr/0002-brand-name-and-domain.md)（Status: Proposed）。其余开放问题见 §17.5 与 [`docs/tasks/README.md`](docs/tasks/README.md)。
