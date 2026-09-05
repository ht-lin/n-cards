# 0012. 邮件通道：Q3 悬置、Doctrine 队列、消息体加密、发信收敛在两个新 deptrac 图层之后

- **Status**: Accepted
- **Date**: 2026-09-05
- **Deciders**: 后端负责人
- **规格引用**: §3.1、§3.2、§7.1、§7.5、§8.2、§8.3、§14.4、§17.5（Q3）
- **影响**：T-102（本 ADR 随其落地）、T-103 / T-104 / T-105 / T-106（全部发信调用方）、T-306（FCM，会照 `email` transport 的形状再加一条）、T-405（指标导出端点）、T-406（`esp-failover.md` 会吸收本 ADR 的 runbook）

## Context

T-102 要交付「邮件通道与双语模板」。落地时有四个决定必须做，且互相牵扯。

**§3.2 是有意识的风险接受，不是遗漏。** 一期单通道、无双活、无送达率监控体系，
理由是「一期用户规模小，单点故障的期望损失低于现在就投入的工程与运维成本」。
但规格书紧接着给了一个**前提条件**：

> 请把发信实现收敛在 `Notification` 模块的 `MailSenderInterface` 之后
> （Symfony Mailer DSN 一行配置即可切换），**不要把邮箱服务商的细节泄漏到业务代码里**
> ——这是保证「未来 2 人日能补回来」的唯一前提。

于是本 ADR 的四个决定里有三个是在回答同一个问题：**那个「唯一前提」靠什么强制？**

四个决定各自的直接背景：

1. **Q3（邮件服务商选型）在 §17.5 里仍是开放问题**，截止日写的是 M0 结束，已经过了。
   而 T-103（OTP 请求）依赖 T-102，M1 的整条认证链在等它。
2. **`config/packages/messenger.yaml` 把 async transport 选型明确留给了「第一个真实
   异步消费者」**，T-102 就是那个任务。T-004 刻意没替它做这个决定。
3. **队列里那条消息含收件人邮箱与 6 位 OTP 码。** §3.8 要求 `users` 不存明文邮箱列，
   §7.1 要求 OTP 只存 `HMAC-SHA256(code, pepper)`。这两条在队列这一层没有天然的落点。
4. **`Framework.Core` 图层的 collector 是 `^(Symfony|Psr|Twig)\\`，而它对每个模块的
   Application 层开放。** 装上 `symfony/mailer` 之后，`MailerInterface` 会直接落进去。

## Decision

### 1. Q3 不在本 PR 内决定；代码保持通道无关

`MAILER_DSN` 走标准 SMTP DSN，`MailSenderInterface` 之后只有 Symfony Mailer，
没有任何服务商 SDK。`MAIL_PROVIDER` 环境变量默认 `unset`。

**两条交付物因此顺延，且必须显式记账**（已写进 `docs/tasks/M1.md` 的 T-102 回填块）：

- 真实的 SPF / DKIM / DMARC 记录 —— `docs/runbooks/email-dns.md` 交付的是模板与推进节奏，
  DKIM selector 留占位，因为它由服务商生成。
- §3.2 的 4 次手工送达验证（Gmail / GMX / Web.de / Outlook）—— 这是**上线必需项**（§15.1），
  只是不能在服务商未定时做。

选择「悬置」而不是「替它选一个」的理由：Q3 的三条硬要求（EU/EEA 处理 + 可签 AVV/DPA +
支持自定义域 SPF/DKIM）里，前两条是**商务与合规动作**，不是技术选型 —— 需要有人去签 AVV。
后端替它挑一个填进配置，只会制造「看起来定了」的假象。

`MAIL_PROVIDER=unset` 是这个决定的可见性设计：`email_send_total{provider="unset"}`
在 T-405 的看板上就是「Q3 还没定」的直接证据，比一个猜出来的默认值诚实。

### 2. async transport 用 Doctrine，表由迁移建

`MESSENGER_TRANSPORT_DSN=doctrine://default?auto_setup=0`，
`messenger_messages` 由 `Version20260905183000` 建，DDL 逐字照抄 upstream。

输给它的是 Redis transport（零迁移、复用现成的 `RedisConnectionFactory`）。
它输在**持久性**：丢一封 OTP 信 = 该用户登不进去，而 §14.2 的 Redis 是单容器、
无 HA，`docker-compose.base.yml` 里也没开 AOF 之外的任何保障；§8.2 给 Redis 的定位
更是「限流计数，保留 24 小时」——把一条需要跨越重启的队列塞进去是新用途。
Postgres 那边本来就有 WAL 与备份（T-406）。

代价与配套：

- 多一张表 + 一个迁移。
- **`doctrine.dbal.schema_filter: '~^(?!messenger_messages)~'` 是必需的**，
  且**结尾不能带 `$`**：`id BIGSERIAL` 会连带建一个 `messenger_messages_id_seq` 序列，
  带 `$` 的话表被滤掉、序列没有，`schema:update --dump-sql` 会给出一句孤零零的
  `DROP SEQUENCE … CASCADE`——真跑下去会 CASCADE 掉主键默认值，队列从此写不进去。
  （实测踩过；`composer migration:check` 第三步会红。）
- **`use_notify: false` 必须与 `auto_setup=0` 成对出现**，而且它是 **transport 选项、
  不是 DSN 参数**（写进 DSN 会被 `Connection::buildConfiguration()` 当未知选项抛异常）。
  PG 的 NOTIFY 一侧靠一个触发器，而那个触发器只由 auto_setup 创建。留着 use_notify
  的后果不是报错，是**静默的 60 秒延迟**：`PostgreSqlConnection::get()` 在队列空过一次
  之后改走 LISTEN，`pgsqlGetNotify()` 永远返回 false，真正的 SELECT 要等
  `check_delayed_interval`（默认 60000 ms）才跑一次。对一封有效期 10 分钟的 OTP 信
  来说那是灾难性的，而且看起来完全像是「邮件服务商慢」。

### 3. 消息体整体走 Vault Transit（装饰原生序列化器，不自己编解码）

`Notification\Infrastructure\Messenger\EncryptedMailSerializer` 装饰 Messenger 自带的
`PhpSerializer`，只对 `body` 做一次 `encrypt` / `decrypt`（`CryptoKey::Pii`）。
落库的是 `vault:v1:…`。

**为什么必须加密**：不加密的话 `messenger_messages.body` 就是一列明文邮箱 + 明文 OTP 码。
比「多存了一份」更糟的是 T-406 的备份会把整个库 age 加密后推去 Hetzner Storage Box ——
于是这两样东西离开主机，而 §8.2 给它们的保留期分别是「账号存续期」与「10 分钟」。

**为什么是装饰而不是手写 JSON 编解码**（这是落地时被推翻的初始方案，记在这里免得
下一个人再走一遍）：

- Messenger 的 **stamp 全部序列化在 `body` 里**（见 `PhpSerializer::encode()`：
  `serialize($envelope)`）。自己编解码就必须自己搬运 `RedeliveryStamp` ——
  而它正是重投计数的载体。漏掉它的症状是 `retry_strategy: {max_retries: 3}`
  **永远达不到 3**：每次重投都从 0 开始，一封发不出去的信无限重投，
  且看起来完全像是 transport 在正常工作。`ErrorDetailsStamp` /
  `SentToFailureTransportStamp` 同理，少了它们 `messenger:failed:show` 什么也看不见。
- 手写 JSON 的初衷是躲开 `unserialize()` 的反序列化 gadget 面，而**那个面本来就被
  Transit 关掉了**：`aes256-gcm96` 是 AEAD，密文被改一个字节就解不开。能产出一段
  「解得开」的密文的人必须持有 `transit/encrypt/ncards-pii` 的能力，也就是应用自己。
  仅有数据库写权限的攻击者——正是本序列化器要防的那个人——构造不出任何会被
  `unserialize()` 看到的字节。所以这里的 `unserialize()` 比裸用 PhpSerializer 更安全。

配套：两个 catch 的**顺序**是语义的一部分。`CryptoUnavailable extends CryptoFailed`，
而 PHP 的 catch 首个匹配者胜：`CryptoUnavailable`（Vault 封着，**暂时**）必须冒泡去重投，
`CryptoFailed`（密文坏了，**不可恢复**）才包成 `MessageDecodingFailedException` 进死信。
搞反的后果是一次 unseal 窗口期（ADR-0004 的人工 Shamir 3-of-5，按分钟算）
会把那期间队列里的每一封 OTP 信都变成死信，且 unseal 完成后不会自己回来。

代价：worker 消费一条消息 = 一次 `transit/decrypt`，Vault 不可达时消费不了。
但那本来就是全站状态（§14.4 把「Vault sealed / 不可达」列为 **P0**，读卡也要它），
且消息**留在队列里**而不是丢失。

### 4. 新开 `Framework.Mail` 与 `Framework.Templating` 两个 deptrac 图层，只给 Notification.Infrastructure

这是本 ADR 的核心 —— §3.2 那个「唯一前提」的强制点。

- `Framework.Mail`：`^Symfony\\(Component\\(Mailer|Mime)|Bridge\\Twig\\Mime)\\`
- `Framework.Templating`：`^Twig\\`、`^Symfony\\Bundle\\TwigBundle\\`、
  `^Symfony\\Bridge\\Twig\\`（排掉已被 Mail 收走的 `\Mime\`）
- 两层在 `Framework.Core` 的 `must_not` 里各有对应的排除条目 ——
  **包括单独一条 `^Twig\\`**，因为 `Framework.Core` 的 collector 从 T-002 起就写着
  `^(Symfony|Psr|Twig)\\`（那时还没装 Twig，是为将来留的）。
- 两层**只**加进 `Notification.Infrastructure` 的允许列表。

于是「只有 Notification 模块能发信」不是一条约定，是 deptrac 强制的事实。
`tools/deptrac-selftest.sh` 的场景 ④ 守着它：`Identity.Application` import
`MailerInterface` 必须 violation —— 因为 `deptrac analyse` 全绿只能证明
「当前没人这么写」，证明不了「这么写会被拦下」。

**由此产生的一处结构调整**：`MailTemplate` 放在 `Application\Dto\` 而不是 `Domain\`。
`_Ports` 策略只给调用方开放 `<M>.Port` + `<M>.Dto`，而调用方**必须**能命名这个 enum
（「发哪封信」正是它要做的选择）。放在 Domain 的话 Port 入参只能退化成 string，
把 §3.1「外发邮件只有这四封」这条约束从类型系统里删掉。
`MailCriticality`（熔断分级）留在 Domain，分类的 match 挪进了 `MailCircuitBreaker` ——
「这封信在全局熔断时的去留」是熔断策略，不是模板的固有属性，调用方也不该知道。

## Consequences

**正面**

- 换服务商 = 改 `MAILER_DSN` + `MAIL_PROVIDER` 两个环境变量，重启 worker。
  §3.2 承诺的「2 人日能补回来」有了机械保证，不靠自觉。
- §3.8 / §7.1 的明文约束在队列这一层也成立，且有一条断言真库内容的集成测试
  （`QueuedMailPipelineTest::testQueuedBodyIsEncryptedAtRest`）。
- T-306（FCM）接手时照 `email` transport 的形状再加一条即可，
  异步的形状与 worker 槽位都已经就位。

**负面 / 需要记账**

- Q3 仍未决，两条交付物顺延（见上），其中 4 次手工送达验证是**上线必需项**。
- `messenger_messages` 是本仓库第一张、也是唯一一张不受 ORM 管的表。
  `schema_filter` 的存在意味着它的结构漂移**不会**被 `schema:validate` 发现 ——
  升级 `symfony/doctrine-messenger` 时要人工核对一次 DDL。
- 该表的时间列是 `TIMESTAMP(0) WITHOUT TIME ZONE`，与 §17.1 给业务表定的
  「一律 TIMESTAMPTZ」相反。这是刻意的：读写它的 SQL 全部由 transport 自己拼，
  换成 TIMESTAMPTZ 会让「消息什么时候可投递」随 PHP 与 PG 的时区差整体偏移。
- worker 现在依赖 Vault 可达（消费即解密）。已在 compose 的 `depends_on` 与
  `docs/runbooks/email-dns.md` 里写明。
- `MailLocale` 与 `Identity\Domain\ValueObject\Locale` 是两个取值域相同的 enum。
  合并的唯一办法是把 `Locale` 挪进 `Shared\Domain`，那要改 T-101 的实体与
  `User.orm.xml`，且会把「用户的界面语言」这个 Identity 的领域概念变成全局共享的东西。
  为一个两分支的 enum 不值得 —— 调用方做一行 `match` 即可。

## Alternatives considered

**替 Q3 挑一个（比如直接定 Brevo FR）。** 输在它把商务动作伪装成技术决定：
AVV/DPA 要有人去签，`MAIL_PROVIDER=brevo` 却会让看板显示得像已经定了。

**Redis transport。** 输在持久性：单容器、无 HA，一次重启吃掉在途的登录信；
且与 §8.2 给 Redis 的「限流计数 24 小时」定位冲突。

**手写 JSON 序列化器。** 输在会静默丢掉 `RedeliveryStamp`，让 `max_retries` 永远不生效；
而它想换来的安全收益已经由 Transit 的 AEAD 提供了。见决定 3。

**不加密，靠「消费即删行 + 库本身静态盘加密」兜底。** 输在 T-406 的备份会把明文
带出主机，且 `email_failed` 队列里的消息本来就是**长期**留着等人看的。

**配 `framework.mailer.message_bus` 让 Symfony 自己异步发。** 输在它丢进队列的是
一整封已渲染好的 MIME 消息，含明文收件人与明文 OTP 码 —— 与决定 3 直接冲突。

**把 `Framework.Mail` 加进所有 `*.Infrastructure`（与其余 Framework 层保持一致）。**
输在那样「只有 Notification 能发信」就退回成约定了。一致性在这里不是优点：
其余几层没有一条像 §3.2 那样把整个风险接受押在「不许泄漏」上的规格条文。
