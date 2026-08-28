# API 契约

`openapi.yaml`（OpenAPI **3.1**）是 API 的**唯一真相源**（§13.1）。**由 T-007 交付**，尚不存在。

## 已交付：`schemas/problem-details.schema.json`（T-004）

RFC 9457 Problem Details 的 JSON Schema（draft 2020-12）—— §6.1 错误响应的形状。
T-004 的验收标准要求「契约测试能对 Problem Details schema 校验通过」，而 T-007
还没交付 `openapi.yaml`，所以这份 schema 先独立落地。

**⚠️ 给 T-007**：`components/schemas/Problem` 必须用 `$ref` **指向**这个文件，
**不要复制一份** —— 两份 `code` 枚举必然会漂。

三方一致由 CI 强制：

| 位置 | 角色 |
|---|---|
| `docs/TECHNICAL_SPEC.md` §6.1 的错误码表 | 规格，Android T-010 生成 `ApiError` 的输入 |
| `backend/src/Shared/Domain/Error/ErrorCode.php` | 后端实现，无 `default` 分支的 `match` |
| `docs/api/schemas/problem-details.schema.json` | 契约 schema |

`backend/tests/Unit/Shared/Domain/Error/ProblemDetailsSchemaTest` 断言后两者的
枚举**逐项相等**；`ErrorCodeTest` 的黄金对照表守着状态码与 title；
`backend/tests/Api/ProblemDetailsContractTest` 对**每一个** code 做真实 HTTP 往返
并校验 schema。改一处不改另两处，CI 立刻红。

### 通用列表信封（T-004 补进 §6.1）

所有游标分页端点共用：

```json
{ "items": [], "next_cursor": "eyJ2IjoxLC...", "has_more": true }
```

`has_more` 为 `false` 时 `next_cursor` 恒为 `null`。名字沿用 §5.4.2 的同步响应，
唯一的新名字是 `items`。T-007 只需 schematise 一次，不必给七个列表端点各写一遍。

### 两个 Content-Type

- 成功响应：`application/json; charset=utf-8`（§6.1）
- 错误响应：`application/problem+json`（RFC 9457，**不带** charset —— JSON 按定义就是 UTF-8）

### 可选成员会被**省略**

`errors` 与 `current` 为空时**不出现**，不会发成 `[]` / `{}`。
所以 schema 里不能把它们标成 `required`，T-010 的 Kotlin 模型必须给默认值。

## 契约优先（MUST）

1. 任何接口变更**必须先改 `openapi.yaml`**，并在**同一个 PR** 内一并修改后端实现、契约测试与 Android 生成代码。
2. **禁止**用 API Platform / NelmioApiDocBundle 从实现生成契约 —— 方向反了，会让契约跟着实现漂移。
3. 后端：`backend/tests/Api` 用 `league/openapi-psr7-validator` 对每个端点的真实请求/响应做 schema 校验，不符即 CI 失败。
4. Android：`android/core/network/api` 由 `openapi-generator`（`kotlin` + `retrofit2` + `kotlinx-serialization`）生成，**提交入库但禁止手改** —— CI 重新生成并 diff，不一致即失败。
5. 所有 schema 必须 `additionalProperties: true`（前向兼容）；客户端必须 `ignoreUnknownKeys = true`。

## CI 校验（T-007 起）

Spectral 自定义规则集：必须有 `operationId`、必须有错误响应、必须有示例、所有 schema `additionalProperties: true`。

## 演进规则（§13.6）

**允许**：新增端点、新增可选请求字段、新增响应字段、新增枚举值（前提是客户端有 `UNKNOWN` 兜底分支，须由生成器配置保证）、放宽校验。

**禁止（在 `/v1` 内）**：删除/重命名字段、改字段类型、把可选变必填、收紧校验、改变错误 `code` 的含义、改变默认值语义。

破坏性变更 → 新增 `/v2`，`/v1` 至少并行 **6 个月**，期间通过 `/v1/config` 的 `min_supported_client` 推动升级。

参考片段见规格书 §17.2。
