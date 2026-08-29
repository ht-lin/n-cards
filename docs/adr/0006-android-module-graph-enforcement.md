# 0006. Android 模块依赖规则用配置期的 Gradle 规则表强制，不用自定义 lint 规则

- **Status**: Accepted
- **Date**: 2026-08-29
- **Deciders**: Android 负责人
- **规格引用**: §4.3、§12.3、§13.3
- **影响**：全部 Android 任务（T-009 起），尤其是新建模块的任务

## Context

§12.3 给了四条模块依赖规则：

```
app → feature:* → data:* → core:*
feature:* 之间禁止互相依赖（跨 feature 导航经 app 的 NavHost + core:model 的路由定义）
core:* 不得依赖 data:* / feature:*
sync 可依赖 data:* 与 core:*，不得被 feature:* 直接依赖（feature 只经 Repository）
```

紧接着写了一句实现建议：「违反用 Gradle 的**模块可见性** + **自定义 lint 规则**检查。」

T-008 落地时这两条都不成立：

1. **Gradle 没有模块可见性机制。** `api` 与 `implementation` 管的是传递依赖要不要
   暴露给下游，挡不住任何人在 `feature/wallet/build.gradle.kts` 里直接写
   `implementation(project(":feature:scan"))`。那正是要挡的动作。
2. **自定义 lint 规则来得太晚。** T-008 的验收标准原文是「故意在两个 feature 间加
   依赖会**构建失败**」。lint 要等到 `lintDebug` 任务，而那时 `assembleDebug` 已经
   成功了 —— 本地开发者根本不会看到红。lint 还要求写一个 `com.android.tools.lint`
   的 Detector + 自己的模块 + 自己的测试，成本远高于收益。

这条规则的价值不在「今天没人违规」，而在**将来**：三个月里会有 30 多个任务往这些
模块里填代码，其中若干次会遇到「就用一下隔壁 feature 的那个 Composable」的诱惑。

## Decision

**在 Gradle 的配置期执行一张规则表**，落在 `build-logic` 的
`de.ncards.buildlogic.NcardsModuleGraph`（规则表 + 纯函数）与 `NcardsModuleGraphPlugin`
（插件，`ncards.module.graph`）。

- 插件在 `afterEvaluate` 里遍历本模块所有 `isCanBeDeclared` 的 configuration，
  取出 `ProjectDependency`，逐条过 `violationOf(from, to)`，违规即 `error(...)`。
- 每个模块都必须应用它。`ncards.android.{application,library}` 与
  `ncards.jvm.library` 各自应用；`benchmark`（`com.android.test`，不走前三者）
  在自己的 build 文件里显式应用。
- 覆盖率由 `ModuleGraphTest.testEveryIncludedModuleIsCoveredByARule()` 兜底：
  它读 `settings.gradle.kts`，断言每个 `include` 的模块都能匹配到规则表的一行。

三个落地细节，都是踩过之后写下来的：

- **放 `afterEvaluate`，不放 `Configuration.withDependencies`。** 后者要等依赖解析
  才触发，`./gradlew help` 不会红；前者让任何任务（含验收标准点名的
  `assembleDebug`）在配置期就失败，快两个数量级。
- **只扫 `isCanBeDeclared` 的 configuration。** 遍历全部 configuration 会过早实体化
  AGP 的可解析 configuration。
- **用 `ProjectDependency.path`。** Gradle 9 已移除 `getDependencyProject()`。

**不在根 `build.gradle.kts` 用 `subprojects { }` 统一挂。** 那会破坏 Gradle 9.7 起
incubating 的 Isolated Projects，而 AGP 9 正在往那个方向推。同样的理由让
ktlint / detekt / Kover 也改成逐模块施加（`ncards.quality`）。

### 两道自检，缺一不可

与 `backend/tools/deptrac-selftest.sh` 是同一个思路——`./gradlew assembleDebug` 全绿
只能证明「当前代码没违规」，证明不了「违规会被拦下」。这里需要**两层**：

| | 验什么 | 验不了什么 |
|---|---|---|
| `ModuleGraphTest`（build-logic 单测，毫秒级） | 规则表对不对（穷举四条规则的正反例） | 规则有没有被接到构建上 |
| `tools/module-graph-selftest.sh`（秒级） | 规则真的在构建里生效（注入 4 条真实违规） | 规则表覆盖得全不全 |

谁把 `NcardsModuleGraphPlugin` 从 `ncards.android.library` 里删掉，单测依然全绿、
脚本立刻变红；谁把规则表改松了，脚本可能依然绿、单测立刻变红。

## Consequences

**正面**

- 违规在配置期就失败，本地与 CI 行为一致，`./gradlew help` 即可复现。
- 规则表是一份可读的 Kotlin `Map`，评审时看得见「谁能依赖谁」的全貌。
- 错误文案直接给出替代方案（下沉到 core:ui / 经 Repository / 在 app 的 NavHost 接线），
  而不是只说「不允许」。

**负面 / 代价**

- 新建模块时**必须**同时改 `ModuleGraph.kt`，否则构建直接失败。这是刻意的摩擦：
  静默放行一个不受约束的模块，比多改一行代价大得多。
- `afterEvaluate` 是 Gradle 里公认容易出问题的钩子。这里只读 `configurations` 的
  声明态、不改任何模型，是它可接受的用法之一。
- 规则表用**路径前缀**匹配，因此模块命名必须守住 `:core:` / `:data:` / `:feature:`
  这三个前缀。这与 §12.3 的目录结构本来就是一回事。

**落地时被规则本身纠正的一处认知**

起初以为 `benchmark` 的 `targetProjectPath = ":app"` 只是一个字符串指针，规则表里给了
空的允许列表。第一次构建时插件当场报出 `:benchmark -> :app` 违规 —— AGP 把它放进了
一个名为 `testedApks` 的真实 configuration。规则表因此改为 `:benchmark -> [":app"]`，
且**只有** `:app`：Macrobenchmark 是黑盒测量（§9.1），一旦能 import 生产代码，
就会有人写出「先直接调 Repository 预热再测」这种把基准变成谎言的代码。

## Alternatives

- **自定义 Android Lint 规则（§12.3 的字面建议）**：来得太晚（`lintDebug` 而非配置期），
  且实现成本高。可以作为将来的**补充**（比如检查 import 而非模块依赖），不是替代。
- **`java-library` 的 `api`/`implementation` 可见性**：只管传递暴露，挡不住直接依赖。
- **完全不强制，靠 code review**：三个月、30 多个任务的排期下，等同于不强制。
- **每个模块手写允许列表**：真相源从 1 个变成 30 个，且新模块默认无约束。
