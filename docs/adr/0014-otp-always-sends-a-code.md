# 0014. OTP 请求对**任意**邮箱都真发码，取消 decoy challenge

- **Status**: Accepted
- **Date**: 2026-09-06
- **Deciders**: 后端负责人
- **规格引用**: §3.1、§3.8、§5.2、§6.3.1（注1）、§7.1、§7.2（T06 / T11）、§7.5、§17.1
- **修订**: §3.8 的「decoy challenge」缓解手段、§6.3.1 的**注1**、§17.1 的 `otp_challenges` DDL
- **影响**：T-103（改实现）、T-104（本 ADR 随其落地）、T-106（Magic Link 同一条流）、
  T-113（`is_decoy` 列的收尾）、T-151（Android onboarding 的前提）

## Context

T-103 已交付的 `POST /v1/auth/otp/request` 有两条路径（`RequestOtpService`）：

- 邮箱在 `users` 里 → 建挑战、**发信**；
- 邮箱不在 → 建一条 `is_decoy = true` 的哑挑战、**不发信**。

两条路径的响应体、状态码与耗时被仔细配平过，客户端分辨不出差别。
§6.3.1 的**注1**给出的理由是：「若攻击者据『是否收到邮件』判断，那是他自己的邮箱，
无信息泄露。」那句话对**枚举**这件事是成立的。

问题出在别处。规格同时要求（三处，措辞一致）：

- §5.2（第 700 行）：「`POST /auth/otp/verify` 成功即创建 `users` 行（首次验证即注册）」
- §6.3.1 时序图：「成功 → upsert user（首次即注册）」
- `docs/api/openapi.yaml` 的 `verifyOtp`：「首次验证成功即注册（创建 `users` 行）」

而契约里**没有 signup 端点**，Android 的 onboarding（T-151）也是走这条流：
邮箱 → OTP → username。于是把两件事放在一起：

> 未注册的邮箱收不到码 → 永远无法走到 verify → **永远无法注册**。
> 而这是全仓库唯一一条注册路径。

也就是说，T-103 交付的那个设计让系统**没有任何用户能注册**。
它没有在任何测试里显形，因为每一条既有用例要么只测 request（那里没有注册的概念），
要么自己往库里塞一个 user 再断言。M1 的出口条件「真机上完成登录 → 设定 username →
加卡」在真机上第一步就走不通。

还有一个更小但同向的问题：`otp_challenges` 既无 `email_encrypted` 也无 `locale`。
即使把码发出去，verify 时也拿不到建 `users` 行所需的邮箱密文与语言 ——
明文邮箱只在 request 的请求体里活过一次，§3.8 规定它不落任何明文列。

## Decision

**`POST /v1/auth/otp/request` 对任意邮箱都生成并发送一个真实的验证码。**

具体地：

1. `RequestOtpService` 里**不再有分支**，也不再注入 `UserRepositoryInterface` ——
   这个端点在结构上就不知道邮箱注册过没有。
2. 收件人恒为**当场加密**的密文：`$crypto->encrypt(CryptoKey::Pii, $payload->email)`。
   它同时被存进挑战，作为首次验证成功时建 `users` 行的输入。
3. `otp_challenges` 增两列：`email_encrypted TEXT NULL`、`locale TEXT NULL`
   （迁移 `Version20260906120000`）。
4. `is_decoy` 列与 `OtpChallenge::decoy()` **保留但不再有生产写入方**：
   部署窗口内库里还有上个版本建的哑挑战，`VerifyOtpService` 必须继续对它们返回 401。
   删列走 §13.5 的 expand–contract，归 T-113。

## Consequences

**变容易的**

- **注册路径成立了。** M1 的出口条件与 T-151 的 onboarding 有了前提。
- **防枚举变强，且不再需要维护。** 原来的保证是「两条路径的做功被配平过」——
  一个必须人肉维护、配错了**没有任何症状**的账（T-103 的类注释里那行
  「返回值被丢弃的 `encrypt()`」就是为此存在的）。现在的保证是「只有一条路径，
  服务端根本没查」。`RequestOtpServiceTest` 里对应的断言从「数两边的往返次数」
  变成了一条反射断言：构造签名里不得出现任何能查用户的东西。
- **少了一次 DB 往返**（`findByEmailHash` 没了），也少了一处将来会被「优化」错的地方。

**变难的 / 新增的风险**

- **⚠️ 攻击面从 request 搬到了 verify。** 攻击者现在能对任意邮箱拿到一个真实的
  `challenge_id`（信进了受害者的收件箱，他看不到），再用错码去 verify。
  「未注册邮箱的 challenge + 错码 → 401」与「已注册邮箱的 + 错码 → 401」
  两者必须不可区分。今天它们做的功天然相同（都只有 `hmac(code)` + `findById` +
  `attempts` 的 UPDATE，都不查 `users`），另有
  `ncards.otp.verify_budget_ms` 的恒定耗时填充兜底。
  钉住它的是 `VerifyOtpServiceTest::testEveryRejectionShapeDoesTheSameAmountOfWork()`
  与 `OtpEnumerationResistanceTest` 的 verify 那一节。
- **⚠️ §7.5 的 email_hash 限流从「重要」变成「唯一」。** 本端点现在可以向任意
  第三方邮箱发信，也就是 §3.1 那条「用户无法让系统向任意第三方发信」**不再成立** ——
  准确的表述变成「**只能向任意邮箱发一封 OTP，且每邮箱每天 10 封封顶**」。
  §7.2 的 T11（OTP 邮件轰炸 → 域名进黑名单）攻击面因此从「对自有邮箱的轰炸」
  回到了「对任意邮箱的轰炸，但被 1/min、5/h、10/day 三个窗口夹住」。
  这个代价是**明知并接受的**：三个窗口是 §7.5 原文就有的，本决定没有放宽任何一个。
- **发信量上升。** 未注册邮箱的请求现在也入队。ADR-0013 的域名邮箱配额
  （未解决项 b，仍未实测）因此多了一个消耗源。§14.4 的 `email_send_total`
  与 `MailCircuitBreaker` 的 200/500 阈值不变，但那两个数的校准要把这部分算进去。
- **`otp_challenges` 的数据分级变了。** 它从「只有哈希」变成「含加密的个人数据」，
  §8.2 的 ROPA 与 §8.4 的数据导出要相应更新（保留期不变 —— 挑战本就短命，
  T-113 的清理任务照删）。
- **`otp_request_total{result}` 的 `decoy` 取值退休**，恒为 `issued`。
  §14.4 的「OTP 转化率骤降」告警查询不受影响（它看的是总量趋势）。

## Alternatives considered

**A. 保留 decoy，另开一个 `POST /auth/signup` 端点。**
输在它把枚举面原样搬了个家：一个「这个邮箱能不能注册」的端点，答案就是
「这个邮箱注册过没有」。要防住它就得让 signup 也恒 202 + 不发信，
于是又回到同一个死结。而且它与 §6.3.1「首次验证即注册」的时序图直接冲突，
要动的规格比本决定多。

**B. 保留 decoy，把注册做成「verify 成功后客户端再调一次 `POST /me`」。**
输在 decoy 的码从来没发出去过，未注册邮箱的 verify **必然**失败 ——
根本走不到那个「之后」。这个方案在纸面上像是能work，实际上是 A 的一个更绕的版本。

**C. 什么都不改，把注册路径当作已知缺陷记账，留给后续任务。**
输在它让 M1 的出口条件无法达成，而 T-104 的验收标准
（「集成测试断言首次验证创建的 user 行 `username IS NULL`」）也无法通过端点验证。
更实际的理由是：真正的修复就是本决定这几行，拖到 T-105/T-106 之后只会让
它们各自基于一个走不通的流程写代码。

**D. 只给未注册邮箱发一封「你还没有账号」的信，不发码。**
输在它是**最坏**的选项：那封信的内容本身就在回答「这个邮箱注册过没有」，
而收件人是攻击者指定的任意地址。§3.8 想防的东西被原封不动地写进了邮件正文。
