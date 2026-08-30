# 协作规范

本文是 [`docs/TECHNICAL_SPEC.md`](docs/TECHNICAL_SPEC.md) §13 的可执行摘要。**规格书是真相源**，两者冲突以规格书为准，并提 PR 修正本文。

## 1. 分支

Trunk-based：`main` 永远可发布。

- 功能分支生命周期 **≤ 3 天**。超过 3 天说明任务该拆了。
- 命名：`feat/<ticket>-<slug>`、`fix/…`、`chore/…`、`docs/…`
  例：`feat/T-109-cards-crud`、`fix/T-250-outbox-409`
- 未完成的功能用 **feature flag** 合入 `main`（后端 `feature_flags` 从 `/v1/config` 下发；客户端 `BuildConfig` + 远程开关），**不要**长期分支。

## 2. 提交信息

[Conventional Commits](https://www.conventionalcommits.org/)，由 `commitlint` 在**本地 commit-msg 钩子**与 **CI** 两处强制：

```
feat(wallet): add card pinning
fix(sync): handle 409 on outbox flush
chore(deps): bump kotlin to 2.0.21
docs(spec): record ADR-0012
```

允许的 `type`：`feat` `fix` `docs` `style` `refactor` `perf` `test` `build` `ci` `chore` `revert`

允许的 `scope`（见 [`commitlint.config.js`](commitlint.config.js)，与 §12.2 / §12.3 的模块划分对齐）：

| 组 | scope |
|---|---|
| 后端模块 | `identity` `wallet` `sharing` `social` `sync` `notification` `compliance` `shared` |
| 端 | `backend` `android` |
| 横切 | `api` `infra` `ci` `docs` `deps` `release` |

改动跨多个 scope 时省略 scope 即可（`chore: …`），不要堆砌。

启用本地钩子（clone 后执行一次）：

```bash
npm install
```

## 3. Pull Request

- **标题**必须是 Conventional Commit 格式 —— squash 后它就是 `main` 上的 commit message。
- 必须关联 issue。
- **1 个 approve** + 全部 CI 绿。
- **Squash merge**（`main` 保持线性历史）。
- 按 [PR 模板](.github/PULL_REQUEST_TEMPLATE.md)逐项填写：变更说明 / 关联 issue / 契约是否变更 / 迁移是否向后兼容 / 测试说明 / UI 截图。

`main` 分支保护规则（§13.2）：禁止直推、禁止 force push、禁止删除分支、要求线性历史、要求 PR + 1 approve + required checks。规则定义在 [`scripts/setup-branch-protection.sh`](scripts/setup-branch-protection.sh)。

> ⚠️ **当前服务端未生效**：`ht-lin/n-cards` 是 Free 套餐的私有仓库，GitHub 的 ruleset 与 branch protection 均返回 403（`Upgrade to GitHub Pro or make this repository public`）。
>
> 也就是说，**上述规则目前只是约定，服务端不会阻止直推 `main` 或 force push**。在做出下列选择之一前，这是一项**已知且已接受的风险**：
>
> 1. 升级 GitHub Pro → 直接 `bash scripts/setup-branch-protection.sh` 即生效
> 2. 仓库转 public → 同上（但规格书与业务细节对外可见）
> 3. 维持现状 → 靠自律 + 本地 commit-msg 钩子
>
> 在此期间请**仍然走 PR**：直推 `main` 会绕过 `commit-conventions` 检查，squash merge 的线性历史也会被打断。
>
> ⚠️ **单人期临时豁免**：脚本中 `bypass_actors` 给了 `repository_admin`，否则单人账号无法批准自己的 PR，`main` 会锁死。**团队到 2 人时立即删除该 bypass**（改为 `[]` 并重跑）。

## 4. 契约优先（MUST，§13.1）

1. `docs/api/openapi.yaml`（OpenAPI 3.1）是**唯一真相源**。任何接口变更**必须先改契约**，并在**同一个 PR** 内一并修改双端实现与契约测试。
2. **禁止**用 API Platform / NelmioApiDocBundle 从实现生成契约 —— 方向反了，会让契约跟着实现漂移。
3. Android 的 `core:network:api` 由 `openapi-generator` 生成，**提交入库但禁止手改**；CI 会重新生成并 diff，不一致即失败。
4. 所有 schema 必须 `additionalProperties: true`；客户端反序列化必须 `ignoreUnknownKeys = true`。

改完契约，**在提 PR 之前**跑这三条（第三条 T-010 起）：

```bash
npm run lint:api                                  # Spectral，0 error / 0 warn
cd backend && vendor/bin/phpunit --testsuite Api  # 契约测试

# Android 的生成代码，**产物要一起提交**
cd android && ./gradlew :core:network:api:generateApiClient
```

漏掉第三条，CI 的 `checkApiClientUpToDate` 会红并直接告诉你跑哪条命令。
**不要**手改 `android/core/network/api/generated/` 下的任何文件 ——
下一次生成会原样覆盖掉。

`docs/api/**` 会同时触发 `contract`、`backend` 与 `android` 三条流水线 ——
契约文件在 `docs/` 下，但校验它的测试在 `backend/` 下、消费它的生成代码在
`android/` 下，三条都得绿。

⚠️ 加新端点时最容易漏的一条：每个操作都要 `$ref` 到
`#/components/parameters/XClient`。OpenAPI 没有「全局请求头」，漏掉的话契约会说
这个端点不需要 `X-Client`，而后端会在运行时返回 400。Spectral 的
`ncards-operation-requires-x-client` 会拦下来。

规则集与三条守护测试的完整说明见 [`docs/api/README.md`](docs/api/README.md)。

API 演进规则（§13.6）：可以新增端点/可选字段/响应字段/枚举值（前提是客户端有 `UNKNOWN` 兜底）；**禁止**在 `/v1` 内删除或重命名字段、改类型、把可选变必填、收紧校验、改变错误 `code` 的含义。破坏性变更 → 新增 `/v2`，`/v1` 至少并行 6 个月。

## 5. 数据库迁移（MUST，§13.5）

离线优先 + 旧客户端长期存在 ⇒ **只允许 expand–contract**：

1. 发布 N：加**可空**新列 / 新表，双写。
2. 发布 N+1：回填历史数据，读切到新列。
3. 发布 N+2（≥ 2 周后）：删旧列 / 加 NOT NULL。

**禁止**：重命名列/表、直接删列、一次迁移中无默认值地 `SET NOT NULL`、加会锁表的索引（用 `CREATE INDEX CONCURRENTLY`）。

每个迁移文件顶部注释必须写明：**影响的表、预估执行时长、是否锁表、如何回滚**。每个迁移必须有可用的 `down()`。

## 6. ADR（§13.7）

符合以下任一条件的决策**必须**写 ADR 到 `docs/adr/NNNN-title.md`：

- 改变 §2 中已有的架构决策
- 引入新的基础设施组件或第三方服务（涉及 GDPR 子处理者，还需更新 §8.3）
- 改变数据模型的核心语义（共享、同步、加密）
- 引入新的跨模块依赖

模板与流程见 [`docs/adr/README.md`](docs/adr/README.md)。需技术负责人 + 至少一名工程师批准。

## 7. Definition of Done（§13.8）

一个任务只有满足**全部**以下条件才算完成：

- [ ] 代码合入 `main` 且 CI 全绿
- [ ] 契约（若涉及）已更新，双端一致
- [ ] 单元测试覆盖新增逻辑分支；集成测试覆盖新增端点
- [ ] 德语 + 英语文案齐全（无 `MissingTranslation`）
- [ ] 无障碍检查通过（触摸目标、contentDescription、对比度）
- [ ] 若引入个人数据处理 → §8 ROPA 已更新
- [ ] 若引入新限额/端点 → §7.5 限流已配置
- [ ] 若需要运维介入 → runbook 已写
- [ ] 在真机（低端设备：Android 8 + 2 GB RAM）上验证过

## 8. 质量门禁（CI 阻断，§13.3）

| 后端 | 阈值 |
|---|---|
| `php-cs-fixer --dry-run` | 0 差异 |
| `phpstan analyse` | level 8，`src/` 全量，0 error，baseline 只允许缩小 |
| `deptrac analyse` | 0 violation |
| `composer audit` | 0 高危 |
| 行覆盖率 | 整体 ≥ 70%；`src/Module/*/Domain` 与 `Application` ≥ 85% |

| Android | 阈值 |
|---|---|
| `ktlintCheck` / `detekt` | 0 |
| Android Lint | 0 error；`HardcodedText`、`MissingTranslation`、`ContentDescription` 提升为 error |
| 单元测试覆盖 | `core:*` 与 `data:*` ≥ 70%（`core:network:api` 有记录在案的豁免：整个模块是生成产物） |
| 生成代码 diff | 与契约重新生成结果一致 —— `./gradlew :core:network:api:checkApiClientUpToDate` |

| 通用 | 说明 |
|---|---|
| `gitleaks` | 0 命中 |
| 敏感日志扫描 | 禁 `dump(`、`var_dump`、`Log.d/v/i` 打印实体；禁日志中插值 `barcode_value` / `email` |
| TODO 检查 | `TODO` 必须带 issue 编号：`// TODO(#123): …` |

后端门禁在 `backend/` 下用 `composer qa` 一键跑完，细则见 [`backend/README.md`](backend/README.md)。

（完整 CI 流水线由 T-011 交付。当前仓库有四条：`commit-conventions`、`backend`、
`contract`、`android`。除 `commit-conventions` 外都带 `paths` 过滤，因此**都尚未**
加进 required status checks —— 不碰对应目录的 PR 永远不会上报那个 context，配上
strict 策略会把那类 PR 永久卡死。原因见
[`.github/workflows/backend.yml`](.github/workflows/backend.yml) 头部注释，
由 T-011 统一处理。）

Android 侧的三条本地命令（提 PR 前跑一遍，CI 也跑）：

```bash
cd android
./gradlew -p build-logic test     # §12.3 模块依赖规则表的单测
tools/module-graph-selftest.sh    # 证明违规依赖真的会让构建失败
./gradlew :core:network:api:checkApiClientUpToDate   # 契约与生成代码是否还对得上
```

⚠️ **新建 Android 模块时**，除了 `settings.gradle.kts` 的 `include`，还必须让它匹配到
`build-logic` 的 `ModuleGraph.kt` 里的一行规则 —— 匹配不到会直接构建失败。
那是刻意的摩擦：静默放行一个不受依赖约束的模块，代价比多改一行大得多。
见 [ADR-0006](docs/adr/0006-android-module-graph-enforcement.md)。

### `.env` 的两处例外

§14 的规则是「`.env` 绝不入库」，根 `.gitignore` 也是一刀切。T-002 对
`backend/.env` 与 `backend/.env.test` 开了两个**窄口**：它们是 Symfony 约定的
**非密钥默认值**文件，缺了它们 `bin/console` 直接起不来。约束：

- 这两个文件里**只能**有非密钥的默认值，任何看起来像凭据的东西都不许写进去
- 真实密钥走 `.env.local` / `.env.*.local`（仍被 `.gitignore` 全部忽略）、
  容器环境变量（T-003）、sops(age) 加密下发（T-012）
- 不要再开第三个口子

## 9. 范围纪律（§1.3）

任何"顺手加一下"的需求，若属于以下 **OUT 清单**，**一律拒绝**，不讨论、不"先留个接口"：

> iOS、图片存储、邀请链接、邮箱邀请、editor 角色、所有权转让、商家目录、到期提醒、订阅、通讯录导入、Web 端、一次性副本分享

3 个月 / 4 人的排期没有任何余量（§3.11）。想做的记到二期清单里。

## 10. 三个不能写错的语义（§13.4 / ADR-01 / §5.2）

| 语义 | 结论 |
|---|---|
| 谁能写卡 | **只有 owner**。viewer 除 `placement` 外无任何上行写入 |
| `sort_order` / `is_pinned` 存哪 | 在 `card_members` 上（**每成员私有**），不在 `cards` 上 |
| `change_log.audience` | `card` 广播给全体成员；`card_member` 点对点（**仅 owner + 当事人**）。删除类记录的 audience 取**删除前快照** |

第 3 条错了会**在数据库层面泄露 viewer 名单**，前端无法补救。
