# 每日保留期清理（scheduler 容器）

§8.2 ROPA 承诺的保留期，在生产上的执行点。交付于 T-113，设计见
[ADR-0022](../adr/0022-daily-cleanup-via-symfony-scheduler-single-replica-no-lock.md)。

**每天 04:30 Europe/Berlin**，`scheduler` 容器把一条 `RunDailyCleanup` 消息交给
`command.bus`，依次跑完所有注册的清理任务。当前有三条：

| 任务名 | 做什么 | 参数（`config/packages/ncards_cleanup.yaml`） |
|---|---|---|
| `identity.zombie_registrations` | 物删 `username IS NULL` 且超过 7 天的 `users`（§5.2）。`devices` / `sessions` 靠库层 `ON DELETE CASCADE` 跟着消失 | `zombie_registration_days: 7` |
| `identity.dead_otp_challenges` | 删掉已死（已消费或已过期）满 24 小时的 `otp_challenges` 整行。**这张表每行都带 Vault 加密的收件邮箱**，所以这条是 §8.2「认证」那一行的保留期本身 | `otp_challenge_grace_hours: 24` |
| `identity.otp_request_ips` | 把 30 天前的 `request_ip_hash` 置空（**不删行**）。⚠️ 当前配置下**恒 0 行**，见下方「为什么有一条任务永远是 0 行」 | `request_ip_hash_days: 30`、`request_ip_batch_size: 1000` |

---

## 触发条件

需要人介入的情况只有三种：

1. **`cleanup_errors_total{task}` 有增量**，或日志里出现 `cleanup task failed`。
2. **`cleanup_runs_total` 超过 24 小时没有增量** —— scheduler 没在跑。
   ⚠️ 这是**唯一**能发现「清理停了」的信号：它没有任何用户可见的症状，
   而每停一天，§8.2 的保留期就多违约一天。告警归 T-405。
3. **scheduler 容器停机跨过了 04:30**（部署、主机重启、Vault 长时间封印后的排查）。
   那一天的清理**不会补跑**（ADR-0022 决定 4）—— 第二天那一趟会把该删的一并删掉，
   所以通常**什么也不用做**；只有在「必须今天就把数据删掉」时才手动补一次。

---

## 前置检查

```bash
cd /opt/ncards            # 生产主机上的栈目录，见 staging-first-boot.md
export COMPOSE_FILE=infra/compose/docker-compose.base.yml:infra/compose/docker-compose.prod.yml

# 1) 容器在不在
docker compose ps scheduler
#    期望：Up，且**没有** (unhealthy) —— 它不起 HTTP 服务，健康检查是显式关掉的

# 2) 时刻表是什么，下一次什么时候
docker compose exec app bin/console debug:scheduler
#    期望：一条 `30 4 * * *`，Provider 是 RunDailyCleanup，Next Run 带 +02:00/+01:00

# 3) 最近有没有报错
docker compose logs --since 48h scheduler | grep -i 'cleanup task failed'
```

---

## 步骤

### A. 手动跑一趟（补一次错过的清理）

```bash
docker compose exec app bin/console app:cleanup
```

输出每个任务的行数与耗时，全成功时退出码 0：

```
   ✓ identity.otp_request_ips                0 行  40 ms
   ✓ identity.dead_otp_challenges           11 行  1 ms
   ✓ identity.zombie_registrations           0 行  4 ms

 [OK] 3 个清理任务，共处理 11 行。
```

> 走 `app` 而不是 `scheduler` 容器：两者同镜像同配置，而 `app` 一定在跑。
> 用的是与自动触发**完全相同**的 runner，所以不存在「手动跑的和自动跑的不是一回事」。

### B. 只跑某一条

```bash
docker compose exec app bin/console app:cleanup --task=identity.dead_otp_challenges
```

打错名字会**失败**并列出可选值 —— 不会静默成功。这是刻意的：一条绿色的
「跑了 0 个任务」会让人以为清理跑过了。

### C. 单独重启 scheduler

```bash
docker compose restart scheduler
```

⚠️ **不影响 worker**（两个独立服务，互不 `depends_on`）。重启后时刻表从当下重算，
所以重启本身**不会**补跑今天错过的那次 —— 要补就跑步骤 A。

### D. 某个任务一直失败

失败**不会**拖垮其余任务（runner 逐个隔离），所以先确认影响面：

```bash
docker compose logs --since 48h scheduler | grep -A5 'cleanup task failed'
```

已知且唯一预料得到的失败：**`identity.zombie_registrations` 撞上
`cards.owner_id` 的 `ON DELETE RESTRICT`**。它意味着有一个 `username IS NULL`
的用户持有卡 —— 按 ADR-0018 的 onboarding 拦截器这**不可能**发生，所以这是一个
真正的不变量破损，不是运维问题。

```bash
# 先看有没有、有几个
docker compose exec postgres psql -U ncards -d ncards -c \
  "SELECT u.id, u.created_at, count(c.id) AS cards
     FROM users u JOIN cards c ON c.owner_id = u.id
    WHERE u.username IS NULL
    GROUP BY u.id, u.created_at;"
```

**不要手动删那些行**，也不要改清理任务去绕过它。把查询结果连同日志开 issue ——
这条路径能出现，说明拦截器的白名单或建卡端点的鉴权出了问题，那才是要修的东西。
在修好之前，另外两个任务照常工作，§8.2 里最要紧的那条（`otp_challenges` 的
加密邮箱）不受影响。

---

## 验证

```bash
# 1) 手动跑一趟应当退出码 0
docker compose exec app bin/console app:cleanup; echo "exit=$?"

# 2) 该删的真的没了（以 otp_challenges 为例：不该有死亡超过 24 小时的行）
docker compose exec postgres psql -U ncards -d ncards -tAc \
  "SELECT count(*) FROM otp_challenges
    WHERE expires_at < now() - interval '24 hours'
       OR (consumed_at IS NOT NULL AND consumed_at < now() - interval '24 hours');"
#    期望：0

# 3) 不该删的还在（活着的挑战一条都不许少）
docker compose exec postgres psql -U ncards -d ncards -tAc \
  "SELECT count(*) FROM otp_challenges WHERE consumed_at IS NULL AND expires_at > now();"
#    期望：与当下在途的登录数相当，**不是 0**

# 4) 下一次自动触发的时刻仍然正确
docker compose exec app bin/console debug:scheduler
```

---

## 失败回滚

清理是**不可逆**的（物删）。所以这里没有「回滚」，只有「停下来」：

```bash
docker compose stop scheduler      # restart: unless-stopped 下会保持停止
```

停掉是安全的：数据只是留得久一点，§8.2 的保留期是「不超过 N 天」，
晚删仍然满足，漏删才不满足。**但别忘了它是停着的** —— 没有任何告警会提醒你。
修好之后 `docker compose start scheduler`，并跑一次步骤 A 把积压的清掉。

如果怀疑某条任务删错了东西，恢复路径是 `restore-from-backup.md`（T-406），
且必须先停 scheduler 再恢复 —— 否则恢复出来的行会在下一个 04:30 被再删一次。

---

## 为什么有一条任务永远是 0 行

`identity.otp_request_ips` 在当前配置下**恒处理 0 行**，这是正确的，不是故障：
挑战 10 分钟过期、死后 24 小时整行就被 `identity.dead_otp_challenges` 删了，
活不到 30 天。

它存在是因为 §8.2 对 `ip_hash` 写的是一条**独立的 30 天上限**，而上限与
「整行什么时候删」是两条不同的承诺。今天前者被后者顺带满足了 —— 但那是巧合，
而巧合不是执行点。把 `otp_challenge_grace_hours` 调到 30 天以上（一个完全合理的
排障诉求），这条任务会自动开始干活，而没有它的话上限就静默失效了。

⚠️ **别因为「它没用」把它删掉。** 类注释与两个测试文件都逐字写了这一点。

---

## 升级路径

- 不变量破损（步骤 D 的 RESTRICT 情形）→ 后端负责人，开 issue，**不要手动改数据**
- 清理停了超过 72 小时 → 后端负责人（开始影响 §8.2 的合规陈述）
- 怀疑删错数据 → 立刻 `docker compose stop scheduler`，然后走
  `restore-from-backup.md`，并同步后端负责人
