# 发信域名的 DNS 配置与通道切换

> **交付任务** T-102 · **规格引用** §3.1、§3.2、§8.3、§14.4、R1
>
> 两件事写在一篇里：**开通发信域**（一次性，Q3 定了之后做）与
> **切换发信通道**（R1 触发时的处置，按分钟计）。放一起是因为第二件要用到第一件的结果 ——
> 换服务商往往连带换 DKIM selector。
>
> ⚠️ T-406 的 `esp-failover.md` 会吸收「§3 通道切换」这一节并补上对外沟通口径。
> 在那之前本文是唯一的处置依据。

---

## 现状（读之前先知道）

**Q3（邮件服务商选型）在 §17.5 里仍是开放问题。** T-102 交付的是通道，不是选型。
因此本文第 2 节的 DKIM 记录**留着占位符** —— selector 与公钥由服务商生成，
拿不到它就配不了。

**这意味着两条上线必需项还欠着**（已记在 `docs/tasks/M1.md` 的 T-102 回填块）：

1. 真实的 SPF / DKIM / DMARC 记录。
2. §3.2 的 4 次手工送达验证（Gmail / GMX / Web.de / Outlook），§15.1 的**上线必需**项。

选型的三条硬要求（§8.3，**合规底线，不可放宽**）：

| # | 要求 | 为什么不能放宽 |
|---|---|---|
| ① | 数据处理在 **EU/EEA** 内 | 收件邮箱地址与邮件内容（OTP 码、安全提醒）是个人数据。第三国传输需要额外法律基础 |
| ② | 可签 **AVV / DPA** | Art. 28 要求。签不了的服务商不能进 §8.3 的子处理者清单 |
| ③ | 支持**自定义域** SPF / DKIM | 没有它，第 2 节整节做不了，邮件几乎必进垃圾箱 |

候选：Brevo FR / Mailjet FR / Postmark EU。
**不得**用个人或消费级邮箱（Gmail / GMX 个人账户）、也**不得**用美国 SaaS 的免费层 ——
那是无 DPA 的第三国传输，属于合规硬伤，与「要不要做双活」是两回事（§3.2 的旁注写得很明确）。

---

## 1. 前置检查

- [ ] Q3 已决，AVV/DPA 已签署，服务商已列进 §8.3 的子处理者清单与隐私声明
- [ ] 拿到了服务商的 SMTP 主机 / 端口 / 用户名 / 口令
- [ ] 在服务商后台完成了「域名验证」（各家叫法不同：Domain Authentication /
      Sender Domain / Verified Domain），它会给出**本文第 2 节要填的那几条记录**
- [ ] 有 `n-cards.de` 的 DNS 管理权限

---

## 2. DNS 记录

三条记录，缺一不可。**顺序有意义**：先 SPF + DKIM，观察通过率，最后才收紧 DMARC。

### 2.1 SPF

```dns
n-cards.de.    TXT    "v=spf1 include:<服务商给的 include 域> -all"
```

- `-all`（hard fail）而不是 `~all`。一期只有一个发信通道，**没有**别的系统需要
  用这个域发信，所以「不在名单里的一律拒收」是准确的描述。
  `~all` 只会让接收方把伪造邮件放进垃圾箱而不是拒收。
- ⚠️ **一个域只能有一条 SPF 记录。** 已经有一条的话是**合并** include，不是再加一行 ——
  两条 SPF 记录的结果是 `permerror`，等于没配。
- ⚠️ SPF 有 **10 次 DNS 查询上限**。`include:` 是递归的，服务商的 include 本身可能就用掉好几次。
  超了同样是 `permerror`。用下面的检查命令确认。

### 2.2 DKIM

```dns
<selector>._domainkey.n-cards.de.    TXT    "v=DKIM1; k=rsa; p=<公钥>"
```

**`<selector>` 与 `<公钥>` 由服务商生成** —— Q3 未决，所以这里是占位符。
多数服务商给的是一条 CNAME 而不是 TXT（方便他们轮换密钥），照给的填即可：

```dns
<selector>._domainkey.n-cards.de.    CNAME    <服务商给的目标>
```

> DKIM 是三条里最重要的一条：SPF 在**转发**场景下会失效（转发方的 IP 不在名单里），
> 而 DKIM 的签名跟着邮件走。DMARC 只要 SPF / DKIM 任一对齐即通过。

### 2.3 DMARC —— 分三阶段推进，**不要一步到位**

§3.2 要求 `p=quarantine` → `reject`。中间必须有观察期，否则配错的直接后果是
**全部登录邮件被拒收**，而那是 R1（影响「致命」）。

| 阶段 | 记录 | 停留时间 | 进入下一阶段的判据 |
|---|---|---|---|
| ① 观察 | `v=DMARC1; p=none; rua=mailto:dmarc@n-cards.de; adkim=s; aspf=s` | ≥ 7 天 | 聚合报告里我们自己发的邮件 **100%** 通过 SPF **或** DKIM 对齐 |
| ② 隔离 | `v=DMARC1; p=quarantine; pct=100; rua=mailto:dmarc@n-cards.de; adkim=s; aspf=s` | ≥ 7 天 | 同上，且 §14.4 的「OTP 转化率」没有下降 |
| ③ 拒收 | `v=DMARC1; p=reject; rua=mailto:dmarc@n-cards.de; adkim=s; aspf=s` | 长期 | — |

```dns
_dmarc.n-cards.de.    TXT    "<上表对应阶段的那一串>"
```

- `adkim=s` / `aspf=s`（严格对齐）：一期没有任何子域发信，宽松对齐换不来好处。
- `rua` 收报告的地址**必须真的有人看** —— 阶段 ① 的判据全靠它。
  它可以是一个普通邮箱，不需要走本系统。
- ⚠️ **不配 `ruf`**（取证报告）。它会把**真实收件人地址**发给我们，
  等于凭空多一条个人数据流入，而 §8.2 的 ROPA 里没有它的位置。

### 2.4 验证

```bash
# 三条记录都能查到？
dig +short TXT n-cards.de | grep spf1
dig +short TXT _dmarc.n-cards.de
dig +short TXT <selector>._domainkey.n-cards.de

# SPF 的 10 次查询上限有没有超（任选其一）
#   https://www.dmarcanalyzer.com/spf/checker/
#   https://mxtoolbox.com/spf.aspx
```

---

## 3. 通道切换（R1 的处置）

### 触发条件

以下任一：

- §14.4 的 **「OTP 转化率骤降」P1 告警**（1 小时窗口 < 80% 且样本 > 20）。
  §3.2 明确写着：**这是邮件侧唯一的缓解措施，告警触发即为重启「不做双活」这个决定的信号。**
- §14.4 的 **「邮件发送失败率 > 5% 持续 10 min」P1 告警**。
- 服务商宣布故障，或发信域名被列入黑名单。

### 前置检查

先确认问题**在通道侧**，别把一次 Vault 故障当成邮件故障处置：

```bash
ssh -p 2242 deploy@<主机>
cd /opt/ncards/compose

# ① worker 起着吗？
docker compose ps worker

# ② 队列积压多少？正常深度是 0
docker compose exec -T postgres psql -U ncards -c \
  "select queue_name, count(*) from messenger_messages group by queue_name;"

# ③ 死信里是什么错？（这一条最能说明问题在哪一层）
docker compose exec -T app bin/console messenger:failed:show --max=10

# ④ worker 日志
docker compose logs --tail=100 worker
```

判读：

| 现象 | 结论 | 处置 |
|---|---|---|
| `messenger:failed:show` 里是 SMTP 认证 / 连接错误 | 通道故障 | 继续下面的切换 |
| 死信里是 `CryptoUnavailable` / Vault 相关 | **Vault 封了，不是邮件故障** | 走 [`vault-unseal.md`](vault-unseal.md)。消息还在队列里，unseal 后自动补发 |
| `queue_name = failed` 有积压但 `default` 是空的 | 故障已过去，只剩历史死信 | 见下面「重投死信」 |
| worker 容器根本没起来 | 多半是 `MAILER_DSN` 格式错（口令里的特殊字符没 percent-encode） | 修 DSN，同下 |

### 切换步骤

```bash
# 1) 改 DSN。**注意：直接改 .env 会被下一次部署覆盖**，见下面的「⚠️ 两处都要改」
sops infra/ansible/inventory/group_vars/ncards_production/secrets.sops.yaml
#    → 改 MAILER_DSN，存盘即自动重新加密
git commit -am 'ops: 切换发信通道（R1）' && git push

# 2) 让它生效。两条路，按紧急程度选：
#    a) 走部署流水线（推荐，一致性有保证）
#       push 到 main 即触发，见 deploy-and-rollback.md
#    b) 主机上就地改（**只在赶时间时用**，且事后必须补做 a）
ssh -p 2242 deploy@<主机>
cd /opt/ncards/compose
vi .env                                   # 改 MAILER_DSN 那一行
docker compose up -d --force-recreate worker
```

> ⚠️ **两处都要改。** 主机上的 `.env` 由 `env.j2` 渲染，下一次部署会**原样覆盖**它 ——
> 只改主机不改 sops 的话，问题会在下一次不相干的部署时突然复发，
> 而那时没人会联想到邮件。

### 验证

```bash
# worker 起来了、没在崩溃循环
docker compose ps worker
docker compose logs --tail=50 worker

# 队列在消化（隔十几秒跑两次，default 的计数应该在降）
docker compose exec -T postgres psql -U ncards -c \
  "select queue_name, count(*) from messenger_messages group by queue_name;"
```

然后**真的走一遍登录**：用团队自己的测试邮箱请求一次 OTP，确认收到。
指标侧看 `email_send_total{result="sent"}` 恢复增长（T-405 接上之后）。

### 重投死信

故障期间进 `email_failed` 的消息**不会**自动回来。

```bash
docker compose exec -T app bin/console messenger:failed:show --max=20

# ⚠️ 先想清楚再重投：OTP 码的有效期只有 10 分钟（§7.1）。
# 故障超过 10 分钟的话，重投那些 OTP 消息毫无意义 —— 用户早就重新请求过了，
# 而且旧 challenge 已被作废（新建 challenge 会作废该邮箱的旧 challenge）。
# 值得重投的通常只有安全提醒类（new_device_login / refresh_replay）。
docker compose exec -T app bin/console messenger:failed:retry <id> --force

# 确认无意义就直接丢弃，别让它们一直挂在那里干扰下次判读
docker compose exec -T app bin/console messenger:failed:remove <id>
```

### 失败回滚

切换本身没有「回滚」——换回旧 DSN 就是再走一遍上面的步骤。

真正的兜底是 §3.2 保留的那一条：**refresh token 90 天滑动**。
它把邮件故障的爆炸半径限制在「新设备登录 / 新注册」，存量用户不受影响。
所以即使通道短时间内修不好，**已登录的用户仍然能正常用**。
对外沟通时要说清这一点（完整口径归 T-406 的 `esp-failover.md`）。

### 升级路径

- 通道 30 分钟内切不回来 → 通知技术负责人，考虑对外公告
- 发信域名进了黑名单 → 这不是切 DSN 能解决的（新服务商用的还是这个域）。
  找技术负责人，走服务商的 delisting 流程；此时 §3.2 的「不做双活」决定应当重新评估

---

## 4. 本地怎么看信

不发到真实邮箱，用 compose 的 dev profile：

```bash
export COMPOSE_FILE=infra/compose/docker-compose.base.yml
docker compose --profile dev up -d mailpit        # UI: http://localhost:8025

# 把 infra/compose/.env 里的 MAILER_DSN 改成 smtp://mailpit:1025，然后
docker compose up -d --force-recreate worker
```

本地默认是 `null://null` —— **不发信，直接丢弃**。这是刻意的：开发机上跑
`composer test` 不该往真实邮箱发东西。

四封信 × 两种语言的渲染结果，不起栈也能看：

```bash
cd backend && vendor/bin/phpunit --filter MailTemplateRenderingTest
```
