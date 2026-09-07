# 0017. username 的 10 次总计是 `users` 上的一列、返回 422 而不是 429，且只有「探到占用」的请求消耗它

- **Status**: Accepted
- **Date**: 2026-09-07
- **Deciders**: 后端负责人
- **规格引用**: §3.8、§5.2、§6.2、§7.5、§8.2、§13.4-6、§17.1、§17.5（Q9 / Q10）
- **修订**: §17.1 的 `users` DDL（新增一列）、§5.2 的 `users` 表定义、
  §7.5 的速率表（`POST /v1/me/username` 那一行移入限额表并改状态码）、
  §17.5 Q9 的状态
- **影响**：T-107（本 ADR 随其落地）、T-108（onboarding 拦截器的白名单与
  `PATCH /v1/me` 的 409）、T-113（清理僵尸注册行时这一列一并消失）、
  T-151（Android 的错误分支与二次确认对话框）、T-405（新增一个计数器）

## Context

T-107 要把 `POST /v1/me/username` 接上 —— 它是 §5.2 那个「注册中间态」
（`username IS NULL`）唯一的出口，也是全站新用户进入钱包的必经之路。

§7.5 的**速率限制**表里有这么一行：

| 端点 | 维度 | 限制 |
|---|---|---|
| `POST /v1/me/username` | user | **10 次总计**（用于试探占用情况；成功一次后该端点永久 409） |

落地时发现这一行同时踩到三个规格没有回答的问题，而每一个的两个选项都会长成
不同的系统。

**① 它存在哪？** 它印在速率限制表里，看起来该进
`config/packages/rate_limiter.yaml`。但 T-006 落地那天就已经把它排除在外了 ——
那个文件的页脚与 `RateLimitPolicyCoverageTest::NOT_RATE_LIMITED` 都逐字登记了
这条豁免，理由是 §8.2 的 ROPA 规定「限流计数保留 **24 小时**」，
而 `RedisSlidingWindowRateLimiter` 的 TTL 正是照那条钉死在 86400 秒的。
一个必须跨越账号生命周期的计数放不进去。

**② 用尽时返回什么？** 若照速率表的归类返回 429，契约（`components.responses
.TooManyRequests`）**强制**它同时带 `Retry-After` 与 `X-RateLimit-Remaining`。
可是这个计数永远不会恢复。

**③ 哪些请求消耗它？** 规格只写了「10 次总计」。而 username **不可变**，
用尽即意味着这个账号永远完成不了 onboarding —— T-108 的拦截器会把它挡在除
`GET /me`、`POST /me/username`、`POST /auth/logout` 之外的所有端点之外，
**包括注销路径**。也就是说这条限流的误伤后果是「账号报废」，不是「稍后再试」。

## Decision

### 一、计数落在 `users.username_attempts` 上（新增一列，迁移 `Version20260907170000`）

```sql
ALTER TABLE users ADD COLUMN username_attempts SMALLINT NOT NULL DEFAULT 0;
```

`NOT NULL DEFAULT 0` 让这次变更满足 §13.5 的 expand–contract：既有行不需要回填，
不知道这一列的旧代码照常 INSERT 也不会失败，因此**只发一次**。
形状与 `otp_challenges.attempts`（§7.5 的另一条「总计」）完全相同，
包括实体侧到上限即饱和的 `recordUsernameAttempt(int $max)`。

**刻意不加 `CHECK (username_attempts BETWEEN 0 AND 10)`。** §17.1 的口径是
只有值域属于契约一部分的三列（username / locale / status）带 CHECK。
把 10 写进库层意味着改 §7.5 的数字要发两次，而反过来的顺序会让新值被库层拒掉。
上限的真相只在 `%ncards.limits.username_attempts_per_user%` 一处。

### 二、用尽返回 **`422 limit_exceeded`**，不是 429

新增 `SystemLimit::UsernameAttemptsPerUser`，走既有的
`LimitEnforcer::enforceCanAdd()`。

429 会强迫我们编一个 `Retry-After` 秒数，而没有任何秒数是真的 ——
客户端会照着退避、重试、再退避，永远失败。这不是措辞问题：Android 侧（T-151）
对 429 与 422 的处理分别是「自动重试」与「终局错误，展示给用户」，
选错的后果是用户看着一个转圈的按钮而不是一句能理解的话。

而且它本来就更像限额而不是速率：没有窗口长度，也没有恢复。
`SystemLimit` 里 `friend_requests_per_day` / `share_invites_per_day` 两条
已经立了同一个先例（「§7.5 把它们放在限额表而不是速率表里，是有意的：
它们是产品语义」）。§7.5 与 §17.1 已回改，那一行现在在限额表里。

### 三、只有**走到唯一性检查**的请求消耗次数

`AssignUsernameService` 的顺序是：

1. `username` 已非空 → `409 username_immutable` —— **不消耗**
2. 归一化 + 格式 + 保留词 → `422 username_invalid` —— **不消耗**
3. `enforceCanAdd()` → 用尽则 `422 limit_exceeded`
4. `recordUsernameAttempt()` + **落库**
5. `findByUsername()` 命中 → `409 username_taken` —— 消耗（已在第 4 步落库）
6. `assignUsername()` + `saveNewUsername()`（唯一索引是真防线）

依据是 §7.5 给这条限流写的理由本身：「**用于试探占用情况**」。
一个过不了 `^[a-z0-9_]{3,20}$` 的字符串在库里不可能存在，问它「被占了吗」
得不到任何信息 —— 计它一次既没有安全收益，又要付上面那个「账号报废」的代价。

反面的代价是具体的：客户端本地预校验（T-010 / T-151）**必然**存在，而它与
服务端正则一旦漂移（比如 Android 那边漏了长度上界），一个用户在设定页上按
十次「确认」就再也进不了钱包，且没有任何自助出路。把 422 排除在计数之外，
这条故障链就断了。

### 四、两次 flush，不合并

第 4 步的落库是**独立**的一次 flush，在第 5/6 步之前。
Doctrine 在 flush 失败时会关闭 EntityManager —— 两者挤在同一次 flush 里的话，
撞上 `uq_users_username` 的那一路会把计数一起丢掉，
于是这条限流恰好对**唯一真正在试探的人**失效，而所有测试照常绿。
与 T-104 把 `otp_challenges.attempts` 放在事务外保存是同一条论证。

### 五、Q9 的黑名单是 12 个词，**精确匹配**，落在独立的配置文件里

`config/packages/ncards_username.yaml`：§3.8 的九个通用词
（`admin` `support` `ncards` `help` `root` `system` `info` `kontakt`
`datenschutz`）+ 任务卡 T-107 为德语场景补的三个（`impressum` `hilfe` `konto`）。

不做前缀 / 子串 / 变体匹配：前缀会误伤 `systematic_anna`、`not_admin`、
`helpful` 这类正常伪名，而变体是一场没有终点的军备竞赛 ——
它保护的东西本来就有限，因为 username 是伪名、产品里没有任何把 `admin`
渲染得更可信的「官方账号」UI。

`UsernameRulesTest` 断言**每个保留词自己必须是一个合法的 username**：
不合法的保留词是死代码（值对象先做字符集校验，一个像 `n-cards` 的词永远
匹配不到任何输入），而这正是 Q9 定稿时最容易踩的坑 ——
产品很自然会写出人读形态 `n-cards`（ADR-0002）。

## Consequences

**接受的**：10 次用尽 = 该账号永久无法完成 onboarding，且**无法通过 API 自助注销**
（T-108 的拦截器不放行 `POST /v1/me/deletion`）。出路是 §17.5 Q10 已定的口径 ——
一期一律拒绝人工改名、引导注销重注册 —— 但那需要人工介入，FAQ 要写清楚。
决定三把触发这件事所需的条件收紧到了「连续十次撞上**真实存在**的名字」，
在一个 3–20 字符的自选空间里，那是刻意行为而不是意外。

**新增可观测性**：`username_set_total{result=set|taken|invalid|immutable|exhausted}`。
`invalid` 那一维顺带给 Q9 提供实测数据（保留词到底被撞了多少次）。

**未做**：`audit_log` 里不记 username 设定。那张表归 T-401（M4），
与 T-104 对 `login_success`、T-105 对 `reuse_detected` 的处理是同一个决定。

## Alternatives considered

- **把 10 次做成 Redis 滑动窗口（窗口取一年）。** 输在 §8.2：ROPA 登记的
  「限流计数保留 24 小时」是对外承诺的处理活动记录，为了一个计数改它不划算；
  而且 Redis 在 §14.2 是单容器无 HA，一次重启就把所有人的配额清零。
- **429 + 一个很大的 `Retry-After`。** 输在它是假的。客户端会照着退避重试，
  而那个请求永远不会成功 —— 用一个协议字段撒谎去迁就一张表格的排版。
- **每个到达服务的请求都计数（含 422）。** 防线更硬，但硬的那部分挡的是
  「用非法字符串试探占用情况」，而那件事本来就得不到信息。代价见决定三。
- **不设上限，只靠 `write_endpoints` 的 300/min。** 输在 §7.5 明确要求了这一条，
  而且 300/min 对枚举来说太宽 —— username 空间小到值得跑字典。
- **保留词做前缀匹配。** 输在误伤：`systematic_anna` 是一个完全正当的伪名，
  而被拒的用户看不到任何能理解的理由（错误文案不能回显是哪个词，§3.8-C4）。
