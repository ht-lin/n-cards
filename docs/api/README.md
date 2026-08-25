# API 契约

`openapi.yaml`（OpenAPI **3.1**）是 API 的**唯一真相源**（§13.1）。**由 T-007 交付**，当前目录为空。

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
