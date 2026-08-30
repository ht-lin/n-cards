# 0003. Problem Details 错误码表扩展 8 个 code，幂等冲突用 409/422，幂等 fail-open

- **Status**: Accepted
- **Date**: 2026-08-27（提出）／2026-08-30（批准）
- **Deciders**: 创始人
- **规格引用**: §6.1、§5.4.3、§9.2、§13.6、§14.2
- **相关**：T-004（Shared 内核 / HTTP 横切层）
- **影响**：T-006（限流）、T-010（Android `ApiError` 生成）、T-1xx（幂等作用域）

## Context

T-004 要把 §6.1 的「统一错误码表」落成一个 PHP enum，并实现
`Idempotency-Key`（§6.1：所有 POST 支持，Redis 存 24h）。实现过程中出现三个
规格没有回答、但必须当场定下来的问题：

1. **§6.1 的错误码表覆盖不到若干真实存在的情况。** 任何 HTTP API 都会产生
   405（方法不允许）、415（Content-Type 不对）、413（体积超限）、畸形 JSON
   请求体，以及未预期的 500。表里一个都没有。另外 `id_conflict` 在 §5.4.3
   里被定义和引用，却没有出现在 §6.1 的表中 —— 这是规格自身的不一致。

2. **幂等冲突没有对应的错误码。** 规格要求所有 POST 支持 `Idempotency-Key`，
   但没有说：同一个键配上不同的请求体该返回什么？前一次请求还在处理中时，
   并发进来的第二个请求该返回什么？

3. **Redis 不可用时应当 fail-open 还是 fail-closed？** 规格没有讨论。
   而 T-004（幂等）与 T-006（限流）都依赖 Redis，两者的正确答案**相反** ——
   这个不对称不写下来，会被后来的 reviewer 当成 bug 顺手「修」掉。

## Decision

### 1. 扩展 §6.1 的错误码表（新增 8 个）

`malformed_request`(400)、`method_not_allowed`(405)、`id_conflict`(409)、
`idempotency_in_progress`(409)、`payload_too_large`(413)、
`unsupported_media_type`(415)、`idempotency_key_reused`(422)、`internal_error`(500)。

§13.6 允许在 `/v1` 内新增错误码（向后兼容），禁止的是改变已有 code 的含义。
选择**现在**扩表而不是以后：Android 的 T-010 会照这张表生成 `ApiError` sealed class，
在那之前定好，客户端就不需要为「新增的 code」做一次迁移。

三方一致由 CI 强制：
`App\Shared\Domain\Error\ErrorCode` ↔ `docs/api/schemas/problem-details.schema.json`
↔ 本表，任一处改动而不同步另两处，`ProblemDetailsSchemaTest` 与
`ErrorCodeTest` 会红。

### 2. 幂等冲突的状态码

- 同键 + 不同请求体 → **422 `idempotency_key_reused`**
- 同键 + 前一次仍在处理中 → **409 `idempotency_in_progress`** + `Retry-After: 1`

与 IETF 的 `Idempotency-Key` header-field draft 一致。选这两个而不是别的组合，
真正的理由是**客户端一行代码都不用改**：§5.4.3 已经规定 Android 的 outbox
「4xx（除 409/429）不重试」。于是 409 会被自动重试（稍后拿到回放），
422 会快速失败（那确实是客户端 bug，重试永远不会成功）。

另定义响应头 `Idempotency-Replayed: true` 标记回放命中 —— 标准里没有，
是本项目自定义的，必须进 T-007 的契约与 T-010 的拦截器。

### 3. 只持久化 2xx 响应

非 2xx 一律释放锁，让客户端能用同一个键重试。

### 4. 幂等 fail-open；限流（T-006）默认 fail-closed

Redis 不可达时，`IdempotencyMiddleware` 记一条 error 日志并**当作没带幂等键继续处理**。

> **2026-08-30 补记**：限流侧的落地细节由 [ADR-0005](0005-rate-limiting-topology.md) 收口，
> 它把本条细化为「**默认** `deny`（fail-closed），且只对 §7.5 的通用写限流
> （`write_endpoints`，纯防 DoS、非安全控制）开唯一一个 `on_store_failure: allow` 例外」。
> 本节说「限流 fail-closed」指的是**安全类**限流（OTP、username 枚举）；
> 完整表述以 ADR-0005 §2、§3 为准。

## Consequences

### 正面

- 客户端只需对 `code` 分支就能覆盖全部真实情况，不再有「掉进 else 分支」的错误。
- 幂等的两个冲突状态码与既有的 outbox 重试策略天然吻合。
- 一次 Redis 抖动不会变成写入不可用。§9.2 的 99.5% 可用性在 30 天里只有
  3.6 小时预算，而 §14.2 的 Redis 是**单容器、无 HA** —— fail-closed 下
  一次重启就能吃掉可观的一块。

### 负面（必须写下来）

- **扩表让 §6.1 从 18 个 code 涨到 26 个**，Android 的 `when` 分支相应变长。
- **fail-open 意味着 Redis 故障期间幂等保护消失**：那段时间里客户端的重试
  可能导致重复创建。这个代价是**有界**的 —— §5.4.3 已经给创建类端点提供了
  更强、且与存储无关的幂等保证（客户端生成 id，重复 → `200` + 现有实体），
  Redis 只是第二道保险。故障本身不静默：`RedisHealthCheck` 会把
  `/health/ready` 翻成 503，Caddy / Ansible / §14.3 的部署健康检查都看得到。
- **T-004 与 T-006 的降级方向相反**，这在代码里看起来像不一致。
  限流 fail-open 等于在故障期间关掉 §7.5 的 OTP 与 username 枚举防线 ——
  那是**安全控制**，不是便利功能，所以必须 fail-closed（唯一例外见 ADR-0005 §3）。
  两处代码都要引用本 ADR。
- `Idempotency-Replayed` 是自定义 header，增加了一处「我们和标准不一样」的地方。

## Alternatives considered

**不扩表，把新情况映射到已有 code。** 即 500/405 → `service_unavailable`，
幂等冲突 → `already_exists`。规格零改动，但把三种语义压到两个 code 上：
客户端无法区分「服务端在维护」与「服务端有 bug」，也无法区分「资源已存在」
与「你的幂等键用法不对」。而 §13.6 禁止事后改变 code 含义 —— 一旦这么发出去，
就再也纠正不回来了。**否决。**

**只为幂等冲突补一个 code，500/405 走框架默认错误页。** 改动最小，
但 API 会出现两种错误响应格式，直接违反「客户端只对 `code` 分支」。**否决。**

**幂等 fail-closed（Redis 挂 → 503）。** 语义最严格，但把一个可用性
依赖（Redis）变成了全部写入路径的硬依赖，与 §14.2 的单容器部署形态直接冲突。
**否决**，理由见上。

## 脚注：T-004 期间的一条相邻决定 —— 游标不签名

不是上面三个问题的备选方案，是 T-004 落地时顺带定下的另一件事，记在这里以便检索。

**`Cursor` 不签名、不加密**，只做 base64url(JSON) 的往返加一组守卫（长度、字母表、
JSON 深度）。载荷是调用方本来就持有的排序键（它们就在上一页的响应里），而服务端每次
查询都会重新施加归属 / `audience` 过滤（§5.2、§4.2 规则 5），所以篡改游标最多只能在
自己已授权的结果集里换个窗口，不构成越权。签名则会把 Vault 密钥管理（T-005）拖进分页
这条最热的路径，还要处理密钥轮换期间的旧游标兼容，换来零威胁缓解。**否决签名。**

代价：游标内容对客户端可读，暴露了排序键的形状（例如 `seq` 是单调整数）——
若将来往游标载荷里放敏感值，这条要重新审视。完整论证与那段「不要顺手把游标签个名」
的警告写在 `Shared\Domain\Pagination\Cursor` 的类注释里。
