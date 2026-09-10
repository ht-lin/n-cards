# API 契约

`openapi.yaml`（OpenAPI **3.1**）是 API 的**唯一真相源**（§13.1）。由 T-007 交付。

```bash
npm install
npm run lint:api      # Spectral，见下方「CI 校验」
```

## 首版覆盖了什么（T-007）

**Auth（5 个）+ Cards（6 个）+ 全套通用组件**。

§6.2 的其余端点 —— Sharing、Friends、Sync ——
**故意还没在里面**。这不是遗漏，是「契约优先」的正常工作方式：端点与它的 schema
由**拥有它的那个任务**在同一个 PR 里一并加进来（§13.1 第 1 条）。提前把 schema
全摆好，只会得到一堆没人对照实现校对过的定义，和 Spectral 的
`oas3-unused-component` 告警。

已经照这个方式补进来的：Me/Devices（T-108）、`GET /v1/config`（T-112）。

通用组件已经全部就位，补端点时直接 `$ref` 即可：

| 组件 | 内容 |
|---|---|
| `schemas/Problem` | `$ref` 到 `schemas/problem-details.schema.json`（见下） |
| `schemas/CardPage` | 通用列表信封 `{items, next_cursor, has_more}` |
| `parameters/` | `XClient`、`IdempotencyKey`、`IfMatch`、`CardId`、`Cursor`、`Limit` |
| `headers/` | `XRequestId`、`IdempotencyReplayed`、`RetryAfter`、`XRateLimitRemaining` |
| `responses/` | 12 个可复用错误响应：400 / 401 / 403 / 404 / 409 / 413 / 415 / 422 / 426 / 429 / 500 / 503 |
| `securitySchemes/bearerAuth` | 全局默认；auth 组各自 `security: []` 覆盖 |

## 三条测试守着这份契约（T-007）

| 测试 | 守什么 |
|---|---|
| `backend/tests/Api/OpenApiDocumentTest` | 契约与**仓库里已经写死的东西**是否还对得上：路由表、`ErrorCode`、`ClientVersion`、`CursorPaginator` |
| `backend/tests/Api/OpenApiContractHarnessTest` | 校验器**能不能咬人** —— 契约自己的 example 正向全过，改坏之后必须被拒 |
| `.spectral.yaml`（`npm run lint:api`） | 契约本身写得对不对 |

其中最要紧的一条是 `OpenApiDocumentTest::testEveryProductApiRouteIsDeclaredInTheContract()`
—— **它是 §13.1「契约优先」在 CI 里的强制点**：路由表里每条 `/v1` 路由都必须在契约里
找得到。今天 `/v1` 下只有 `when@test` 的探针，这条断言空过；从第一个真实端点（T-103）
落地那天起，「先写 Controller、忘了改 openapi.yaml」的 PR 当场红。

它是 `RouteInventoryTest` 的对偶：那条守「谁可以不在 `/v1` 下」，这条守
「在 `/v1` 下的都必须在契约里」。

> **给下一个改契约的人**：`docs/api/**` 同时触发 `contract` 与 `backend` 两条流水线
> （`backend.yml` 的 `paths` 里加了 `docs/api/**`）。少了后者，只改契约不碰
> `backend/**` 的 PR 就跑不到上面三条测试里的前两条。

## `schemas/problem-details.schema.json`（T-004）

RFC 9457 Problem Details 的 JSON Schema（draft 2020-12）—— §6.1 错误响应的形状。
T-004 的验收标准要求「契约测试能对 Problem Details schema 校验通过」，而 T-007
当时还没交付 `openapi.yaml`，所以这份 schema 先独立落地。

**T-007 已照办**：`components/schemas/Problem` 是一个指向这个文件的 `$ref`，
**没有复制一份**。两条测试钉着这一点：

- `OpenApiDocumentTest::testProblemSchemaIsAReferenceToTheSharedFileNotACopy()`
  看**未解引用**的 yaml —— 复制一份的话解引用后的结果一模一样，只有原始形态能分辨。
- `OpenApiDocumentTest::testResolvedProblemCodeEnumMatchesErrorCode()`
  看**解引用之后**的结果 —— `$ref` 指错文件、或路径变化让解引用悄悄退化成空 schema，
  都会在这里红。

因此「对着这份 JSON Schema 校验」与「对着契约里的 Problem 校验」是同一件事，
`ProblemDetailsContractTest` 不需要再走一遍 yaml。

四方一致由 CI 强制（第四方是 T-010 补的）：

| 位置 | 角色 |
|---|---|
| `docs/TECHNICAL_SPEC.md` §6.1 的错误码表 | 规格，Android `ApiError` 的输入 |
| `backend/src/Shared/Domain/Error/ErrorCode.php` | 后端实现，无 `default` 分支的 `match` |
| `docs/api/schemas/problem-details.schema.json` | 契约 schema |
| `android/core/network/impl/.../error/ApiError.kt` | Android 实现，无 `else` 分支的 `when` |

`backend/tests/Unit/Shared/Domain/Error/ProblemDetailsSchemaTest` 断言中间两者的
枚举**逐项相等**；`ErrorCodeTest` 的黄金对照表守着状态码与 title；
`backend/tests/Api/ProblemDetailsContractTest` 对**每一个** code 做真实 HTTP 往返
并校验 schema。改一处不改另三处，CI 立刻红。

Android 那一侧的钉法不一样，比测试更早：`Problem.toApiError` 是一个**没有 `else`
的 `when`**，直接 `when` 生成的 `Problem.Code`。契约加一个 code、重新生成之后，
那个 `when` 不再穷举，**编译**就失败。`ApiErrorCoverageTest` 再补一层，
抓「两个 code 被复制粘贴到同一个类型上」这种编译器看不出来的错。

### 通用列表信封（T-004 补进 §6.1）

所有游标分页端点共用：

```json
{ "items": [], "next_cursor": "eyJ2IjoxLC...", "has_more": true }
```

`has_more` 为 `false` 时 `next_cursor` 恒为 `null`。名字沿用 §5.4.2 的同步响应，
唯一的新名字是 `items`。T-007 只 schematise 了一次（`components/schemas/CardPage`）。
将来别的列表端点照抄它、只换 `items` 的元素类型，**不要**把
`next_cursor` / `has_more` 在每个端点里重写一遍。

### 两个 Content-Type

- 成功响应：`application/json; charset=utf-8`（§6.1）
- 错误响应：`application/problem+json`（RFC 9457，**不带** charset —— JSON 按定义就是 UTF-8）

### 可选成员会被**省略**

`errors` 与 `current` 为空时**不出现**，不会发成 `[]` / `{}`。
所以 schema 里不能把它们标成 `required`，Kotlin 模型必须给默认值。

T-010 已照办：生成的 `Problem` 里这两个成员都是 `= null`，而
`core:network:impl` 的 `Json` 配了 `explicitNulls = false`，
让「缺席」与「显式 `null`」在解码时表现一致。`ApiError.ValidationFailed.fieldErrors`
对外是一个空列表而不是 `null` —— 调用方不需要为「有 errors 但是空的」写分支。

### 自定义响应头（T-004 / T-006）

标准里没有这几个，但它们是契约的一部分。**T-007 已经把它们写进 `openapi.yaml`
（`components/parameters/*` 与 `components/headers/*`），T-010 的拦截器必须读它们**
—— 否则客户端无从区分「真的执行了」与「拿到了回放」，也无从知道该等多久重试。

| Header | 出现在 | 含义 | 交付 |
|---|---|---|---|
| `Idempotency-Key` | 请求（任意 POST，可选） | 幂等键，Redis 存 24h（§6.1） | T-004 |
| `Idempotency-Replayed: true` | 回放命中的响应 | 本次是回放，**没有**真正执行 | T-004 |
| `X-Client` | 所有 `/v1/*` 请求（**必填**） | `android/1.4.0 (26)`（§6.1） | T-004 |
| `X-Request-Id` | 所有响应 | 追踪 id，与 problem body 的 `request_id` 同值 | T-004 |
| `Retry-After` | `429` / `503` / `409 idempotency_in_progress` | **秒数**（不是 HTTP-date）。多维度限流时是**更长的**那个（§7.5） | T-006 |
| `X-RateLimit-Remaining` | `429` | 剩余次数，多窗口取最小值。超限时恒为 `0` | T-006 |

⚠️ §7.5 明文要求限流响应**必须**同时带 `Retry-After` 与 `X-RateLimit-Remaining`。
回归测试 `backend/tests/Api/RateLimitTest`。

⚠️ 限流的两种失败要分开看：
- `429 rate_limited` —— 「我判定你超限了」，按 `Retry-After` 退避。
- `503 service_unavailable` —— 「我**无法判定**」（Redis 不可达 + 该策略 fail-closed，
  见 [ADR-0005](../adr/0005-rate-limiting-topology.md)）。这是服务端故障，
  走 §5.4.3 的 outbox 重试策略。

而 `422 limit_exceeded`（系统限额，§7.5 的第一张表）与限流**完全是两回事**：
它是一个绝对的存量上限，重试永远不会成功，客户端应该展示「额度已满」而不是「稍后重试」。

## 契约优先（MUST）

1. 任何接口变更**必须先改 `openapi.yaml`**，并在**同一个 PR** 内一并修改后端实现、契约测试与 Android 生成代码。
2. **禁止**用 API Platform / NelmioApiDocBundle 从实现生成契约 —— 方向反了，会让契约跟着实现漂移。
3. 后端：`backend/tests/Api` 用 `league/openapi-psr7-validator` 对每个端点的真实请求/响应做 schema 校验，不符即 CI 失败。
4. Android：`android/core/network/api` 由 `openapi-generator`（`kotlin` + `retrofit2` + `kotlinx-serialization`）生成，**提交入库但禁止手改** —— CI 重新生成并 diff，不一致即失败。
5. 所有 schema 必须 `additionalProperties: true`（前向兼容）；客户端必须 `ignoreUnknownKeys = true`。

> ⚠️ **第 5 条在「请求」方向上的含义**（T-007 补）：它的目的是让**客户端**前向兼容
> —— 服务端将来加响应字段时，老客户端不能因为多了一个键就崩。
>
> 但后端对**请求体**里的未知字段是**主动拒绝**的（`400 validation_failed` +
> `errors[].code = unknown_field`）。也就是说契约在请求方向上比实现**宽**：
> 它描述的是「最大可接受形状」，不是「服务端保证接受任意字段」。
>
> 不要为了「让契约和实现一致」把请求 schema 改成 `additionalProperties: false`
> —— 那会让 §13.6 明确允许的「新增可选请求字段」变成破坏性变更。

## CI 校验（T-007 起）

`.spectral.yaml` + `.spectral/functions/`，本地 `npm run lint:api`，
CI 跑在 `.github/workflows/contract.yml`（T-011 会并进 `shared` 流水线）。

**任务书点名的四条**

| 规则 | 为什么 |
|---|---|
| 必须有 `operationId` | T-010 用它生成 Retrofit 的方法名 |
| 必须有错误响应（至少一个 4xx） | 没有的话生成的客户端对这个端点没有任何错误分支 |
| 必须有示例 | 示例同时是 review 的锚点与 `OpenApiContractHarnessTest` 的夹具 |
| 所有 object schema `additionalProperties: true` | 前向兼容的地基（递归下钻，内联 schema 也查） |

**另加四条，守的是已经写死在代码里的东西**

| 规则 | 守什么 |
|---|---|
| `ncards-operation-requires-x-client` | OpenAPI 没有「全局请求头」，只能逐操作声明。漏一个，契约就说该端点不需要 `X-Client`，而 `ClientVersionListener` 会在运行时 400 |
| `ncards-error-response-is-problem-json` | 错误响应写成 `application/json` 会让 Android 把它喂给成功解析器 |
| `ncards-no-offset-pagination` | 逐字对齐 `CursorPaginator::FORBIDDEN_PARAMS`。那边运行时 400，这边 lint 时红 |
| `ncards-no-removed-v11-definitions` / `ncards-no-editor-role` | §17.2 的 v1.1 已删定义与 `editor` 角色不得复活（§1.3 范围纪律） |

`npm run lint:api` 带 `--fail-severity=warn` 是刻意的：`spectral:oas` 里不少有价值
的检查默认是 warning，而这个仓库只有一份契约，没有「先记个 warning 以后再说」的
余地 —— warning 会一直在那儿，然后被下一个人当成背景噪音。

## 外部 `$ref` 的三个消费者（T-010 已全部验过）

`components/schemas/Problem` 是 `$ref: './schemas/problem-details.schema.json'`
—— 一个跨文件的相对引用。三个消费者都能解析它：

- **Spectral**：能（`npm run lint:api` 绿）。
- **`league/openapi-psr7-validator` 0.24**（底层 `devizzent/cebe-php-openapi`）：能。
  `OpenApiDocumentTest::testResolvedProblemCodeEnumMatchesErrorCode()` 断言解引用后
  真的拿到了那 26 个 `code`。
- **`openapi-generator` 7.25.0**：能（T-010 实测）。生成的 `Problem.Code`
  就是那 26 项，`ProblemFieldError.Code` 是 9 项。

所以 T-007 预留的 `npm run bundle:api` **没有用上**，也不需要加 —— 契约保持一份，
`code` 枚举也保持一份。

### 但生成器仍然需要一份**派生**的输入（T-010）

不是因为 `$ref`，是因为**第 5 条**（所有 schema `additionalProperties: true`）。
`openapi-generator` 把「有 `properties` **又**有 `additionalProperties`」的 schema
当成 Map，于是 15 个模型全部继承一个语法非法的
`kotlin.collections.HashMap<String, kotlin.Any>()()`，一个都编译不过；嵌套模型
（`Card` / `User` / `Device` / `ProblemFieldError`）还会被额外打上 `@Contextual`。
生成器侧没有开关（`config-help -g kotlin` 与 `--openapi-normalizer` 都没有）。

`android/build-logic` 的 `ncards.openapi` 因此在生成前派生一份剥掉
`additionalProperties: true` 的副本到 `android/core/network/api/build/openapi-input/`，
**只喂给生成器**，不入库。这对客户端**没有任何语义损失**：前向兼容来自
`core:network:impl` 的 `Json { ignoreUnknownKeys = true }`（§3.10），
从来不来自 schema 上的那个布尔。

⚠️ 只剥**同时声明了 `properties`** 的那些。`Problem.current` / `Problem.debug`
是真的自由形态对象（只有 `type: object`），剥了它们会让生成的类型从
`Map<String, JsonElement>` 退化成裸 `Any` —— 那才是真的丢信息。

⚠️ 契约本身**一个字都没动**。要改生成行为，改
`android/core/network/api/openapi-generator-config.yaml` 或 build-logic 的派生逻辑，
**不要**为了迁就生成器去改 `openapi.yaml`。

### `info.license.identifier`（T-010 补）

OAS 3.1 里 `identifier` 是**可选**的，但 `openapi-generator` 的 spec 校验器把它当
必填，不写就 `Error count: 1` 直接中止生成。另一条出路是给生成器加
`--skip-validate-spec`，但那会连同「`$ref` 指不到」「响应少了 `content`」这类真问题
一起关掉。所以补了 `identifier: LicenseRef-N-Cards-Proprietary`（SPDX 给非标准许可证
留的合法形态），它是纯元数据，不碰任何 API 表面。

## 演进规则（§13.6）

**允许**：新增端点、新增可选请求字段、新增响应字段、新增枚举值（前提是客户端有 `UNKNOWN` 兜底分支，须由生成器配置保证）、放宽校验。

**禁止（在 `/v1` 内）**：删除/重命名字段、改字段类型、把可选变必填、收紧校验、改变错误 `code` 的含义、改变默认值语义。

破坏性变更 → 新增 `/v2`，`/v1` 至少并行 **6 个月**，期间通过 `/v1/config` 的 `min_supported_client` 推动升级。

参考片段见规格书 §17.2。
