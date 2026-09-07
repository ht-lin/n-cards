# 0016. Magic Link：与验证码同一封信、落地页是静态文件、消费不做耗时填充

- **Status**: Accepted
- **Date**: 2026-09-07
- **Deciders**: 后端负责人
- **规格引用**: §1（第 86 行）、§3.1、§6.2、§7.1、§7.5、§14.4、§17.1
- **修订**: §3.1 的「内容只可能是一封验证码信」、§7.1 的「Magic Link 的关键陷阱」四条、
  §7.5 的速率表、§14.4 的 `email_send_total{template}` 取值域
- **影响**：T-102（退休一份模板）、T-103（请求侧签发令牌）、T-104（签发路径被抽出）、
  T-106（本 ADR 随其落地）、T-151（Android 的 App Links 注册与消费调用）、
  T-405（`login_total` 现在聚合两条登录入口）

## Context

§1 把产品定义写成「6 位码 + **Magic Link 兜底**」，而兜底那一半从 T-101 起就只有
脚手架：`otp_challenges.magic_token_hash` 建了列没有写入方，`MailTemplate::MagicLink`
连同六个 Twig 模板在 T-102 就写好了却没有生产调用方，契约里
`POST /v1/auth/magic/consume` 从 T-007 起一字未改地等着。

T-106 要把它接上。接的过程中有四件事规格没有回答，而每一件的两个选项都会长成
完全不同的系统。

**① 这个链接从哪封信里来？** 契约里没有「请求 Magic Link」的端点，
`magic_token_hash` 又与 `code_hash` 在**同一行**上 —— 也就是说令牌必然是
`POST /auth/otp/request` 顺手签发的。那么它是随验证码信一起发，还是另发一封？

**② 落地页由谁提供？** §7.1 只说「邮件中的链接指向一个 App Link / 落地页，
`GET` 只渲染『点击继续登录』按钮」。而 `app.n-cards.de` 这个域在这张卡之前
**在整个仓库里没有任何服务端**：Caddyfile 只有 `api.` 一个站点块，
`/l/devices`（T-104 已发出）与 `/l/security`（T-105 已发出）两个链接在 DNS 层就打不开。

**③ 谁来 POST？** §7.1 说「实际消费走 `POST /v1/auth/magic/consume`」，
但没说发起者是落地页还是 App。

**④ 消费端点要不要恒定耗时填充？** `TimeEqualizerInterface` 的类注释里写着
「将来 T-106 的 magic consume」会复用它。

## Decision

### 一、码与链接在**同一封**信里；`MailTemplate::MagicLink` 退休

`MailTemplate::OtpCode` 的 `requiredVariables()` 增加 `magic_link_url`，
四份 `otp_code` 模板（de/en × html/txt）加上按钮与明文 URL。
`MailTemplate::MagicLink` 与它的六个模板文件删除。§3.1 的外发邮件从四封变成三封。

主题行不变（`{{ code }} ist dein N-Cards-Anmeldecode`）—— 码留在通知栏里可读是
这封信最有价值的部分，加一个按钮不影响它。

### 二、落地页是**静态文件**，由 Caddy 上一个新的 `app.n-cards.de` 站点块提供

新增 `infra/caddy/site/`，由 Caddyfile 第二个站点块 `file_server` 出去。
**没有 `reverse_proxy`。** 后端在 `/l/` 下不注册任何路由，
`tests/Api/RouteInventoryTest::testTheBackendServesNothingUnderTheAppLinksPath()` 钉这一条。

顺带给 `/l/devices` 与 `/l/security` 补上落点 —— 那两个链接已经在真实用户
收到的邮件里了。

### 三、落地页**不调 API**，它把令牌交给 App

按钮是一个 `intent://app.n-cards.de/l/magic/<token>#Intent;scheme=https;
package=de.ncards;S.browser_fallback_url=<Play 商店>;end`。
桌面上退化成一句「在手机上打开，或把信里的 6 位码输进 App」。

### 四、消费端点**不加**恒定耗时填充，也不加 `attempts` 计数

`TimeEqualizerInterface` 里那句预言作废，已在该文件改掉。

## Consequences

### 变容易的

- **兜底那一半成立了**，且没有增加任何一次发信。§7.5 的「每邮箱每天 10 封」
  仍然是 10 条消息 —— 若发两封，那个数字实际会变成 20，而 ADR-0013 的域名邮箱
  配额（未解决项 b）**至今没有实测**，ADR-0014 刚刚才往同一个消耗源里
  加进了「未注册邮箱也发信」。
- **注册路径多了一条入口，而不是多了一个分支。** magic consume 与 otp verify
  在鉴别通过之后走的是同一段代码（新的 `Application/Session/SessionIssuer`）。
  「首次即注册」「设备 id 撞别人 → 409」「刚注册的账号不发新设备提醒信」
  三条规则因此不可能在两条入口上分叉 —— 而它们**每一条**都是 T-104 交付回顾里
  点名过的坑。
- **「GET 不消费」从测试保证变成结构保证。** 这与 ADR-0014 把防枚举从
  「配平两条路径的做功」升级成「服务端在那条路径上根本不查 users」是同一步棋：
  最好的安全性质是那些**没有代码可以违反**的性质。邮件安全网关会把这个 URL
  打烂，让它们打到一个文件上，既打不出 500，也不消耗任何限流配额与 DB 连接。
- **`app.n-cards.de` 这个域第一次真的存在了。** T-104 与 T-105 发出去的两个
  链接不再是死链。

### 变难的 / 新增的风险

- **⚠️ 任务卡的验收标准前半条改写了。** 原文是「集成测试断言 `GET` 与 `HEAD`
  不改变 `consumed_at`」。落地页不在后端，这条 PHPUnit 写不出来。替代是三个
  强制点：`RouteInventoryTest` 的路由缺席断言、`MagicConsumeEndpointTest`
  断言本端点只接 POST、以及 `scripts/ci/smoke-app-site.sh` 在**真栈**上
  curl `GET`/`HEAD`/预取。三者合起来比原文更强（原文只覆盖后端），
  但它们分散在三处，而原文是一条。
- **⚠️ 删掉了一份 T-102 已交付的模板。** `magic_link` 的德英文案写得很好，
  其中那段「为什么这个链接不消费令牌」的注释已经搬进 `otp_code.html.twig`。
  §14.4 的 `email_send_total{template}` 从此没有 `magic_link` 这个取值 ——
  告警查询看的是总量趋势，不受影响。
- **⚠️ 新增一个仓库外的人工前置条件**：`app.n-cards.de` 与
  `app.staging.n-cards.de` 的 A 记录必须先存在，Caddy 才签得出证书。
  在它就位之前，邮件里的链接指向一个打不开的域名 —— 而这个状态**在本卡之前
  就已经存在**（`/l/devices`、`/l/security`），所以不是新增的破损。
  deploy.yml 里那一步带 `if`，域名没就位时跳过而不是让整条流水线红。
- **⚠️ `assetlinks.json` 今天是空的 `[]`。** 仓库里没有 release keystore，
  拿不到 `sha256_cert_fingerprints`。效果是 App Links 验证不通过，
  点信里的链接落到 Web 落地页而不是直接拉起 App —— 功能不坏，少一步。
  填它归 T-151 / M4；Android 侧的 `intent-filter` + `autoVerify` 也在那里。
  **两边缺一边，链接都不会拉起。**
- **⚠️ 消费路径整段跑在一个事务里。** `findByMagicTokenHash()` 带
  `PESSIMISTIC_WRITE`（不加锁的话两个并发 POST 会用**一个令牌换到两个会话**，
  而库里只留下一条看起来完全正常的记录），而 `FOR UPDATE` 要求在事务里。
  于是签 JWT 也落在事务内 —— 与 T-105 的 `RefreshTokenService::rotate()`
  同一个形状，签名密钥是缓存的。
- **`login_total{result}` 现在聚合两条登录入口**（otp verify 与 magic consume）。
  语义仍然自洽（「一次登录尝试的结果」），但从指标上分不出用户走的是哪条。
  T-405 若需要分开，加一个 `method` 标签即可 —— 那会改变既有序列的形状，
  所以留给那张卡决定，本卡不动。
- **`otp_challenges` 一张表上两列哈希用了两种口径**：`code_hash` 走 Vault HMAC
  （6 位码从一份库备份里几秒钟就能全枚举，pepper 是唯一的防线），
  `magic_token_hash` 走本地 SHA-256（32 字节 CSPRNG 没有可枚举的字典，
  pepper 买不到任何东西，而代价是登录关键路径上多一次 Vault 往返）。
  这个不对称是刻意的，三处注释都写了「不要顺手统一」。
- **索引不是部分索引，尽管那样更好。** `WHERE magic_token_hash IS NOT NULL`
  能让大量 NULL 行整体缺席，但 DBAL 读不回索引定义里的 WHERE 子句 ——
  加了它 `composer migration:check` 的第 ③ 步会**永久变红**。实测过。
  这张表是短命数据，代价可以忽略。

## Alternatives considered

**① 发两封信（保留 `MailTemplate::MagicLink`）。**
输在发信量翻倍（见上），以及一秒内到达的两封 Critical 信会让用户先分辨再选 ——
而 §7.2 的 T02 把这类信列为「邮箱被接管」唯一能被用户察觉的信号，
训练用户忽略它是最贵的那种代价。

**② 给 `POST /auth/otp/request` 加一个可选 `delivery` 字段让客户端选。**
发信量同样不变，两份模板都活着。输在它把「兜底」变成了用户**在看到信之前**
就得做的选择 —— 而兜底的全部意义是「另一条路走不通时还有这条」。
另外要动契约、要 T-151 出 UI。

**③ 落地页由 Symfony 渲染（`GET /l/magic/{token}`），`app.n-cards.de` 反代到 `app:8080`。**
好处是任务卡的验收标准能逐字照写成集成测试。输在三点：
`config/packages/twig.yaml` 明写「真出现第二个用途时是一次要单独论证的扩张」；
把 PHP 暴露给会把这个 URL 打烂的邮件安全网关；以及落地页想显示「链接已失效」
就要做一次无鉴权的 DB 查询 —— 那等于给网关开一个「这个令牌有效吗」的探针，
恰好抵消掉整条设计要保护的东西。

**④ 落地页自己 POST 消费（浏览器直接换令牌）。**
在契约层面就不成立：`MagicLinkConsumption.device` 是必填的，`platform` 的取值域
只有 `android`，`id` 是安装级的客户端生成 UUID。浏览器造不出合法请求体，
也没地方放拿回来的 refresh token（§7.1 规定它只存在 EncryptedSharedPreferences 里）。
硬要做就得放宽契约，而放宽之后第一个问题是「一个浏览器会话算哪台设备」。

**⑤ 给消费端点加 `attempts` 计数与耗时填充，与 verify 对称。**
输在两者都没有对象。耗时填充在 verify 上挡的是一条具体信道：ADR-0014 之后
攻击者能对任意邮箱拿到真实的 `challenge_id`，再用随便编的 6 位码问
「这个邮箱注册过吗」。这里没有对应的东西 —— 令牌 2^256 种，编不出来；
唯一能从时序读出的是「我手上这个令牌还有效吗」，而他直接 POST 一次就有答案。
`attempts` 更甚：verify 是拿 `challenge_id` 找行再比码，所以错码也能定位到一行去
`attempts++`；这里是拿令牌摘要**本身**找行，猜错的令牌根本找不到任何行 ——
没有行可以加一。给一条登录关键路径白加 120 ms，换不到任何东西。
