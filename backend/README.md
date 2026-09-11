# backend

Symfony 7.4 (LTS) / PHP 8.3+ **模块化单体**。骨架与质量门禁由 T-002 交付。

根命名空间是 Symfony 默认的 `App\`（`App\Shared\…`、`App\Module\Identity\…`）。
刻意不用品牌名 —— 品牌名（Q1）在 [ADR-0002](../docs/adr/0002-brand-name-and-domain.md) 里还挂着
`Proposed`，用 `App\` 则将来改名对 `backend/` 的源码零影响。

持久化是 **Doctrine DBAL + ORM**（DBAL 由 T-003 为 `/health/ready` 探活引入，
ORM 与第一个迁移由 T-101 落地）。映射的形态相当特殊，动实体之前先读
[持久化约定](#持久化约定doctrine-orm)。
`doctrine/doctrine-bundle` 钉在 **2.x**：3.x 起要求 PHP ^8.4，而本项目按 §12.2
跑 8.3（`composer.json` 的 `config.platform.php` 与 CI 的 `php-version` 都是 8.3）。

## 常用命令

```bash
composer install

composer qa            # 本地全套门禁：cs + stan + deptrac + selftest + test
composer cs            # php-cs-fixer 干跑（§13.3：0 差异）
composer cs:fix        # 就地修复风格
composer stan          # 预热容器 + PHPStan level 8
composer deptrac       # 模块与分层边界（§4.2）
composer test          # PHPUnit
composer test:coverage # 带覆盖率并校验 §13.3 阈值（需 pcov 或 xdebug）
composer audit         # 依赖漏洞（§13.3：0 高危）
```

底层命令同样可用（与仓库根 README 一致）：

```bash
vendor/bin/php-cs-fixer check --diff
vendor/bin/phpstan analyse
vendor/bin/deptrac analyse
vendor/bin/phpunit
```

> `composer test:coverage` 需要 **pcov** 或 **xdebug** 扩展。开发机上通常没装，
> CI（`.github/workflows/backend.yml`）用 pcov 跑，日常本地开发跑 `composer qa` 即可。
> 想在本地复现覆盖率判定，用容器：dev 镜像里装了 pcov（见下）。

## 在容器里跑（T-003 起）

裸机上 `composer qa` 全绿就够日常用，但需要真实 Postgres 的集成测试会 skip
——后端服务按 §7.4 不映射宿主机端口，裸机连不上。要连真库跑，先在仓库根起栈：

```bash
cp infra/compose/.env.example infra/compose/.env
export COMPOSE_FILE=infra/compose/docker-compose.base.yml
docker compose up -d
```

然后：

```bash
docker compose exec app bin/console --env=test doctrine:database:create --if-not-exists
docker compose exec app vendor/bin/phpunit          # 0 skip
docker compose exec app composer test:coverage      # dev 镜像里有 pcov
docker compose exec app bin/console app:seed
docker compose exec app bin/console app:cleanup      # §8.2 保留期清理，手动跑一趟
```

> `app:cleanup` 日常由 `scheduler` 容器每天 04:30（Europe/Berlin）自动触发；
> 上面那条是运维手动入口，两者走的是同一个 runner。
> `bin/console debug:scheduler` 看下一次什么时候跑。
> 运维手册：[`docs/runbooks/scheduled-cleanup.md`](../docs/runbooks/scheduled-cleanup.md)。

镜像定义在 [`Dockerfile`](Dockerfile)（`dev` / `prod` 两个 target），
栈定义在 [`../infra/compose/`](../infra/compose/README.md)。

> 容器里 compose 注入的 `APP_ENV=dev` 是**真实环境变量**，会落进 `$_ENV` 并赢过
> `phpunit.xml.dist` 里的 `<server>`。所以那里额外写了一行 `<env name="APP_ENV">`，
> 否则 `docker compose exec app vendor/bin/phpunit` 会拿 dev 内核跑测试。

## 结构（§12.2）

```
src/
├── Kernel.php
├── Shared/{Domain,Application,Infrastructure,Http}
└── Module/{Identity,Wallet,Sharing,Social,Sync,Notification,Compliance}/
    └── {Domain,Application/{Port,Dto},Infrastructure,Http}

tests/{Unit,Integration,Api}      # 见 §12.2：Unit 无容器 / Integration 带 DB / Api 端到端
tests/Double/                     # 跨用例复用的测试替身
config/{packages,routes,services}
migrations/
docker/                           # entrypoint 与 prod PHP ini（T-003）
```

M0 阶段模块目录全是空壳（`.gitkeep`），由 T-004 起逐个填充。
`Shared/` 下目前只有 T-003 放的健康检查与种子命令骨架：

| 路径 | 内容 |
|---|---|
| `Shared/Application/Health/` | `HealthCheckInterface`、`ReadinessProbe` |
| `Shared/Application/Seed/` | `SeederInterface` |
| `Shared/Infrastructure/Health/` | `DatabaseHealthCheck`（唯一碰 Doctrine 的一层） |
| `Shared/Infrastructure/Console/` | `SeedCommand`（`app:seed`） |
| `Shared/Http/Controller/` | `HealthController`（`/health/live`、`/health/ready`） |

两个扩展点都是「实现接口即注册」：`config/services.yaml` 的 `_instanceof` 打标签，
收集端用 `#[AutowireIterator]`。加一项就绪检查或一个模块 seeder，都不需要回头改
控制器或命令 —— T-004 的 `RedisHealthCheck` 与 T-005 的 `VaultHealthCheck`
都是这样零配置接进去的。

## 跨切面契约（T-004 / T-006）

`/v1/*` 上的每个请求都会穿过五个监听器。优先级是承重的，由
[`tests/Integration/Shared/Http/ListenerOrderTest`](tests/Integration/Shared/Http/ListenerOrderTest.php)
钉死 —— 属性把优先级散在各个类文件里，那个测试是唯一能看到全貌、也是唯一能防止
后来者随手改序的东西。

| 事件 | 优先级 | 监听器 | 作用 |
|---|---:|---|---|
| `kernel.request` | 512 | `RequestIdListener` | `X-Request-Id` 透传/生成。**必须最先** —— 后面谁抛异常都得有 id 可追 |
| `kernel.request` | 40 | `ClientVersionListener` | 解析 `X-Client`，缺失即 400，过旧即 426。**早于路由**（32） |
| `kernel.request` | 12 | `RateLimitListener` | §7.5「全部写接口 300/min」。**晚于路由**（打错路径不该烧配额）、**早于幂等**（被限流的请求不该抢占幂等键） |
| `kernel.request` | 8 | `IdempotencyMiddleware` | `Idempotency-Key`。**晚于路由** —— `POST /v1/typo` 不该烧掉一个键 |
| `kernel.response` | −256 | `IdempotencyMiddleware` | 落库（仅 2xx）或释放锁 |
| `kernel.response` | −512 | `RequestIdListener` | 回显 `X-Request-Id` |
| `kernel.exception` | 16 | `ApiProblemExceptionListener` | RFC 9457 Problem Details。**必须早于 Symfony 的 `ErrorListener` 并 `stopPropagation()`** |

> ✅ **`/health/*` 的豁免已交付**（原本此处是给 T-004 的警告）。
> 实现方式不是「排除 `/health/*`」那样的黑名单，而是反过来的正向白名单
> [`ApiSurface::isProductApiPath()`](src/Shared/Domain/Http/ApiSurface.php)：
> **只有 `/v1/` 受横切规则约束**，其余一律豁免。于是 T-007 之后任何新的非 `/v1`
> 端点都自动豁免，不需要任何人记得去登记。
>
> 真正的强制点是 [`tests/Api/RouteInventoryTest`](tests/Api/RouteInventoryTest.php)：
> 每条路由要么在 `/v1/` 下，要么登记在一份显式清单里，否则 CI 红。
> 另有 `tests/Api/ClientVersionEnforcementTest::testHealthEndpointsAreExempt`
> 直接守着两个探活端点 —— 它红了就说明 compose 起栈与 §14.3 的部署健康检查要挂。

> ⚠️ **T-112 起有一个容易被当成 bug 的推论**：`GET /v1/config` 自己也在 `/v1/` 下，
> 所以一个过旧的客户端在**那个端点上**拿到的也是 426，而不是配置。
> 这是对的 —— 426 本身就是 T-158 强制升级墙的信号源，客户端不需要先读到
> `latest_client` 才知道该弹墙；`latest_client` 的消费者是仍在支持范围内的客户端。
> **不要给 `/v1/config` 开豁免口子**，完整论证在
> [`ConfigController`](src/Shared/Http/Controller/ConfigController.php) 的类注释，
> `tests/Api/ConfigEndpointTest::testAnOldClientIsRejectedHereToo` 钉着它。
>
> 它同时是全仓库**唯一**一处 `AbstractApiController::json(noStore: false)` ——
> `public, max-age=60` + **`Vary: X-Client`**。那个 `Vary` 是承重的：响应体与该头
> 无关，但状态码与它强相关，少了它的共享缓存会把 200 喂给旧客户端，升级墙就再也
> 不出现。

## 限额与限流（T-006）

§7.5 的两张表，**机制完全不同，不要混**：

| | 超限响应 | 强制点 | 存储 |
|---|---|---|---|
| **系统限额** | `422 limit_exceeded` | `Shared\Domain\Limit\LimitEnforcer` | 配置常量 + 数据库计数 |
| **速率限制** | `429 rate_limited` | `Shared\Application\RateLimit\RateLimiterInterface` | Redis 滑动窗口 |

限额是绝对的存量上限（「你最多有 500 张卡」），重试永远不会成功；
限流是频率（「你每分钟最多 30 次」），等一会儿就好。客户端的文案与重试策略都不同。

**配置**：[`config/packages/ncards_limits.yaml`](config/packages/ncards_limits.yaml)（限额）、
[`config/packages/rate_limiter.yaml`](config/packages/rate_limiter.yaml)（限流）。
两者都由黄金对照表测试与 §7.5 逐行钉死（`LimitEnforcerTest`、`RateLimitPolicyCoverageTest`）——
改数字而不改规格（或反过来）会红。

**怎么用**

```php
// 系统限额：注入 LimitEnforcer（Shared\Domain，各模块 Application/Domain 都能用）
$this->limits->enforceCanAdd(SystemLimit::CardsPerUser, $ownedCount);
$this->limits->enforceLength(SystemLimit::TitleChars, $title);

// 速率限制：注入 RateLimiterInterface（Shared\Application）
$this->limiter->consumeAll([
    new RateLimitCheck('otp_request_email', 'email:'.$emailHash),
    new RateLimitCheck('otp_request_ip', 'ip:'.$request->getClientIp()),
]);
```

**五条规矩**

1. **主体必须带维度前缀**（`ip:` / `user:` / `email:`）。不带的话，一个 IP 字符串
   与一个恰好相同的 device id 会共用同一个计数桶。
2. **多维度用 `consumeAll()`，不要连着调 `consume()`。** 前者是「全过才扣」；
   后者会让攻击者打爆共享 IP 配额后，远程烧掉任意受害者自己的 email 配额。
3. **⚠️ 限流 fail-CLOSED，与 T-004 的幂等 fail-open 相反。** Redis 不可达 →
   `503 service_unavailable`（不是 429：我们不是「判定超限」，是「无法判定」）。
   例外**恰好两条**，判据相同（纯防 DoS，不是安全控制），都标了 `on_store_failure: allow`：
   `write_endpoints`（[ADR-0005](../docs/adr/0005-rate-limiting-topology.md) 决定 3）与
   `config_ip`（T-112 追加，[ADR-0021](../docs/adr/0021-second-fail-open-rate-limit-for-the-config-endpoint.md)）。
   `RateLimitPolicyCoverageTest::testOnlyTheTwoDocumentedDosPoliciesFailOpen` 把这个白名单
   断言成**封闭集合** —— 加第三条要先写 ADR，省略 `on_store_failure` 时默认仍是 `deny`。
   两处方向相反看起来像 bug，**它不是** —— 理由见
   [ADR-0003](../docs/adr/0003-problem-details-and-idempotency-semantics.md) §4 与
   [ADR-0005](../docs/adr/0005-rate-limiting-topology.md)。
4. **按 IP 限流依赖 `framework.trusted_proxies`**（T-004 已配）。
   回归测试 [`tests/Api/TrustedProxyTest`](tests/Api/TrustedProxyTest.php) ——
   那条没了的话，§7.5 的 IP 20/h 会把全世界算作一个 IP，而且看起来完全像是「限流生效了」。
5. **`LimitEnforcer` 在 `Shared\Domain\Limit`，不是任务书写的 `Shared\Infrastructure\RateLimit`。**
   deptrac 里各模块 Application 的允许列表不含 `Shared.Infrastructure` ——
   放那儿的话 T-111 在 Wallet 层根本无法调用它。与 `ErrorCode` 同一条论证。

**§7.5 里两条「总计」不在配置里**：challenge 的 5 次总计归 `otp_challenges.attempts`（T-104），
username 的 10 次总计归 users 行（T-107）。它们是生命周期计数而不是滑动窗口，
而 §8.2 的 ROPA 规定限流计数只保留 24 小时。

### 强制点登记（T-111）

**一个限额有配置值不等于它被 enforce 了。** `ncards_limits.yaml` 里躺着 §7.5 的
全部数字，但其中一半现在没有任何调用点 —— 下表是那份对照，加一条限额时一并更新。

| §7.5 | `SystemLimit` | 强制点 | |
|---|---|---|---|
| 每用户卡数 500 | `CardsPerUser` | [`CreateCardService::enforceLimits()`](src/Module/Wallet/Application/Card/CreateCardService.php) | ✅ |
| barcode payload 1024 **字节** | `BarcodePayloadBytes` | `Create` / [`UpdateCardService::enforceLimits()`](src/Module/Wallet/Application/Card/UpdateCardService.php) | ✅ |
| note 2000 字符 | `NoteChars` | 同上 | ✅ |
| title 100 字符 | `TitleChars` | 同上 | ✅ |
| username 3–20 / `[a-z0-9_]` | —— | [`Identity\Domain\ValueObject\Username`](src/Module/Identity/Domain/ValueObject/Username.php) | ✅ |
| username 设定 10 次总计 | `UsernameAttemptsPerUser` | [`AssignUsernameService`](src/Module/Identity/Application/Me/AssignUsernameService.php) | ✅ |
| 每卡成员数 20 | `MembersPerCard` | **无** | ⏳ T-304 |
| 每用户好友数 500 | `FriendsPerUser` | **无** | ⏳ T-301 |
| 每日好友请求 50 | `FriendRequestsPerDay` | **无** | ⏳ T-301 |
| 每日共享邀请 100 | `ShareInvitesPerDay` | **无** | ⏳ M2 |

后四行**故意为空**，不是漏了：`friendships` 表与邀请端点在 M1 还不存在，
往一条不存在的写路径上挂钩子只会得到一个没人走的分支和一段假的覆盖率。

⚠️ `MembersPerCard` 尤其容易被误认为漏了 —— 建卡确实写一行 `card_members`。
但那个 20 管的是**邀请**（1 owner + 19 viewer），owner 是第 1 行、永远不可能超限；
在每次建卡上花一次 `COUNT(*)` 去证明「1 ≤ 20」是买零信息。
完整论证在 [`CardOwnershipRegistrarInterface`](src/Module/Sharing/Application/Port/CardOwnershipRegistrarInterface.php) 的类注释里。

**不在这张表里的长度限制**：`cards.merchant_label` 的 100 字符是契约的字段约束
（`maxLength`），不是 §7.5 的额度 —— 所以它是 `400 validation_failed` + `too_long`，
由 [`CardFields`](src/Module/Wallet/Application/Card/CardFields.php) 管。
把它挪进 `LimitEnforcer` 会静默地把码从 400 改成 422。

**待接入（T-1xx）**：`RateLimitSubjectResolverInterface` 现在是匿名实现（回落到 IP），
认证落地后把 `config/services.yaml` 里的 alias 指向 Authenticated 版本 ——
与 `IdempotencyScopeResolverInterface` 是同一个待办，最好一起改。

## 加密门面（T-005）

§5.3 的服务端信封加密。**所有**涉及 `*_encrypted` 列与 `*_hash` 列的读写都必须走这里。

```
明文 ──► Transit encrypt (ncards-card) ──► "vault:v1:BASE64" ──► Postgres TEXT
```

三个接口在 `Shared\Application\Crypto`，值对象在 `Shared\Domain\Crypto`，
实现在 `Shared\Infrastructure\Crypto`：

| 接口 | 用途 | 谁会用 |
|---|---|---|
| `CryptoServiceInterface` | 单条 `encrypt` / `decrypt` | `users.email_encrypted`（T-101 建列，T-102/104 填）、T-109（建卡/改卡） |
| `BatchDecryptorInterface` | **批量** `decrypt`，一次请求解一批 | T-109 列表、T-203 bootstrap |
| `HmacHasherInterface` | 带 pepper 的 HMAC-SHA256，返回 32 字节裸摘要 | `email_hash`、`barcode_value_fingerprint`、`code_hash` |

> `HmacHasherInterface::hash()` 返回的 32 字节裸摘要在进实体之前要包成
> `Shared\Domain\Crypto\HashDigest`（T-101）—— 理由与 `Ciphertext` 同源，见下一节第 2 条。

**四条规矩**

1. **要解多于一条就用 `BatchDecryptorInterface`。** 在循环里调 `decrypt()` 是 §5.3
   明令禁止的写法：200 张卡会变成 200 次 HTTP 往返，把 T-203 的 700 ms 预算吃掉大半。
   强制点是 `VaultBatchDecryptorTest::testDecryptsTwoHundredItemsInASingleRequest()`。
2. **`*_encrypted` 列的类型是 `Ciphertext`，不是 `string`。** 那几列在 §17.1 里是 `TEXT`，
   数据库分不出密文与明文；一次漏调 encrypt 的重构会把卡号以明文写满整张表且不报错。
   `Ciphertext::fromString()` 只接受 `vault:v<N>:` 前缀，是唯一靠得住的闸门。
3. **不缓存明文**（§5.3）。当前没有任何 memo，实测也不需要（见
   [`infra/vault/README.md`](../infra/vault/README.md) 的实测表）。
   ⚠️ 真要加也**不能**往这几个类里塞 `private array $memo` —— 它们是容器单例，
   而 FrankenPHP 的 worker 长驻，那会变成跨请求缓存，正是 §5.3 禁止的东西。
4. **Vault 不可用时 fail-CLOSED**，与 T-004 幂等的 fail-open **相反**。
   解不了密就没有可返回的正确数据，加密侧更糟（fail-open = 把明文写进密文列）。
   `CryptoUnavailable` 是 `DomainException` 子类，渲染为 `503 service_unavailable`（可重试、
   记 warning）；`CryptoFailed` 是 `500 internal_error`。这个不对称是刻意的，
   理由见两个类的注释与 `IdempotencyStoreUnavailable` 的对照。

**认证**：dev/test 用 `VAULT_TOKEN`（compose dev 模式的 root token），
staging/prod 用 `VAULT_ROLE_ID` + `VAULT_SECRET_ID` 走 AppRole。
判据是「配没配 `VAULT_ROLE_ID`」而不是 `APP_ENV`，选择逻辑在 `VaultTokenProviderFactory`。

**本地起栈**：`docker compose up -d` 会自动跑 `vault-init` 初始化 Transit。
裸机跑 `composer test` 时 Vault 不可达，相关集成用例自行 skip（全绿）。

**运维**：生产每次重启后 Vault 是封印状态，必须人工 unseal ——
[ADR-0004](../docs/adr/0004-manual-vault-unseal.md) 与
[`docs/runbooks/vault-unseal.md`](../docs/runbooks/vault-unseal.md)。

## 邮件通道（T-102）

§3.1 / §3.2 的外发邮件。完整论证在
[ADR-0012](../docs/adr/0012-mail-channel-topology.md)，这里是使用者视角的摘要。

```
调用方 ──► MailSenderInterface::send(MailRequest)   ← 入队即返回，不等 SMTP
             │
             ├─ SendMailCommand ──► EncryptedMailSerializer ──► messenger_messages
             │                        （整条 body 经 Vault Transit 加密）
             │
   worker ◄──┘  messenger:consume email
             │
             └─ SendMailHandler ──► 熔断判定 ──► SymfonyMailerTransport ──► 服务商
                                    │              （解密收件人、渲染双语模板）
                                    └─ email_send_total{provider,template,result}
```

**怎么发一封信**（T-103 起的调用方）：

```php
$this->mailSender->send(new MailRequest(
    MailTemplate::OtpCode,
    MailLocale::German,          // 由调用方从 Identity 的 Locale 映射，见下面第 4 条
    $user->emailEncrypted(),     // ⚠️ Ciphertext，不是 string
    ['code' => $code, 'expires_in_minutes' => '10'],
));
```

**五条规矩**

1. **调用方只认识 `MailSenderInterface`。** 本接口之上不得出现任何
   `Symfony\Component\Mailer\*` 或服务商特有类型 —— §3.2 把「一期单通道、无双活」
   记为有意识的风险接受，而「未来 2 人日能补回来」的唯一前提就是这层收敛。
   强制点是 deptrac：`Framework.Mail` / `Framework.Templating` 两个图层**只**加进了
   `Notification.Infrastructure` 的允许列表，`composer deptrac:selftest` 的场景 ④ 守着它。
2. **收件人是 `Ciphertext`，不是 `string`。** `users.email_encrypted` 原样递进来即可，
   全程不解密；明文地址的作用域是 `SymfonyMailerTransport::send()` 一个方法体。
   未注册邮箱（T-103 的 decoy 路径）由调用方自己 `encrypt(CryptoKey::Pii, …)` 一次。
3. **`send()` 入队即返回，不告诉你信有没有发出去。** 返回 `void` 是刻意的 ——
   这不是性能优化，是 §3.8 的安全要求：T-103 的验收标准要求「已注册 vs 未注册邮箱的
   **耗时分布**不可区分」，而同步发信会让两条路径差出一次 SMTP 往返。
   投递结果只在 `email_send_total{result}` 与 `email_failed` 队列里可见。
4. **`MailLocale` 与 `Identity\Domain\ValueObject\Locale` 是两个 enum**，取值域相同但
   不能复用（`Notification.Dto` 的 deptrac 允许列表只有 `Shared.Domain`）。
   调用方做一行 `match`。取舍见 ADR-0012。
5. **加模板 = 改四处**：`MailTemplate` 加 case（含 `requiredVariables()`）、
   `MailCircuitBreaker::criticalityOf()` 加分支、`templates/email/{de,en}/` 各加三份文件、
   `MailTemplateRenderingTest::sampleVariables()` 加样例值。
   漏第二处会抛 `\UnhandledMatchError`，漏其余的会被 `MailTemplateRenderingTest` 拦下。

**熔断**（§3.1「全局日发信量超阈值 → 告警 + 自动熔断非关键邮件（保留 OTP）」）：
阈值在 `config/packages/ncards_mail.yaml`。OTP 与 Magic Link 是 `Critical`，
任何阈值之上都照发；两封安全提醒是 `Advisory`，熔断时丢弃并记
`result="suppressed"`。⚠️ 计数器不可达时**放行**，与 §7.5 限流的 fail-closed 相反 ——
理由见 `MailCircuitBreaker` 的类注释，那个不对称是刻意的。

**本地看信**：默认 `MAILER_DSN=null://null`（不发信）。要肉眼看就起 Mailpit：

```bash
docker compose --profile dev up -d mailpit     # http://localhost:8025
# 然后把 infra/compose/.env 的 MAILER_DSN 改成 smtp://mailpit:1025 并重启 worker
```

不起栈也能看四封信 × 两种语言的渲染结果：
`vendor/bin/phpunit --filter MailTemplateRenderingTest`。

**通道**：Q3 已决（[ADR-0013](../docs/adr/0013-mail-via-domain-mailbox.md)）——
发信走 `n-cards.de` 的**域名邮箱**（托管方 dogado GmbH，德国），标准 SMTP，
**不采购专业 ESP**。`MAIL_PROVIDER=dogado`。换选型时改的只有 `MAILER_DSN` 与
`MAIL_PROVIDER` 两个环境变量，`MailSenderInterface` 以上一行代码没动。

⚠️ 代价记在 ADR 里，有两条会影响写代码的人：
① **有发信配额**，超了是被托管商停用账号（R1），所以熔断阈值下调到了
**200 / 500**（`ncards_mail.yaml`，且那两个数**目前是猜的**）；
② **没有 bounce / 投诉回路，也没有投递 webhook** —— `email_send_total{result}`
与 §14.4 的 OTP 转化率告警是仅有的两个送达信号，别指望还有别的地方能看投递结果。

**运维**：R1 触发时的处置、SPF / DKIM / DMARC 记录、配额天花板的判读 ——
[`docs/runbooks/email-dns.md`](../docs/runbooks/email-dns.md)。
⚠️ 仍然欠着：dogado 的 DKIM 是否可用（**不支持即为硬阻塞**）、实测配额、
签 AVV，以及 §3.2 的 4 次手工送达验证（§15.1 上线必需项）——
见 [`docs/tasks/M1.md`](../docs/tasks/M1.md) 的 T-102 回填块。

## 持久化约定（Doctrine ORM）

T-101 落地。完整论证在
[ADR-0011](../docs/adr/0011-doctrine-orm-xml-mapping-and-module-owned-foreign-keys.md)，
这里只列会绊住人的五条。

**1. 映射是 XML，不是属性。** 实体在 `Module/<M>/Domain/Entity/`，是纯 PHP；
映射在 `Module/<M>/Infrastructure/Doctrine/Mapping/<Entity>.orm.xml`，在
`config/packages/doctrine.yaml` 的 `orm.mappings` 里逐模块登记（`auto_mapping: false`）。
不是风格选择：`deptrac.yaml` 里每个模块的 Domain 允许列表逐字是 `[Shared.Domain]`，
`#[ORM\Entity]` 会 import `Doctrine\ORM\Mapping\*` → `Framework.Persistence` → violation。

**2. 三个自定义 DBAL 类型，都在 `Shared\Infrastructure\Doctrine`。**

| 类型 | PHP ↔ 列 | 挡住什么 |
|---|---|---|
| `uuid` | `Uuid` ↔ PG 原生 `uuid` | VARCHAR(36) 带来的索引膨胀（T-004） |
| `ciphertext` | `Ciphertext` ↔ `TEXT` | 明文被写进 `*_encrypted` 列 |
| `hash_digest` | `HashDigest` ↔ `BYTEA` | hex 被写进摘要列；用 `===` 比摘要（§7.1 要常量时间） |

后两个**不收裸 `string`** —— 那正是它们存在的理由。`HashDigestType` 还要
`getBindingType() = BINARY`：不然 pdo_pgsql 把裸字节当文本发，遇到 `0x00` 就截断，
而摘要里出现 `0x00` 的概率约 12%。

**3. 实体不是 `final`，属性不加 `readonly`。** 本仓库其余地方一律 `final readonly`，
实体是例外：Doctrine 的懒加载对象要继承实体类并在实例已存在之后回填属性。
「事实不可变」靠**没有 setter** 保证。每个实体类的注释都写了这一条，别顺手加回来。

**4. 外键要建成 `<many-to-one>`，且暂时只在模块内部。**
`doctrine:schema:validate` 会把库里的外键与 ORM 元数据对账，映射成普通 uuid 列的话
那条命令永远绿不了。join-column 必须写 `on-delete` —— Comparator 不比外键**名字**
（所以迁移里用 §5.2 要求的 `fk_<table>_<column>`），但**比 onDelete**。
⚠️ 这条只管**模块内部**的外键。**跨模块**的反过来办：实体只持有一个 uuid 列，
外键由 `Shared\Infrastructure\Doctrine\CrossModuleForeignKeys` 在
`postGenerateSchema` 上补进 ORM schema。加一条要同时改三处（那份清单、迁移、
引用侧的 `<index>` 声明），见 [ADR-0019](../docs/adr/0019-cross-module-foreign-keys-via-post-generate-schema.md)。

目前清单里有四条，M3 还会加：

| 约束 | 方向 | `on delete` |
|---|---|---|
| `cards.owner_id → users(id)` | Wallet → Identity | `RESTRICT` |
| `card_members.card_id → cards(id)` | **Sharing → Wallet** | `CASCADE` |
| `card_members.user_id → users(id)` | Sharing → Identity | `CASCADE` |
| `card_members.added_by → users(id)` | Sharing → Identity | `SET NULL` |

⚠️ **`card_members` 属 Sharing 而不是 Wallet**（§4.2 把「卡成员」划给它，
M3 的 T-303/304/305 都在它上面写），所以它的**三条**外键全都跨模块 ——
包括看起来像模块内部的 `card_id → cards`。ADR-0019 原先把 T-110 记成「两条」，
那是按归 Wallet 算的，已随 T-110 修订。完整论证见
[ADR-0020](../docs/adr/0020-card-members-belongs-to-sharing-and-wallet-reads-it-through-three-ports.md)。

⚠️ `RESTRICT` 与 `CASCADE` 的不对称是有意的：**删一个用户会被他自己的卡挡住
（要人处理的冲突），但他作为 viewer 的成员行可以随他一起消失。**
别「顺手改成一致」。

**5. 索引、唯一约束与每个 DEFAULT 都要在 XML 里再写一遍，名字与迁移逐字相同。**
索引是**按名字**比的：不显式声明的话 DBAL 会自动补 `IDX_<hash>`（外键索引尤其容易忘），
于是迁移里写什么名字都会 diff。DEFAULT 用 `<options><option name="default">now()</option></options>`。

**5a. 部分索引（`WHERE …`）能用，但谓词要抄 PG 规范化之后的样子。**
DBAL 4 用 `pg_get_expr(indpred, …)` 把谓词读回索引的 `where` 选项，
再与 XML 里声明的做 **`===` 字符串比较** —— 而 PG 存回来的带一对外层括号：

```xml
<index name="idx_cards_owner" columns="owner_id">
    <options><option name="where">(deleted_at IS NULL)</option></options>
</index>
```

少那对括号 `schema:validate` 就永久不同步。照抄 `pg_indexes.indexdef` 里看到的那串最稳。
（T-106 的迁移注释里记着一条相反的结论 ——「DBAL 读不回 WHERE 子句」。
那次观察到的现象是真的，但归因错了：读得回，只是括号没对上。见 ADR-0019 末尾。）

写完跑这两条对账，任何漂移都会当场现形：

```bash
composer migration:check   # 迁移 up/down 往返 + doctrine:schema:validate
bin/console --env=test doctrine:schema:update --dump-sql   # 期望：Nothing to update
```

⚠️ `doctrine:schema:create --dump-sql` 生成的 SQL **不能直接抄进迁移**：
DBAL 会把 `now()` 当字符串字面量输出成 `DEFAULT 'now()'`。它只能当对账参考。

**仓储**：接口在 `Module/<M>/Domain/Repository/`（**不是** `Application/Port/` ——
Port 的 deptrac 允许列表刻意不含本模块 Domain），实现在
`Module/<M>/Infrastructure/Doctrine/`。`save()` 直接 `flush()`；
多次写要原子就在 Application 层包一层
`Shared\Application\Transaction\TransactionRunnerInterface::run()`
（`use_savepoints: true` 让嵌套安全）。

## 分层职责

| 层 | 允许做 | 禁止做 |
|---|---|---|
| `Http/Controller` | 反序列化请求、调用 Application、序列化响应 | 任何业务逻辑、直接用 Doctrine |
| `Application` | 编排、事务边界、权限检查、DTO 组装 | 直接写 SQL、了解 HTTP |
| `Domain` | 实体、值对象、不变量、领域服务、领域事件 | import 任何框架类型 |
| `Infrastructure` | Doctrine 映射与仓储实现、外部 HTTP 客户端、Vault、Mailer | 被 Domain 直接引用（只能实现其接口） |

## 模块边界（[`deptrac.yaml`](deptrac.yaml)）

Deptrac 同时强制**两个维度**，用「模块 × 分层」的交叉积图层在**一个** ruleset 里表达：

1. **模块间**：不得引用他模块的 `Domain` / `Application` / `Infrastructure` / `Http`，
   只能看到对方的 `Application\Port\*` 与 `Application\Dto\*`。
2. **层间**：`Http → Application → Domain`；`Infrastructure` 实现 `Domain` 定义的接口。
   `Framework.{Http,HttpClient,Persistence,Messaging,Logging,Core}` 六个图层收集 vendor 里的
   框架类型，`*.Domain` 的允许列表里一个都没有 —— 这就是「Domain 不得 import 框架类型」的强制点。
   其中窄图层（`HttpClient` / `Messaging` / `Logging` / 部分 `Persistence`）**只对 `*.Infrastructure` 开放**：
   落进宽松的 `Framework.Core` 的话，任何模块的 `Application` 都能直接注入
   `HttpClientInterface` 或 `MessageBusInterface`，把 Shared 的门面整个绕过去。

拆成两份配置会让 `Wallet.Http → Identity.Domain` 这类跨维度组合从缝隙里漏过去，
所以坚持一个 ruleset。文件头部有「新增模块时怎么改」的清单。

唯一豁免：`Sync\Infrastructure\Doctrine\SyncReadModel`（§4.2 规则 5 的跨模块只读查询入口），
`deptrac.yaml` 末尾的 `skip_violations` 留了槽位与使用约束，T-202 实现时填。

**`composer deptrac:selftest`** 会临时写入三段违规代码并断言 deptrac 逐一拦得住。
`deptrac analyse` 只能证明「当前代码没违规」，证明不了「规则还有效」—— 谁把规则改松了，
自检会红而 `analyse` 依然是绿的。三个场景：

| # | 违规 | 守的是什么 |
|---|---|---|
| ① | `Wallet.Domain` → `Identity.Domain` | 模块边界（T-002 验收标准） |
| ② | `Shared.Domain` → `Symfony\...\Request` | `Shared.Domain: []` 空白名单（T-004） |
| ③ | `Wallet.Application` → `Shared\Infrastructure\Crypto\VaultTransitCrypto` | 加密门面只经接口暴露（T-005 验收标准） |

场景 ③ 尤其需要：它今天**自动成立**（`Shared.Infrastructure` 不在任何模块的允许列表里），
恰恰因为如此才没有任何东西会在它失守时报警。

## 质量门禁（§13.3）

| 检查 | 阈值 |
|---|---|
| `php-cs-fixer check` | 0 差异（`@Symfony` + `@PHP83Migration` + `declare_strict_types`） |
| `phpstan analyse` | level 8，`src/` `tests/` `tools/` 全量，0 error；[baseline](phpstan-baseline.neon) 只允许缩小 |
| `deptrac analyse` | 0 violation |
| `composer audit` | 0 高危 |
| 行覆盖率 | 整体 ≥ 70%；`Module/*/{Domain,Application}` ≥ 85%（由 [`tools/coverage-check.php`](tools/coverage-check.php) 校验 clover 报告） |

## 配置与密钥

`DATABASE_URL` / `REDIS_URL` / `VAULT_ADDR` / `VAULT_TOKEN` 在容器里由 compose 注入
（拼接来源是 `infra/compose/.env`，见 `infra/compose/docker-compose.base.yml`）；
下面这两个文件只提供**裸机**上跑 `composer test` / `bin/console` 时的默认值。

⚠️ **`VAULT_SECRET_ID` 是凭据**，只在 staging/prod 存在，由 sops(age) 加密后随 Ansible
下发（T-012），绝不入库、绝不进 CI。入库文件里那一行是空值占位，
存在只是为了让「应用会读这个变量」有据可查。

`.env` 与 `.env.test` 是 Symfony 约定的**非密钥默认值**文件，入库
（仓库根 `.gitignore` 对这两个文件开了窄口，其余 `.env*` 一律忽略）。
真实密钥走 `.env.local` / 容器环境变量 / sops(age)，见 §14 与 T-003 / T-012。

### 客户端版本与维护窗口（T-112）

四个**非凭据**变量，`GET /v1/config` 的全部输入。三处配置面都已打通：
`infra/compose/docker-compose.base.yml` 的 app `environment`、
`infra/compose/.env.example`、以及 Ansible 的 `env.j2` + `group_vars/all/main.yml`。

| 变量 | 说明 |
|---|---|
| `MIN_SUPPORTED_CLIENT` | §6.1 的强制升级基线。**两个读者共用同一个值**：`ClientVersionListener` 的 426 判定，与 `/v1/config` 下发给客户端的那个数字（`config/services.yaml` 的注释解释了为什么不能拆成两个参数） |
| `LATEST_CLIENT` | 商店上最新的版本，软提示用。恒 `>= MIN`，否则启动即 500 |
| `MAINTENANCE_WINDOW_START` / `_END` | §9.2 的计划维护窗口。**成对出现**，RFC 3339 且 **offset 必填**；都留空即无窗口（常态）。`active` / `message_key` / `retry_after` 三个下发字段全部由 `MaintenanceWindow::statusAt()` 按时钟派生 |

⚠️ **T-112 之前 `MIN_SUPPORTED_CLIENT` 在整个 infra 里一次都没出现**：prod overlay
把 dev 的 bind-mount `!reset null` 掉、prod target 直接 `COPY . .`，于是生产读的是
**烤进镜像的** `.env` 默认值。那时无人可见所以无害，而 `/v1/config` 一上线就把它
对外宣告成基线 —— 抬高基线这个纯运维动作因此曾需要重新构建并推送一个镜像。

⚠️ **爆炸半径不对称，排查时别混**：`MIN` 配坏 → **每个**请求 500（含 `/health/*`），
compose healthcheck 与 §14.3 的部署当场失败（判定在 `ClientVersionListener` 的构造期，
而 `kernel.request` 的监听器在任何请求处理之前就被实例化）。`LATEST` 或窗口配坏 →
**只有** `/v1/config` 500，`/health/ready` 照样 200，部署照过，唯一的安全网是
§14.4 的 5xx 告警。把 `ClientConfigProvider` 做成一项就绪检查能让后者也红，
但那等于「一个维护公告的时间戳打错字让整个 API 下线」—— 刻意没做。

**限流**：按 IP **300/min**（§7.5 的 `config_ip`，T-112 追加）。消费点在
`ConfigController::get()` 里显式 `consume()` —— `RateLimitListener` 只管写接口，
读接口的限流按端点配，那个类的注释解释了为什么。

⚠️ 它是 §7.5 里**两条 fail-open 策略**之一（`on_store_failure: allow`，
[ADR-0021](../docs/adr/0021-second-fail-open-rate-limit-for-the-config-endpoint.md)）：
Redis 不可达时本端点**仍然 200**。这是刻意的 —— fail-closed 等于给唯一一个刻意
不依赖 PG / Redis / Vault、且可缓存的端点新增一个 Redis 依赖，于是 Redis 一挂，
每次冷启动都 503，客户端连「要不要弹升级墙」都问不出来。

⚠️ 配额按**分钟**而不像 §7.5 其余 IP 策略（20/h、60/h、100/h）按小时，而且留得很松：
约束是 **CGNAT** 不是攻击者 —— 德国移动运营商一个公网 IPv4 背后可能上千订户共用这一个桶，
配紧的症状是一整个运营商出口在晚高峰被限流，那批用户的升级墙与维护横幅一起失灵。

⚠️ 配额只对**格式正确**的请求生效：`ClientVersionListener`(40) 与路由都排在控制器之前，
所以缺 `X-Client` 的 400 与过旧客户端的 426 都不烧配额（既有房规，两条用例钉着）。
那一类最廉价的洪水只有边缘层挡得住，而 `infra/caddy/Caddyfile` **今天没有任何限流
或连接数限制** —— 留给后续的基础设施卡，记在 ADR-0021 的负面一节。
