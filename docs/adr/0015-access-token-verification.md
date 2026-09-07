# 0015. Access token 验签：算法白名单、按 `kid` 选密钥、不做黑名单

- **Status**: Accepted
- **Date**: 2026-09-07
- **Deciders**: 后端负责人
- **规格引用**: §5.3（密钥轮换）、§6.2、§7.1（令牌与会话）、§7.2（T02）、§9.1、§14.4
- **修订**: 无（本 ADR 记录的是 §7.1 已有条款的**落地方式**，不改变任何规格条款）
- **影响**：T-105（本 ADR 随其落地）、T-106（Magic Link 复用同一条鉴权链）、
  T-107 / T-108（第一批真正受鉴权保护的端点）、T-404（key-rotation runbook 要按
  「验两把、签一把」写）、T-405（`token_refresh_total` 等指标的导出）

## Context

T-104 交付了**签发**侧：`Ed25519AccessTokenSigner` 按 §7.1 签出
`alg = EdDSA`、claims 恰好 `sub sid did jti iat exp`、15 分钟有效的 JWT。

**验签侧一直不存在**，仓库里没有任何地方解析 `Authorization: Bearer`。
`SigningKeyProviderInterface` 的类注释与 `docs/tasks/M1.md` 的 T-104 交接清单
都把它写给了 T-108（onboarding 拦截器与 `/v1/me`）。

那个安排在依赖顺序上是反的。T-105 的四个交付物里有四个需要 Bearer：

- `POST /v1/auth/logout`（契约明写「auth 组里**唯一**需要 Bearer 的端点」）
- `GET /v1/me/devices`、`DELETE /v1/me/devices/{id}`、`PUT /v1/me/devices/{id}/push-token`

而 T-108 依赖 T-107，T-107 依赖 T-104 —— 它排在 T-105 **之后**。
换句话说：不在 T-105 里建鉴权器，T-105 就交付不了。

同时，`symfony/security-bundle` **没有安装**，也不打算装（理由与
`AbstractApiController` 不继承 `AbstractController` 相同：一个 token 认证的
JSON API 用不上 firewall / voter / session 那一整套）。所以这不是「配一个 firewall」，
而是要自己决定三件事：怎么验、用哪把密钥验、验过之后还查不查库。

## Decision

### 1. `alg` 是一个必须匹配的常量，不是一个由 header 决定的开关

`Ed25519AccessTokenVerifier` 只认 `alg = "EdDSA"`，且**在碰签名之前**检查。

JWT 历史上最经典的两个洞都出在「照 header 说的办」：

- `alg: none` —— 签名段留空即通过；
- `alg: HS256` + 拿**公钥**当 HMAC 密钥 —— 公钥是公开的，于是任何人都能签出合法 token。

两者的共同前提是验签方把 `alg` 当成一个算法选择器。本实现里它是一个断言。

### 2. 按 `kid` 直接选密钥，绝不遍历

`SigningKeyProviderInterface` 新增 `verificationKeys(): array<kid, 公钥>`，
验签方用 JWS header 的 `kid` **查表**；查不到即 `token_invalid`。

**不**回落到「拿所有密钥挨个试」。回落会让轮换故障静默通过：
`previous` 配错了、`kid` 写错了，token 照样验得过，直到某天两把密钥都不匹配才炸 ——
而那时已经没人记得当初改过什么。

### 3. 「验两把、签一把」写进类型系统

§5.3 规定 JWT 签名密钥 6 个月轮换、**双密钥重叠期 24h**。重叠期里
「签名用哪把」与「验签接受哪些把」是两个不同的问题：

| 方法 | 回答 | 返回 |
|---|---|---|
| `currentKey()` | 签名用哪把 | **一把** `SigningKey`（含私钥 seed） |
| `verificationKeys()` | 验签接受哪些 | 一组**公钥**，按 `kid` 索引 |

两个方法**并列**，不合并。合并成「返回一组密钥、让签名方取第一个」会让轮换当天
签出一半旧一半新的 token，且没有任何症状 —— 直到重叠期结束、旧 kid 被移出集合，
那批 token 才开始被拒，症状出现在轮换之后一整天。

返回类型也各自堵死了误用：`verificationKeys()` 拿不到私钥，签不了名。

上一代密钥读自新的 KV 路径 `secret/data/ncards/jwt/previous`（policy 里显式加了一行）。
**它在稳态下不存在**，读不到不是错误 —— 只在轮换后的 24 小时里由 T-404 的 runbook
建与删。

### 4. 服务端**不做** access token 黑名单

这是 §7.1 已有的条款，本 ADR 只记录它的后果与落点：鉴权监听器**只验令牌，不查库**。

于是「会话已撤销」与「令牌仍然有效」在最长 15 分钟内并存。
需要「此刻是否有效」的端点自己查表（刷新路径查 `sessions`，设备管理查 `devices`）。

### 5. 免鉴权白名单按**路由名**，不按路径前缀

`AuthenticationListener::PUBLIC_ROUTES` 逐条列出路由名，与契约里带 `security: []`
的操作由 `tests/Api/AuthenticationCoverageTest` 对账。

写成路径前缀 `/v1/auth/` 会把 `POST /v1/auth/logout` 一起放过去 ——
那个端点是 auth 组里唯一需要 Bearer 的。后果是任何人都能撤销任何会话，
**而所有测试照常绿**（logout 本来就返回 204）。

### 6. 所有 401 共用一句文案

「没带 header」「不是 Bearer」「签名不过」「`kid` 不认识」「claim 集不对」
返回逐字相同的 problem body。常量放在 `AccessTokenVerifierInterface::REJECTED` 上，
监听器与验签器共用同一个 —— 各写一份的话它们迟早会分叉。

**唯一的例外是 `token_expired`**：客户端对它的处置不同（去刷新，而不是清会话跳登录）。
而且只有在签名已验过之后才能给出这个码 —— 一枚**伪造的**过期 token 必须是
`token_invalid`，否则攻击者能用一枚随手编的 token 把客户端推上刷新路径。

## Consequences

### 正面

- T-105 的四个端点得以交付，且 T-107 / T-108 接手时鉴权链已经在跑。
- 两个「待接入」的解析器（`AnonymousRateLimitSubjectResolver` /
  `AnonymousIdempotencyScopeResolver`）同时兑现：§7.5 的「全部写接口 user 300/min」
  从此真的按 user 生效，幂等键也按 user 隔离（此前是全局按 IP —— 两个用户
  生成同一个键时后者会拿到前者的响应，那是一次跨用户的数据泄露）。
- 密钥轮换在验签侧一次做对，T-404 的 runbook 不需要为「旧 token 全线失效」
  设计任何补偿动作。

### 负面（必须知道）

- **⚠️ 撤销之后仍有最长 15 分钟的窗口。** 用户在设备管理页上点了「远程登出」，
  那台设备的 refresh **立刻**失效，但它手里那枚 access token 仍然能读接口，
  直到自然过期。§7.1 明确接受这个窗口，代价是被盗设备在最坏情况下还能看 15 分钟卡片。
  缩小它只能靠调小 `ncards.jwt.access_ttl_seconds`，而那会按比例增加刷新频率
  与 Vault 往返。**不要**用「加个黑名单」来消除它 —— 那会给每个带 Bearer 的请求
  加一趟 DB 往返，而 §9.1 的性能预算里没有这一笔。
  `SessionLifecycleTest::testTheAccessTokenKeepsWorkingAfterLogoutWithinItsWindow()`
  是这条负面后果的**断言**，不是一个待修的 bug。
- **⚠️ `secret/data/ncards/jwt/previous` 的读权限包含私钥。** KV v2 的 ACL 粒度是
  整条 secret，而 `bootstrap.sh` 把公私钥写在同一条上；验签只需要 `public_key`，
  但拆不出来。缓解靠**存在时间**：那条 KV 只在轮换后 24 小时里存在。
  详见 `infra/vault/policies/ncards-app.hcl` 里那段注释。
- **手写验签器要自己维护。** 与签名侧同一个取舍（见 `Ed25519AccessTokenSigner`
  的类注释）：不装 JWT 库的前提是**不长出第二种算法**。哪天要支持多算法或接受
  外部签发的 token，那是该装库的信号，不是该往这个类里加 `match` 的信号。
- **`verificationKeys()` 每 5 分钟一次 Vault KV 读**（进程内缓存 TTL）。
  与签名侧共用同一个 TTL 但各自独立的缓存槽 —— 两者读取频率差几个数量级，
  共用一个槽会让其中一个的过期时刻由另一个的调用节奏决定。
- **免鉴权白名单是一张手工维护的表。** 对账测试挡住了「与契约漂了」，
  但挡不住「契约与白名单被一起改错」。所以另有一条针对 `auth_logout` 的显式断言。
