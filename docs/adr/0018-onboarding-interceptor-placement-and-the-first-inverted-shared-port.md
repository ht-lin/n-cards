# 0018. onboarding 拦截器落在 Shared、经一个**反转的** Shared 端口读 Identity，优先级 10 且用户行消失时返回 401

- **Status**: Accepted
- **Date**: 2026-09-08
- **Deciders**: 后端负责人
- **规格引用**: §4.2（模块依赖规则）、§5.2、§6.2、§6.3.1、§7.1、§9.1、§12.2、§13.7
- **修订**: §6.2 的 `PATCH /v1/me` 那一行（「通知偏好」标注为未定义、延后）
- **影响**：T-108（本 ADR 随其落地）、T-109 起的**每一张卡**（新端点默认受管，
  见「Consequences」）、T-113（僵尸行清理与本 ADR 决定四的 401 直接相关）、
  T-150 / T-151（Android 对 403 `username_required` 与 401 的分支）

## Context

§5.2 定义了一个「注册中间态」：`POST /v1/auth/otp/verify` 成功即建 `users` 行，
但那一刻 `username IS NULL`。T-107 交付了它的**出口**（`POST /v1/me/username`），
没有交付**围栏**。规格三处逐字要求同一件事（§5.2、§6.3.1 注 2、契约
`User.onboarding_complete` 的 description）：这类用户除 `GET /v1/me`、
`POST /v1/me/username`、`POST /v1/auth/logout` 外，所有 `/v1` 端点返回
`403 username_required`，且必须由**单一 Kernel 监听器**强制。

「单一监听器」这个约束本身没有争议（逐端点判断漏一个就是权限洞，且漏掉的症状
是那个端点照常工作 —— 没有任何测试会红）。有争议的是它**放在哪**：

判定需要一个 Identity 的事实（`users.username` 是否为空），而现有五个横切监听器
全在 `Shared/Infrastructure/Http/`，deptrac 里
`Shared.Infrastructure: [Shared.Domain, Shared.Application, Framework.*]`
—— **不含任何模块图层**。§4.2 规则 4 的后半句（「Shared 不得依赖任何模块」）
正是靠这份空缺保证的。

## Decision

### 一、监听器放 `Shared\Infrastructure\Http\OnboardingListener`

备选是 `Identity\Infrastructure\Http\OnboardingListener` —— deptrac 天然放行
（`Identity.Infrastructure` 已经能看到 `Framework.Http` 与 `Shared.Infrastructure`），
零新抽象，也不需要本 ADR。

选 Shared 的理由是**这条规则约束的是整个 `/v1` 面**，不是 Identity 的端点。
六个横切监听器留在同一个目录里，`ListenerOrderTest` 才能一眼看到全貌 ——
而那个文件是本仓库唯一能看到「优先级全景」的地方（优先级分散在各个类的
`#[AsEventListener]` 上，随手改一个数字功能测试大概率还是绿的）。
把唯一一个「管所有模块」的监听器藏进某一个模块，下一个加端点的人不会想到
去那里看，而他要理解的恰恰是「我的新端点为什么默认 403」。

### 二、跨边界靠一个**反转的** Shared 端口

新增 `Shared\Application\Onboarding\OnboardingStatusInterface`，
由 `Identity\Infrastructure\Onboarding\UserOnboardingStatus` 实现
（`Identity.Infrastructure` 的允许列表里本来就有 `Shared.Application`，
**零 deptrac 配置改动**，`deptrac analyse` 仍是 0 violation）。

**这是本仓库第一个由模块实现的 Shared 接口，必须如实记下来。** 到 T-107 为止，
每一个 `Shared\Application\*` 接口都由 `Shared\Infrastructure` 实现
（`AccessTokenVerifierInterface`、`CryptoServiceInterface`、
`IdempotencyStoreInterface`…），而既有的跨模块机制是
`<Module>/Application/Port/*` —— 那个方向在这里用不了，因为
`Shared.Infrastructure` 看不见 `Identity.Port`。

⚠️ **不要把它当成「Shared 想要什么就开一个端口」的先例。** 它成立的前提很窄，
两条必须同时满足：

1. 被强制的规则本身是**全 `/v1` 面**的（所以强制点必须在 Shared）；
2. 判定所需的数据属于某一个模块。

不满足第 1 条的东西属于模块自己。这两条写在了接口的类注释里。

### 三、优先级 **10**：晚于限流、早于幂等

现有链是 `RequestId(512) → ClientVersion(40) → Router(32) → Authentication(16)
→ RateLimit(12) → Idempotency(8)`。

- **晚于 `AuthenticationListener`(16)** —— 豁免按路由名匹配要先有 `_route`，
  状态判定要先有 `AuthContext`，两者都由它写下。反过来排的话本类每次读到
  `null` 并直接 return，也就是**全站静默放行**，而所有功能测试照常绿。
- **晚于 `RateLimitListener`(12)** —— 本类是唯一一个每个受管请求都**查一次库**
  的横切监听器。另外两个刻意排在限流前面的检查（`/v1/typo` 的 404、缺
  `X-Client` 的 400）都不碰任何后端。排在限流前面等于给一个持 token 的客户端
  开一条不受 §7.5「user 300/min」约束的 DB 往返 —— 一个 403 重试循环就成了
  对 Postgres 的放大器。403 计入配额也是对的：那是客户端 bug 或滥用。
- **早于 `IdempotencyMiddleware`(8)** —— 一个注定 403 的 `POST` 不该白烧一个
  幂等键，更不该占下 60 秒的在途锁让客户端下次重试撞上
  `409 idempotency_in_progress`。与 T-006 给 RateLimit 写的论证同源。

三条都由 `ListenerOrderTest` 钉死。

### 四、端口返回**三态枚举**，用户行消失时是 **401 而不是 403**

`OnboardingState`（`Shared\Domain\Onboarding`）：`Complete` / `Incomplete` /
`UserUnknown`。第三格不是防御性编程 —— `AuthContext` 的类注释逐字写着
「持有它**不**意味着这个 user 还存在」：token 有最长 15 分钟寿命，而删号流程与
T-113 的僵尸注册行清理都会在那期间把 `users` 那一行删掉。

`bool` 会把这一格并进 `false`，也就是给一个已经不存在的账号返回
`403 username_required` —— 而那会把客户端指向 `POST /me/username`（就在豁免表里），
那条路径上的查找同样落空、抛 500。客户端于是在 403 与 500 之间打转，
**且两个状态码都不会让它清掉本地会话**。

401 + 与 `AccessTokenVerifierInterface::REJECTED` 逐字相同的文案则让 T-150 的
`Authenticator` 去静默刷新；刷新同样失败（`sessions` 随外键一起没了），
于是它清会话跳登录 —— 对一个已删除的账号，那正是想要的终局。
复用同一句文案的另一半理由同 ADR-0015 决策 6：让「用户已删」与「token 是编的」
对探测者不可区分。

### 五、`PATCH /v1/me` 收到 `username` 字段时**不走** `User::assignUsername()`

§6.2 要求返回 `409 username_immutable`（不静默忽略）。T-107 的移交笔记写的是
「调 `User::assignUsername()` 即得 409」。**本卡有意偏离**：那只在调用者已经有
username 时成立。对一个**未完成** onboarding 的调用者，那个方法会真的把值写进去 ——
绕过 `Username::fromInput()` 的归一化、字符集、保留词黑名单，绕过
`findByUsername()` 的查重，也绕过 §7.5 的 10 次计数（ADR-0017）。

也就是说那条路径会开出**第二个 username 写入口**，而它唯一的守卫是
`EXEMPT_ROUTES` 里恰好没有 `me_update` 这件事。把一条产品不变量的正确性挂在
另一个类的白名单上，正是 `UsernameController` 的类注释在拦的形状
（「不可变性是靠没有 PATCH 端点保证的，不是靠某处的一个 if」）。

改为：detail 提成 `User::USERNAME_IMMUTABLE_DETAIL`，
`ProfileUpdatePayload::fromArray()` 第一行按**键是否存在**直接抛同一个码与同一句话。
一处文案、三个调用方（实体、`AssignUsernameService`、payload），且这一路不碰实体。
判定排在未知字段扫描**之前** —— 否则 `username` 会先被报成
`400 unknown_field`，那是「静默忽略」的另一种说法。

### 六、`PATCH /v1/me` 本期只做 `locale`，通知偏好**延后**

任务卡与 §6.2 写的是「`locale`、通知偏好」，但**通知偏好在整个仓库没有任何
数据模型**：§17.1 的 `users` DDL、`User.orm.xml`、契约的 `User` schema、
`Notification` 模块源码里都没有它，规格里唯一的出现是 §12.2 的模块职责表
（「Notification：FCM 发送、邮件模板与发送、通知偏好」）。M1 里也没有任何
用户可以关掉的通知 —— OTP 信与新设备提醒信都是安全类，本来就不该可关。

在没有任何消费者的情况下发明一个偏好模型，等于把一列 JSONB 与一份 schema 钉进
`/v1`，而 §13.6 禁止在 `/v1` 内删除字段。§13.6 同时明确允许**新增可选字段**，
所以延后是安全的方向，反过来不是。契约的 `MeUpdate` 因此是
`required: [locale]`，description 里写明将来会放宽成「至少一个字段」。

## Consequences

### 正面

- **新端点默认受管。** T-109 起加任何 `/v1` 路由，`OnboardingCoverageTest` 会
  自动开始要求它对未完成 onboarding 的用户返回 `403 username_required`，
  不需要任何人来登记。豁免要主动改三处（§5.2、`EXEMPT_ROUTES`、那个测试）。
- 验收测试是**行为**而不是对账，且预期取自规格常量 `SPEC_EXEMPT` 而不是实现的
  `EXEMPT_ROUTES` —— 于是两个漂移方向都会红。落地时用两次 mutation 验证过：
  把白名单改成路径前缀 → 4 条红（`PATCH /me` 与三个设备端点全泄漏）；
  删掉 `me_username_set` → 1 条红，且失败文案直接指出原因。
- `probe_*` 夹具**不需要**测试专用配置口子（不像 `ncards_auth.yaml`）：
  免鉴权路由永远不带 `AuthContext`，结构上就在拦截器之外。同一条规则顺带让
  `POST /v1/auth/token/refresh` 保持可达 —— §5.2 的三条清单里没有它，
  但挡住它的话，卡在 onboarding 的用户会在 15 分钟后连令牌都换不了。

### 负面（必须知道）

- **每个受管请求多一次主键查找。** 在 §9.1 的预算里（最紧的是
  `PATCH /v1/cards/{id}` P95 ≤ 200 ms）可以忽略，而且走
  `DoctrineUserRepository::findById()` 会把行放进 Doctrine identity map，
  同一请求里再需要它的控制器不必查第二次。
  **不要**在那里加缓存：`Complete` 确实是单调的（username 不可变），
  但删号与 T-113 的清理会让它变回 `UserUnknown`，一个永不失效的缓存会让
  已删除账号的 token 在剩余寿命里继续畅通。
- **仓库多了一种依赖方向**（模块实现 Shared 端口）。约束写在接口注释里，
  但那是文字不是机械强制 —— deptrac 拦不住第二个人照着开一个不该开的端口。
- **§6.2 与实现在「通知偏好」这一行上暂时是分叉的**，靠规格里的一句标注与
  契约的 description 兜着。真正闭合它的是 Notification 模块有第一个可关的通知那天。

## Alternatives considered

- **拦截器放 `Identity\Infrastructure\Http\`。** 零新抽象、零 ADR，deptrac 天然放行。
  输在可发现性：六个横切监听器里唯一一个管全站的藏进了某个模块，
  而 `ListenerOrderTest` 要跨目录引用它。如果将来这个端口开始被滥用，
  这是应该回退到的形态。
- **在 JWT 里加一个 `onb` claim，完全不查库。** 输在 access token 有 15 分钟寿命：
  刚设完 username 的人会继续吃最长 15 分钟的 403，除非设定成功后强制轮换令牌 ——
  那等于把 onboarding 的出口挂到令牌轮换上。而且 §7.1 把 claim 集钉成了恰好
  `sub sid did jti iat exp`，加一个要同时改 ADR-0015 与两端。
- **契约里加一个 `x-onboarding-exempt` 扩展，像 `security: []` 那样对账。**
  输在它是第二个真相来源，而行为测试已经能双向发现漂移 ——
  多一处要记得同步的地方，只会多一处忘了同步的地方。
- **给 `PATCH /me` 的空对象 `{}` 返回 200 no-op。** 输在
  `AbstractApiController::decodeBody()` 用 `array_is_list()` 判顶层，
  而 `array_is_list([]) === true` —— `{}` 在解析层就是 `400 malformed_request`。
  为 PATCH 单独放宽它会动到每一个端点，而一个什么都不改的 PATCH 只可能是客户端 bug。
