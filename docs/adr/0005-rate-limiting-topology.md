# 0005. 限流用自研 Redis 滑动窗口，默认 fail-closed，只对通用写限流开一个 allow 例外

- **Status**: Accepted
- **Date**: 2026-08-29
- **Deciders**: 后端负责人
- **规格引用**: §3.1、§7.5、§8.2、§14.2
- **影响**：T-103（OTP 三维限流）、T-107（username 设定）、T-111（系统限额落地）、T-301（好友请求）

## Context

§7.5 给了两张表，落地方式完全不同：

| | 超限响应 | 存储 | 例子 |
|---|---|---|---|
| **系统限额** | `422 limit_exceeded` | 数据库计数 / 配置常量 | 每用户 500 张卡、note 2000 字符 |
| **速率限制** | `429 rate_limited` | Redis 滑动窗口 | OTP 1/min、username lookup 30/min |

ADR-11 已经定了不建 `plan` / `quota` 表；§3.1 的修订同时要求「必须在配置中硬编码
系统限额常量，并在服务端强制校验」。前者是配置 + 一个 Domain 层的强制器，没有争议。
本 ADR 处理的是后者：**限流放在哪、怎么降级**。

T-006 的任务书写的是「Symfony RateLimiter + Redis 滑动窗口」。落地时发现那条路径与
§7.5 的一条硬要求直接冲突，于是有了本 ADR。

## Decision

### 1. 自研 Lua 滑动窗口，不引入 `symfony/rate-limiter`

`Shared\Infrastructure\RateLimit\RedisSlidingWindowRateLimiter`：ZSET 时间戳日志 +
一条 Lua 脚本，经 `RedisConnectionFactory`（全仓库唯一的 Redis 连接构造点）执行。

否决 Symfony RateLimiter 的三条理由，按分量排序：

1. **它默认静默 fail-open，而这是不可配置的行为。**
   `CacheStorage` 建在 `symfony/cache` 的 RedisAdapter 上，而那个适配器捕获所有
   后端异常、记一行 `Failed to fetch key`、返回 cache miss。于是 Redis 一挂，
   限流器认为每个人都是第一次来 —— §7.5 的 OTP 轰炸防线与 §3.8 的 username
   枚举防线在故障期间凭空消失，且**没有任何信号**。见下面第 2 节，这正是我们
   必须反过来做的那件事。
2. **它需要第二个 Redis 连接构造点。** `framework.cache` 起的 Redis pool 与
   `RedisConnectionFactory` 是两套超时策略、两套客户端选型。T-004 把「只有一处
   构造 Redis 连接」写进了那个工厂的类注释，并为此拒绝了 symfony/cache 的
   RedisAdapter（PSR-6 表达不了原子的 `SET NX EX`）。这里是同一条理由的第二次应用。
3. **`sliding_window` 的 `fetch → 计算 → save` 不是原子的。** Symfony 文档建议配
   `lock_factory` 兜住，代价是引入 `symfony/lock` 并给每次检查加两次 Redis 往返。

自研的形态反而更简单：一条 `EVAL`，Redis 保证原子执行，不需要锁；Predis 的异常
直接冒泡（工厂已设 `exceptions => true`），**fail-closed 是默认而不是补丁**。

算法用**精确的时间戳日志**而不是 Symfony 那种两桶近似：§7.5 的上限都很小
（最大 300），ZSET 的内存可以忽略，而精确日志能算出精确的 `Retry-After`
（= 被违反窗口里最老一条的时间戳 + 窗口长度 − 现在），近似算法只能给出整个窗口长度。

一条策略的**全部窗口共用同一个键**：条目保留到最长窗口，每个窗口按 score 区间
各自计数。于是 §7.5 的 OTP 三重限速（1/min + 5/h + 10/day）与单重限速的存储成本相同。

代价（必须写下来）：

- 我们自己维护一段 Lua。缓解：脚本约 40 行，且
  `tests/Integration/Shared/RateLimit/RedisSlidingWindowRateLimiterTest` 用真实 Redis
  逐条验证窗口边界、多窗口、`Retry-After` 精确值、TTL、NOSCRIPT 回落。
- `config/packages/rate_limiter.yaml` 用的是我们自己的 schema，不是框架原生的。
  缓解：`RateLimitPolicyCoverageTest` 拿它与 §7.5 的表逐行对照。

### 2. 限流默认 fail-CLOSED —— 与 T-004 的幂等**方向相反**

ADR-0003 §4 已经写下这一条，本 ADR 是它的落地细节。重申理由，因为这个不对称
在代码里看起来像 bug：

- 幂等 fail-open 的论证建立在「代价有界」上 —— §5.4.3 已经给创建类端点提供了
  更强、且与存储无关的幂等保证，Redis 只是第二道保险。
- 限流**没有**第二道保险。fail-open 等于在故障期间关掉 §7.5 的 OTP 轰炸防线与
  §3.8 的 username 枚举防线。那是**安全控制**，不是便利功能。
- 而且攻击者能主动制造这个窗口（打爆 Redis 内存），于是「故障期间的短暂放松」
  会变成一个可按需触发的旁路。

fail-closed 时渲染成 **`503 service_unavailable`**（带 `Retry-After`），不是 429：
429 的语义是「我判定你超限了」，而真实情况是「我**无法判定**」。客户端对两者的
反应也该不同（§5.4.3 的 outbox 重试策略）。

**默认值是 `deny`。** `config/packages/rate_limiter.yaml` 里省略 `on_store_failure`
就是最严格的那个选择 —— 新增策略时要主动声明放松，而不是主动记得收紧。

### 3. 唯一的例外：`write_endpoints` 标 `on_store_failure: allow`

§7.5 的「全部写接口 | user | 300/min」是纯**防 DoS**，不是安全控制：它挡的是
「一个客户端把写接口打爆」，不是「攻击者枚举 username / 轰炸 OTP」。

对它 fail-closed 的话，§14.2 的 Redis（单容器、无 HA）一次重启就等于全站写入 503 ——
**正是 ADR-0003 为幂等否决掉的那个后果**（§9.2 的 99.5% 可用性在 30 天里只有
3.6 小时预算）。

`RateLimitPolicyCoverageTest::testOnlyTheGenericWriteLimiterFailsOpen` 断言全仓库
只有这一条 `allow`。fail-open 是一个诱人的「让测试变绿」的旋钮，而多标一条的症状
只在 Redis 故障期间出现 —— 那时没有人在看测试。

### 4. 多维度：全过才扣，`Retry-After` 取更长的

§7.5 明文要求后半句（`GET /v1/users/lookup` 的旁注）。前半句是落地时补的，
理由是一个真实的攻击面：

逐个 `consume()` 的话，攻击者打爆某个共享出口 IP 的配额之后，每一个走那个 IP 的
正常用户在被 IP 维度拒绝之前，**自己的 email 维度配额已经被扣掉了** ——
于是攻击者能远程烧掉任意受害者的 OTP 额度（§7.5 的 1/min 变成「这一分钟你也别想登录」）。

所以 `consumeAll()` 对多维度走**两阶段**：先全查，全过才全扣。代价是多一轮往返；
两阶段之间的竞态是良性的（最坏多放行一次），而 §9.3 的峰值约 15 req/s。

被拒时**不点名是哪个维度**：说出「是 IP 维度拒的」等于告诉攻击者该换 IP 还是换邮箱。
单维度时点名是安全的（只有一条，没有可泄露的信息）。

### 5. §7.5 的两条「总计」不进 Redis

`POST /auth/otp/verify` 的 challenge_id 5 次总计、`POST /v1/me/username` 的 user
10 次总计，**不是滑动窗口而是生命周期计数**，归持久层：前者是 `otp_challenges.attempts`
列（T-104），后者挂在 users 行上（T-107）。

除了语义之外还有一条硬约束：**§8.2 的 ROPA 规定「限流计数保留 24 小时」**。
把一个需要跨越账号生命周期的计数放进 Redis 会直接违反那条保留期。
`RateLimitPolicy::ttlSeconds()` 因此把所有键的 TTL 封顶在 86400 秒。

### 6. `LimitEnforcer` 在 `Shared\Domain\Limit`，不在任务书写的 `Shared\Infrastructure\RateLimit`

deptrac 里每个模块 `*.Application` 的允许列表不含 `Shared.Infrastructure`，
`*.Domain` 更是只有 `Shared.Domain` 一项。照任务书字面放过去，**T-111 在 Wallet 层
根本无法调用它**，而「这个用户已经有 500 张卡了」正是那一层该做出的判定。

这与 `ErrorCode` 当初的处境完全相同（其类注释已记录同一条论证）。
代价：`Shared.Domain` 的允许列表是空的，所以 `LimitEnforcer` 不能用 `#[Autowire]`，
值由 `config/services.yaml` 显式注入。

同理，限流的**接口**放 `Shared\Application\RateLimit`（各模块 Application 可见），
实现放 `Shared\Infrastructure\RateLimit` —— 与 T-005 的加密门面同一个形状。

## Consequences

### 正面

- Redis 故障期间，安全类限流仍然生效（以拒绝的形式），而通用写接口不受影响。
  两种故障模式各自匹配它保护的东西。
- `Retry-After` 是精确值而不是「一整个窗口」，客户端的退避不会过度保守。
- 限流器不引入任何新的 composer 依赖，也不引入第二个 Redis 连接点。
- §7.5 的九条策略在还没有端点的情况下就被 `RateLimitPolicyCoverageTest` 钉住了；
  T-103 接线时只需要传主体。

### 负面（必须写下来）

- **我们自己维护一段 Lua。** 它没有类型检查、没有 phpstan、只有集成测试兜底。
  改它之前先读 `RedisSlidingWindowRateLimiterTest`。
  已知的两个雷：① Lua 5.1 的数字是 double，所有拼进命令的数字必须走
  `string.format('%d', …)`，否则 13 位毫秒时间戳会变成科学计数法；
  ② `ZRANGEBYSCORE` 的 `WITHSCORES` 必须排在 `LIMIT` 之前。
- **`write_endpoints` 的 fail-open 是一个真实的（虽然有界的）缺口**：Redis 故障期间
  没有通用写限流。可接受，因为那一条本来就不是安全控制；安全类限流仍然 deny。
- **代码里两处降级方向相反**（T-004 fail-open / T-006 fail-closed），看起来像不一致。
  两处的类注释都引用了 ADR-0003 与本 ADR。**请不要顺手「修」成一致。**
- **多维度多一轮 Redis 往返。** §9.3 的负载下无关紧要，但它是一个真实的成本，
  且随维度数线性增长。单维度路径（绝大多数调用点）不受影响。
- **`LimitEnforcer` 的位置与任务书的交付物清单不一致**，会让照清单验收的人困惑。
  类注释里写明了偏差与理由。

## Alternatives considered

**用 Symfony RateLimiter，配一个自定义 `StorageInterface` 兜住 fail-open。**
可行：保留框架的 `SlidingWindow` 策略与 `rate_limiter.yaml` 的原生 schema，
只换掉存储。但仍然要引入 `symfony/lock`（否则 fetch/save 有竞态）与两次额外往返，
而换来的「用框架组件」的好处在我们只用到 `SlidingWindow` 一个策略时几乎为零。
**否决**，但这是最接近的备选 —— 如果将来需要 token bucket 一类的第二种策略，
应该重新评估。

**限流一律 fail-open（与幂等一致）。** 一致性好、可用性好。但它把 §7.5 的整张
安全表变成了「Redis 在线时才生效」，而攻击者可以让 Redis 不在线。**否决。**

**限流一律 fail-closed（含通用写限流）。** 最严格、最容易 review、没有「哪条是
例外」的判断负担。但 §14.2 的单容器 Redis 一次重启 = 全站写入 503，
而 ADR-0003 已经为幂等否决过这个后果。**否决**，改为按策略区分。

**把「总计」类限额也放进 Redis。** 存储统一、代码更少。但它与 §8.2 的 24 小时
保留期直接冲突，而且那两个计数本来就有天然的持久化载体（challenge 行、user 行）。
**否决。**

**用近似的两桶滑动窗口（Symfony 的算法）。** O(1) 内存。但 §7.5 的上限最大只有
300，精确日志的内存开销可以忽略，而近似算法给不出精确的 `Retry-After`。**否决。**
