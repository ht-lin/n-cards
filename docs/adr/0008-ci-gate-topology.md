# 0008. CI 用一个总是运行的 `pr-gate` 汇总，各条流水线改为 reusable workflow

- **Status**: Accepted
- **Date**: 2026-08-30
- **Deciders**: 全员（T-011）
- **规格引用**: §13.2、§13.3、§14.3
- **影响**：全部后续任务 —— 每加一条 CI 流水线都要回到这条 ADR 的 Consequences

## Context

§14.3 要三条并行的 PR 流水线（`backend` / `android` / `shared`），§13.2 要
「1 个 approve + 全部 CI 绿」才能合入。这两条各自都对，凑在一起有一个死结。

T-001 建的 ruleset（`scripts/setup-branch-protection.sh`）开了
`strict_required_status_checks_policy: true` —— 分支必须与 main 同步且**列出的
每个 check 都上报成功**才能合。而 T-002 / T-007 / T-008 交付的三条 workflow 都带
`paths` 过滤：只改 `README.md` 的 PR 不会触发 `backend`，于是那个 context
**根本不会出现**。GitHub 不会把它当成「跳过」，而是一直等 —— PR 永久卡死。

这个坑在 `backend.yml`、`android.yml`、`contract.yml` 三份文件顶部逐字重复了三遍，
每一遍都写着「留给 T-011 统一处理」。它的直接后果是：**到 T-011 之前，
required status checks 里只有 `commitlint` 一条**（唯一没有 paths 过滤的），
§13.3 的全部质量门禁在服务端一条都没有强制力。

去掉 paths 过滤不是出路：那意味着每个只改文档的 PR 都要跑一遍 Android 构建，
而 §14.3 给的预算是 12 分钟。

## Decision

**加一个总是运行的汇总 job `pr-gate`，它是唯一进入 required status checks 的
context。** 各条流水线改为 `on: workflow_call` 的 reusable workflow，
触发与编排集中到 `pr.yml`（PR）与 `main.yml`（合入）。

```
pr.yml
 ├─ changes          dorny/paths-filter，一处决定「这次该跑什么」
 ├─ backend          if: changes.backend  → backend.yml
 ├─ android          if: changes.android  → android.yml
 ├─ android-release  if: changes.android  → android-release.yml
 ├─ shared           无条件               → shared.yml
 └─ pr-gate          if: always()，needs 以上全部
```

`pr-gate` 遍历 `needs.*.result`，把 `success` 与 **`skipped`** 都算作通过，
其余一律失败。两个细节是必须的，少一个就回到原来的坑：

- **`if: always()`**。默认语义下，任何一个 `needs` 被 skipped，本 job 自己也会
  被 skipped —— 于是 gate 又变成「有时不上报」的 context。
- **`skipped` 算通过**。这正是 paths 过滤的语义：这条流水线与本次改动无关。

`main.yml` 复用同样四条 reusable workflow（不带 paths 过滤，全量跑），
再接仪器测试与 GHCR 镜像。

`contract.yml` 与 `commit-conventions.yml` 整体并入 `shared.yml` 后删除 ——
`contract.yml` 顶部的注释原本就是这么要求的。

## Consequences

**好的**

- §13.3 的全部门禁第一次真正对合入有强制力，而不是只有 `commitlint` 一条。
- 「这次 PR 该跑什么」有了唯一的答案（`pr.yml` 的 `changes`），而不是三份各自
  演化的 `paths` 清单。三份清单迟早会漂，而漂掉的那一条不会有任何症状。
- 换成 reusable workflow 之后，PR 与 main 跑的是**同一份**定义，不存在
  「main 上多跑了一步，PR 上没有」这类偏差。

**要付的代价（这条必须记住）**

- **加一条新流水线时，必须同时把它加进 `pr-gate` 的 `needs`。** 不加，它红了 PR
  照样能合 —— 而且看起来一切正常。这是整套拓扑唯一的失效模式，也是它换来
  「只配一个 required check」的代价。
- 改 PR 标题（`types: [edited]`，commitlint 要重跑）会重跑全部三条。用
  `concurrency` + `cancel-in-progress` 兜住，多花的是 runner 时间不是人的时间。
- 引入了一个第三方 action（`dorny/paths-filter`）。它只读 `github` 上下文与 git，
  不需要任何 secret。自己写一段 `git diff --name-only` 也能做，但那意味着自己维护
  一套 glob 匹配 —— 收益不抵成本。

## Alternatives considered

1. **把四条 workflow 都配进 required status checks。** 就是上面那个死结，
   已经被三份文件的注释逐字警告过三遍。
2. **去掉 paths 过滤，让三条都无条件跑。** required checks 直接可用，但每个改一行
   文档的 PR 都要跑 Android 构建 + 后端集成测试。§14.3 的 12 分钟预算是给
   「真的改了代码」的 PR 的，不是给排版修正的。
3. **单文件 `pr.yml`，把四条 workflow 的内容全合进去。** 文件数最少，但
   `backend.yml` / `android.yml` 顶部那些记录「为什么有 pg/redis/vault 三个 service」
   「为什么 lint 只跑 `:app:lintDebug`」的长注释要挤进同一个文件，而且 `main.yml`
   无法复用，只能复制一遍 —— 复制出来的那一份会漂。
4. **保留四条 workflow，另加一条无 paths 过滤的 `merge-gate`，用 GitHub API 轮询
   其余 workflow 的结论。** 能进 required checks，但轮询逻辑要自己处理「还没开始」
   与「不会开始」的区别 —— 而这两者在 API 上看起来一模一样。失败信息也差得多。

## 与 §14.3 的一处偏差

§14.3 把 `openapi-codegen-diff` 划给 `shared` 流水线。实际留在了 `android` ——
`:core:network:api:checkApiClientUpToDate` 需要 JDK 17 + Android SDK + Gradle
（`android.yml` 顶部原本就写着「搬过去要连着环境一起搬」），搬进 `shared` 等于在
关键路径上再装一遍 Android SDK，白吃 2–3 分钟。

门禁效果完全相同：两条 job 都挂在同一个 `pr-gate` 下，任一条红 PR 就合不进去。
前提是 `changes` 的 `android` 过滤器包含 `docs/api/**` —— 它包含，理由写在
`pr.yml` 那一行的注释里。
