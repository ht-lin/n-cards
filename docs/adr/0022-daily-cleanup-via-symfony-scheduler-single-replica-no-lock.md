# 0022. 每日保留期清理走 Symfony Scheduler：单副本、无分布式锁、无 stateful

- **Status**: Accepted
- **Date**: 2026-09-11
- **Deciders**: 后端负责人
- **规格引用**: §5.2、§8.2、§9.2、§13.5、§14.2、§14.4
- **影响**：T-113（本 ADR 随其落地）、T-204（`change_log` 90 天）、
  T-402（导出过期清理）、T-403（`PurgeDeletedAccounts`）、T-404（key rewrap）——
  后四张卡接入清理的方式由本 ADR 的决定 2 定死；另影响 ADR-0014 遗留的
  `is_decoy` 删列时机（决定 5）

## Context

§14.2 的服务清单里 `scheduler` 从 T-002 起就是一个注释槽位，因为
`symfony/scheduler` 没装。与此同时，已交付的代码里有三处把「每日清理」当作既成事实：

- `User.php` 的类注释：「超过 7 天的僵尸行由每日清理任务物删（T-113）」；
- ADR-0018 决定四的 `OnboardingState::UserUnknown` 那一格，以及它据此**禁止**
  给 onboarding 状态加缓存 —— 理由逐字是「删号与 T-113 的清理会让它变回 `UserUnknown`」；
- `OtpChallenge::forgetRequestIp()`，写好了、有单测、注释写着「由 T-113 的每日任务调用」，
  但没有任何调用方。

也就是说 **§8.2 ROPA 承诺的保留期在生产上没有任何执行点**。这不是性能或磁盘问题：
`otp_challenges` 自 ADR-0014 起每行都带 `email_encrypted`（Vault Transit 加密的收件
邮箱），那张表的数据分级已经是「含加密的个人数据」，而它只增不减。一份 Art. 30 记录
写着一件没有发生的事，是合规硬伤。

要决定的是：这些任务由什么来触发、怎么组织、以及用什么姿态处理「同时跑两份」
与「错过一次」这两个分布式调度的经典问题。

## Decision

### 1. 触发器是 Symfony Scheduler，不是宿主机 cron

`scheduler` 容器跑 `bin/console messenger:consume scheduler_default`，
时刻表在 `Shared\Infrastructure\Scheduler\DailyMaintenanceSchedule`（`#[AsSchedule]`）。
`scheduler_default` 这个 transport 名不是我们起的 —— Symfony 的
`AddScheduleMessengerPass` 对每个 `#[AsSchedule('<name>')]` 自动注册一条
`scheduler_<name>` receiver，所以 `config/packages/messenger.yaml` 里**不需要、
也不应该**再写一条同名 transport（手写的那条会把自动注册的顶掉）。

不用宿主机 cron 的三条理由：

1. **同一个镜像、同一份环境变量、同一条日志管道。** cron 容器要自己解决
   「怎么拿到 `DATABASE_URL`」「日志往哪去」，而这两件事 app / worker 已经解决过了。
2. **任务是普通 PHP 服务，因而可单测。** 边界（第 7 天 vs 第 8 天）是 T-113 验收标准
   点名要的断言，而 cron 表达式里的 `psql -c "DELETE ..."` 测不了。
3. **ADR-0009 的部署拓扑不认识 crontab。** Ansible 下发的是 compose 栈；
   多一个宿主机层面的状态，就多一件 `docker compose up` 之后仍然可能不一致的东西。

### 2. 扩展点是一个标签，不是一张清单

`Shared\Application\Cleanup\CleanupTaskInterface` 由 `config/services.yaml` 的
`_instanceof` 自动打上 `app.cleanup_task`，`CleanupRunner` 用 `#[AutowireIterator]` 收集。
形状与 T-003 的 `SeederInterface` / `SeedCommand` 逐字同构。

**新增一条清理 = 加一个实现类。** 不改 runner、不改时刻表、不改 compose。
T-204 / T-402 / T-403 / T-404 都按这个方式接入 —— 这是本决定存在的全部意义，
也是为什么 `Framework.Scheduling` 图层只对 `Shared.Infrastructure` 开放
（见决定 3）：一个模块若能自己排一条时刻表，上面那句话就不成立了。

配套的两个入口共用同一个 runner：
- `RunDailyCleanupHandler`（scheduler 每天触发的那条消息）
- `bin/console app:cleanup [--task=…]`（运维手动入口，退出码有意义）

### 3. `Framework.Scheduling` 是一个新的 deptrac 窄图层

`Symfony\Component\Scheduler\` 与 `Cron\`（dragonmantank）收成一层，只加进
`Shared.Infrastructure` 的允许列表，并在 `Framework.Core` 的 `must_not` 里
排除同一条正则。与 `Framework.Messaging` / `Framework.Mail` 的论证逐字同构：
不单独成层的话它落进 `Framework.Core`，而那一层对**每个模块的 Application 开放**。

`tools/deptrac-selftest.sh` 增加场景 ⑤ 钉住这一点。

### 4. 单副本、无 `->lock()`、无 `->stateful()` —— 三者是一个整体

- **`replicas: 1`**（prod 与 staging 都是），与紧邻的 `worker: 2` 正好相反。
  两个 scheduler 会把同一条 cron 触发两次。
- **不引入 `symfony/lock`**：单主机 compose 上「保证只有一个」就是上面那一行。
- **不用 `->stateful()`**：它要一个 PSR-6 缓存池，而 `cache.app` 是文件系统适配器、
  写在 `/app/var` —— 那是 **tmpfs**，状态活不过一次容器重启。
  一个「声称有状态」却每次重启就丢的时刻表，比一个诚实的无状态时刻表更坏。

**代价，明确接受**：scheduler 停机跨过触发时刻，**那一天的清理就是没跑过，不会补跑**。
这可以接受，因为三个任务都按**截止时刻**删（不是增量游标），第二天那一趟会把
前一天该删的一并删掉。§8.2 的保留期语义是「不超过 N 天」，晚一天仍然满足；
漏删才不满足。同理，`--time-limit=3600` 的每小时自杀若恰好撞上触发的那两秒，
那天也会跳过 —— 概率约四年一次，后果同上。

⚠️ **要改成 2 副本，必须先装 `symfony/lock` 并给 `Schedule` 加 `->lock()`，
不能只改那个数字。**

### 5. 触发时刻是 04:30 **Europe/Berlin**，不是 UTC

§9.2 的计划内维护窗口是每周二 03:00–04:00 CET。清理落在窗口里，会让
「周二的清理没跑」与「周二本来就停服」变成同一个现象。

必须传时区：容器的 PHP 默认时区是 UTC，不传的话「04:30」会随夏令时在柏林的
05:30 与 06:30 之间漂，一年两次，且漂进维护窗口时**没有任何症状**。
`DailyMaintenanceScheduleTest` 用跨夏令时的两条断言（CEST 与 CET 各一条，
本地墙钟相同而 UTC 时刻不同）把它钉死。

### 6. 错误隔离在 runner，异常不回到 Messenger

一个任务抛异常 → 记一行 `error` 日志 + `cleanup_errors_total{task}` → **继续下一个**。

具体的失败场景是已知的：僵尸行删除撞上 `cards.owner_id` 的 `ON DELETE RESTRICT`。
按 ADR-0018 的 onboarding 拦截器，`username IS NULL` 的用户拿不到任何能建卡的端点，
所以理论上不可能 —— 但真发生了，代价不该是「`otp_challenges` 从此再也不清理」。

Handler **不重新抛出**。`scheduler_default` 没有 `retry_strategy` 也没有
`failure_transport`（`SchedulerTransport::reject()` 是空操作），抛出去的效果是
Messenger 记一行「消息处理失败」然后把它丢掉 —— 而那一行说不出是哪个任务失败，
更说不出另外两个跑没跑。退出码有意义的是 `app:cleanup`，不是消息那一侧。

### 7. 指标是两个计数器，不是一个

`cleanup_runs_total{task}`（每趟恒 +1）与 `cleanup_rows_total{task}`（只在真处理了行时加）。

合成一个的话，「清理跑了但没东西可删」（正常，rows=0）与「清理根本没跑」
（scheduler 挂了）在指标上完全一样 —— 而后者是本卡唯一需要告警的故障。
另外 `MetricsInterface::counter()` 的 `$by` 是 `positive-int`，0 传不进去。

### 8. `is_decoy` 本卡只切读，删列另开一卡

ADR-0014 把 `is_decoy` 的删列归给了 T-113。本 ADR **收窄**那句话：T-113 只摘掉
`VerifyOtpService` 里那次判断，**列、ORM 映射、实体属性与 `decoy()` 工厂全部留着**。

两条理由，第二条是硬的：

1. §13.5 要求「切读」与「删列」两次发布至少隔 2 周；ADR-0010 又写明生产回滚
   只回镜像 tag、不回迁移 —— 同一次发布里既切读又删列，一次回滚就让 OTP 验证 500
   （旧镜像仍然 `SELECT is_decoy`）。
2. **`doctrine:schema:validate` 比的是 ORM 映射与真库内省结果。** 把 `<field>` 从
   `OtpChallenge.orm.xml` 摘掉而列还在，diff 里会多出一条 `DROP COLUMN`，
   `composer migration:check` 第 ③ 步当场红。**映射与列必须同一次发布一起消失。**

切读本身是安全的：哑挑战寿命 10 分钟、`decoy()` 造的行带的是一个从未发出过的码的
哈希、ADR-0014 已于 2026-09-06 上线，且本卡的清理任务首跑会把任何残留行删干净。

删列的前置条件与步骤写在 `docs/tasks/M1.md` 的 T-113「留给后续任务」一节。

## Consequences

**正面**

- §8.2 的保留期第一次真的有执行点。`otp_challenges` 的 `email_encrypted`
  留存窗口从「无上限」变成「死后 24 小时」。
- T-204 / T-402 / T-403 / T-404 的接入成本降到「加一个实现类」，
  且它们自动获得错误隔离、日志、指标与 `app:cleanup --task=` 的手动入口。
- §14.2 服务清单里少一个注释槽位。

**负面 / 需要知情**

- **多了一个容器。** 它和 worker 一样不起 HTTP 服务，所以必须显式
  `healthcheck: disable: true`（FrankenPHP 基础镜像自带的 Caddy 健康检查必然失败，
  后果是一个永远显示 unhealthy 的常驻红色）。
- **scheduler 停机 = 那天不清理**（决定 4），且**没有任何告警**会说这件事 ——
  T-405 接入抓取前，唯一的信号是 `cleanup_runs_total` 不再增长。
  那张卡应该给它配一条「24 小时内没有 cleanup_runs_total 增量」的告警。
- **`ForgetOtpRequestIpsTask` 在当前配置下恒处理 0 行**（挑战活不到 30 天）。
  这是刻意的：它是 §8.2「ip_hash 30 天」这条**上限**的强制点，
  而上限与「整行什么时候删」是两条独立的承诺。把它当死代码删掉是一次合规回退 ——
  类注释与两个测试文件都逐字写了这一点。
- 新增两个生产依赖：`symfony/scheduler`、`dragonmantank/cron-expression`
  （后者是 `RecurringMessage::cron()` 的硬依赖，不装就只能用相对进程启动时刻的
  `every()`，而本卡需要的是墙钟时刻）。

## Alternatives considered

**宿主机 cron 容器跑 `app:cleanup`** —— 决定 1 的三条理由。值得注意的是这条路
**并没有被完全放弃**：`app:cleanup` 就是那个入口，运维随时能手动跑它，
而 scheduler 只是「每天自动敲一次同一条命令」。两者共用同一个 runner，
所以不存在「手动跑的和自动跑的不是一回事」这种分叉。

**每个任务一条 `RecurringMessage`** —— 可以给每条清理配不同的时刻。
没采用是因为它把「新增一条清理」从「加一个类」变成「加一个类 + 改时刻表」，
而决定 2 的全部价值就在前者。真需要错开时刻时再拆，那时 `CleanupTaskInterface`
不用动。

**`->stateful()` + Redis 缓存池**（而不是文件系统池）—— 能让错过的那次补跑。
没采用是因为它把 scheduler 的正确性绑在 §14.2 那个单容器、无 AOF 的 Redis 上，
换来的是一个我们明确不需要的保证（决定 4 的代价段）。
