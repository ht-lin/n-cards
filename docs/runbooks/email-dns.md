# 发信域名的 DNS 配置与通道切换

> **交付任务** T-102 · **规格引用** §3.1、§3.2、§8.3、§14.4、R1
>
> 三件事写在一篇里：**开通发信域**（一次性）、**切换发信通道**（R1 触发时的处置，
> 按分钟计）、以及**配额天花板的判读**（决定什么时候该换掉这个通道）。
> 放一起是因为后两件都要用到第一件的结果 —— 换通道往往连带换 DKIM selector。
>
> ⚠️ T-406 的 `esp-failover.md` 会吸收「§3 通道切换」这一节并补上对外沟通口径。
> 在那之前本文是唯一的处置依据。

---

## 现状（读之前先知道）

**Q3 已决（2026-09-05，[ADR-0013](../adr/0013-mail-via-domain-mailbox.md)）：
发信走 `n-cards.de` 自己的域名邮箱，托管方 dogado GmbH（德国多特蒙德），
**SMTPS submission（465 / implicit TLS，面板给的端口）**。不采购专业 ESP。**

`MAIL_PROVIDER=dogado`。这不是对 §3.2 的偏离 —— §3.2 在 v1.1 里已经把
「专业 ESP 强制」撤销成「**普通商业邮箱服务**，仅要求处理地在 EU/EEA」，
本次只是把那一档的取值填实。三条硬要求（§8.3，**合规底线，不可放宽**）逐条核对：

| # | 要求 | 为什么不能放宽 | dogado 域名邮箱 |
|---|---|---|---|
| ① | 数据处理在 **EU/EEA** 内 | 收件邮箱地址与邮件内容（OTP 码、安全提醒）是个人数据。第三国传输需要额外法律基础 | ✅ 德国 |
| ② | 可签 **AVV / DPA** | Art. 28 要求。签不了的服务商不能进 §8.3 的子处理者清单 | ⚠️ **可签，但尚未签** |
| ③ | 支持**自定义域** SPF / DKIM | 没有它，第 2 节整节做不了，邮件几乎必进垃圾箱 | SPF ✅ **已自动写入** / DKIM ✅ **公钥已在位**（selector `cloudpit`），待实发验证 `d=` 对齐 |

> 判据是上面这三条，**不是「是不是 ESP」**。§8.3 禁的是个人 / 消费级免费邮箱账户
> （Gmail、GMX 个人账户）与美国 SaaS 免费层，理由是「无 AVV 的第三国传输」；
> 一份有合同、有 AVV、处理地在德国的商业托管邮箱三条全中，是两回事。

**2026-09-06 实测的 zone 现状**（`dig` 出来的，不是面板抄的；DNS 会变，重做本文前先复查一遍）：

```
SPF    n-cards.de          TXT    "v=spf1 a mx include:secure-mailgate.com ~all"   ← 已自动写入，但是 ~all
DKIM   cloudpit._domainkey TXT    "v=DKIM1; k=rsa; p=MIGf..."                      ← 1024 位 RSA，已在位
DMARC  _dmarc.n-cards.de   —      不存在                                           ← §2.3 阶段 ① 尚未开始
MX     n-cards.de          MX     10 mx03/mx04.secure-mailgate.com                 ← 邮件托管是活的
```

**还欠着的（已记在 `docs/tasks/M1.md` 的 T-102 回填块）：**

1. ~~确认 dogado 是否支持 DKIM 签名并取得 selector~~ —— **已确认**：selector `cloudpit`，
   公钥已发布在我们自己的 zone 里。§2.2 那个 ⛔ 停止条件不成立。**但仍要实发一封验证
   `d=n-cards.de`**（见 §2.4），在那之前不要推进 §2.3 的阶段 ②。
2. 与 dogado **签署 AVV** 并归档（归 T-450）。
3. 查实**发信配额**，回来校准熔断阈值 —— 见 §4。**这条现在是最大的未知。**
4. 收紧 SPF 到 `-all`（§2.1）、发布 DMARC 阶段 ①（§2.3）—— 两条都可以现在就做。
5. §3.2 的 4 次手工送达验证（Gmail / GMX / Web.de / Outlook），§15.1 的**上线必需**项。
6. 确认**认证账号能否用别名做 `From:`** —— 见 §1，影响发信地址的最终取值。

⚠️ **域名邮箱与专业 ESP 的三个差别，会贯穿本文后面每一节**，先记住：

- **有发信配额**，超了会被托管商限流或**停用发信账号** —— 那是 R1 的直接触发。
- **出口 IP 是共享的**，声誉不由我们控制。所以 SPF/DKIM/DMARC 从「重要」升格为
  **唯一可控的送达手段**，`p=reject` 不能省。
- **没有 bounce / 投诉反馈回路，没有抑制名单，没有投递 webhook。**
  退信只会以 SMTP 错误（→ `email_failed` 队列）或一封退信信（→ `no-reply@` 收件箱）
  出现。§14.4 的「OTP 转化率骤降」P1 告警因此不只是唯一的代理指标，
  是**唯一的送达信号**。

---

## 1. 前置检查

- [ ] AVV 已签署并归档，dogado 已列进 §8.3 的子处理者清单与隐私声明
- [ ] 在 dogado 面板里**建好了发信邮箱**并拿到口令（地址取值见下面的「一个账号」）
- [ ] 从 dogado 面板 / 帮助中心抄下 **SMTP 主机名**（**不要凭印象猜**，见下）。
      端口、SPF include 与 DKIM selector 都已经拿到了，见「现状」段 ——
      端口是 **465 / SSL-TLS**（面板 2026-09-06），因此 DSN 的 scheme 必须是
      `smtps://` 而不是 `smtp://`：587 那条路在服务器不通告 STARTTLS 时会
      **静默退回明文 AUTH**（`require_tls` 默认 false），465 没有这条降级路径
- [ ] 有 `n-cards.de` 的 DNS 管理权限（DNS 可能也在 dogado，也可能在别处 —— 先确认）
- [ ] **测过认证账号能否用别名做 `From:`**（见下面的「一个账号 + 只收别名」）

> ⚠️ **SMTP 主机不要猜。** 写错的症状是 worker 起不来（好查）。相比之下
> **SPF include 写错的症状是邮件照发不误、但对齐失败**，只在 DMARC 聚合报告里
> 才看得出来，而那要等到 §2.3 的阶段 ① 观察窗口结束 —— 一周之后才发现配错，
> 是本文最贵的一种错误。以面板给出的字面值为准。

### 一个账号 + 只收别名（这个套餐的硬约束）

dogado 的这个域名套餐只给 **1 个邮箱账号**，其余地址只能是**别名**，
而别名**没有独立的 SMTP 凭据**。后果分两层，别混为一谈：

- **收信侧不受影响。** `info@` / `kontakt@` / `datenschutz@` / `impressum@` /
  `dmarc@` / `ops@` / `abuse@` / `postmaster@` 想建多少建多少，不占配额、不占账号数。
  Impressum 列一个别名地址完全合规 —— §5 DDG 要求的是地址**真的可达**，不要求它能发信。
- **发信侧只有一个地址可用**，而它要同时服务两种互相冲突的用途：
  事务邮件（OTP / Magic Link / 安全提醒，量级是全部发信的 99.9%）
  与人工回信（GDPR 请求、Impressum 询问，一年个位数）。

⚠️ **但「别名不能发信」很可能只是指「别名没有自己的 SMTP 凭据」，不等于
「认证为主账号时不能用别名做 `From:`」。** 这是两个不同的限制，很多共享托管只有前者。
代码这一侧本来就把两者分开了 —— `MAILER_DSN`（认证凭据）与 `MAIL_FROM_ADDRESS`
（信头 `From:`，见 `infra/compose/docker-compose.base.yml`）是两个独立变量。
**若托管商接受二者不同，发信地址叫什么就无所谓了**，这个约束基本消失。
测法见 §2.4 —— 和 DKIM 的验证是同一封信。

若测下来确实锁死（`From:` 必须等于账号地址），则发信地址的取值是一道取舍，
**倾向保留 `no-reply@`**：事务邮件占了全部发信量的 99.9% 且在 R1 的关键路径上，
而 `info@` 是反垃圾启发式里典型的群发地址模式；这个项目在送达上**没有任何余量可花**
（共享出口 IP、无 bounce 回路、无抑制名单、无投递 webhook，唯一信号是事后告警）。
人工回信从 `no-reply@` 发出、带 `Reply-To:` 指向别名即可 —— 署名难看，但一年几封。
⛔ **不要**用「别名转发到创始人的 Gmail，从那边回信」来绕：那会把用户往来邮件
送进 Google，正是 §8.3 禁止、也正是 ADR-0013 选 dogado 想避免的第三国传输。
转发目标必须同样在 EU/EEA 且有 AVV。决定归 [ADR-0013](../adr/0013-mail-via-domain-mailbox.md) 未解决项 f。

---

## 2. DNS 记录

三条记录，缺一不可。**顺序有意义**：先 SPF + DKIM，观察通过率，最后才收紧 DMARC。

### 2.1 SPF

**dogado 已经自动写入了一条**，2026-09-06 实测：

```dns
n-cards.de.    TXT    "v=spf1 a mx include:secure-mailgate.com ~all"     ← 现状
```

`secure-mailgate.com` 就是权威的 include 值（它自己的 SPF 里含 `a:mailcloud.dogado.de`，
确属 dogado；MX 也指向 `mx03/mx04.secure-mailgate.com`）。**照用，不要改成别的**——
托管商换机器、换 IP 段时靠改这条 include 平滑过渡，写死 IP 会在某天静默失效。

**要做的唯一一处改动是把 `~all` 收紧成 `-all`：**

```dns
n-cards.de.    TXT    "v=spf1 a mx include:secure-mailgate.com -all"     ← 目标
```

- `-all`（hard fail）而不是 `~all`。一期只有一个发信通道，**没有**别的系统需要
  用这个域发信，所以「不在名单里的一律拒收」是准确的描述。
  `~all` 只会让接收方把伪造邮件放进垃圾箱而不是拒收。
- ⚠️ **这是「已经有一条」的情形 —— 改那一条，不要新增。**
  一个域只能有一条 SPF 记录，两条的结果是 `permerror`，等于没配。
- `a` 与 `mx` 两个机制是 dogado 的通用默认值（授权本域 A 记录与 MX 主机发信）。
  一期用不到，但**留着无害**且省一次改动风险，暂不动它们。
- ✅ **10 次 DNS 查询上限已核过**：`a`(1) + `mx`(1) + `include`(1) +
  其内 `a:mailcloud.dogado.de`(1) ≈ **4 次**，离上限很远。往后若再加 include 记得重算。

### 2.2 DKIM

**✅ dogado 已经自动配好了，无需在 DNS 侧做任何事。** 2026-09-06 实测：

```dns
cloudpit._domainkey.n-cards.de.    1200    TXT    "v=DKIM1; k=rsa; p=MIGfMA0GCSqGSIb3DQEBAQUAA4GNADCBiQKBgQCc+Ciw...IDAQAB"
```

**selector = `cloudpit`。** 签名发生在 dogado 的 MTA 上，我们这一侧只负责公钥在 DNS 里。

⚠️ 上面的 `p=` **是截断过的，不要从本文复制**。权威值以 zone 里的实际记录为准：
`dig +short TXT cloudpit._domainkey.n-cards.de`。

> DKIM 是三条里最重要的一条：SPF 在**转发**场景下会失效（转发方的 IP 不在名单里），
> 而 DKIM 的签名跟着邮件走。DMARC 只要 SPF / DKIM 任一对齐即通过。

**为什么这条记录同时是「`d=` 大概率会对齐」的证据。** DKIM 验证方是拿签名里的
`d=` + `s=` 拼出查询路径的。如果 dogado 用自己的域签名（`d=dogado.de`），验证方会去查
`cloudpit._domainkey.dogado.de`，**我们 zone 里这条记录永远不会被读到** —— 那把它
发布在这里就毫无意义。它出现在 `n-cards.de` 自己的 zone 里，本身就指向 `d=n-cards.de`，
也就是 §2.4 末尾那个坑不太可能发生。**但这是证据，不是证明 —— 仍须实发一封验证。**

⚠️ **公钥在 DNS 里 ≠ 我们的信真的被签名。** 有些托管商只签 webmail 发出的邮件，
不签 SMTP submission 的。这一条只有 §2.4 的实发验证能回答。

#### 两个要记账的小问题

- **密钥是 1024 位 RSA**（`MIGfMA0...ADCBiQKBgQC` 这个前缀就是 1024 位的特征；
  2048 位会是 `MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8A...`）。RFC 8301 把 1024 定为下限，
  今天 Gmail / Outlook / GMX / Web.de 都能正常验证，**不是阻塞项**。
  可以顺便问 dogado 能否轮换到 2048，但不必为它停下。
- ⚠️ **它是 TXT 而不是 CNAME。** 本文原先预期的是 CNAME —— 好处是托管商轮换密钥时
  我们无需动手。静态 TXT 意味着 **dogado 一旦换密钥，这条记录必须跟着改**。
  DNS 若也在 dogado 由他们自动维护尚可；**一旦 DNS 迁到别处，这就是一颗静默哑弹**：
  他们换了签名密钥、我们的公钥没换，邮件照发不误但 DKIM 全部验证失败，
  症状只会出现在 DMARC 聚合报告里，而那要等一周。
  **迁移 DNS 时必须把这条列进检查项。**

#### 若哪天 DKIM 没了（记录被删、轮换后失配、换托管商）

⛔ **停下来，不要继续往 §2.3 的阶段 ③ 走。** 后果链条是：没有 DKIM → 只能靠 SPF 对齐 →
转发场景（用户把 OTP 信转到另一个邮箱、企业邮件网关中继）必然失败 →
`p=reject` 一旦推到阶段 ③，那些邮件会被**拒收**而不是进垃圾箱。

两条出路，二选一，都要记录决定：

1. **退回 `p=quarantine`（阶段 ②）不再推进**，把「无 DKIM」记成已接受风险。
   代价是伪造 `n-cards.de` 的钓鱼邮件只会进垃圾箱而不被拒收。
2. **重启 Q3**，换回 ADR-0013 里保留的升级路径（专业 ESP，面板里现成的 DKIM）。

⚠️ 别用「自己在 DNS 里放一条 DKIM 公钥」来绕 —— 私钥在 dogado 的 MTA 上，
我们签不了名。发布一条没有对应签名的 DKIM 记录比不发布更糟：
它会让接收方期待一个签名却收不到。

### 2.3 DMARC —— 分三阶段推进，**不要一步到位**

§3.2 要求 `p=quarantine` → `reject`。中间必须有观察期，否则配错的直接后果是
**全部登录邮件被拒收**，而那是 R1（影响「致命」）。

**现状（2026-09-06 实测）：`_dmarc.n-cards.de` 不存在，阶段 ① 尚未开始。**
SPF 与 DKIM 都已在位，所以阶段 ① 现在就可以发布 —— 而且**应该尽早发布**：
它是唯一能大规模验证对齐的手段，7 天观察窗口是后面两个阶段的前置。
发布前先建好 `dmarc@n-cards.de` 这个**收信别名**（不受「别名不能发信」约束）。

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
# 三条记录都能查到？（DKIM 的 selector 是 cloudpit）
dig +short TXT n-cards.de | grep spf1
dig +short TXT _dmarc.n-cards.de
dig +short TXT cloudpit._domainkey.n-cards.de

# SPF 的 10 次查询上限有没有超（已核过 ≈ 4 次，加 include 后重算）
#   https://www.dmarcanalyzer.com/spf/checker/
#   https://mxtoolbox.com/spf.aspx
```

**记录查得到 ≠ 签名真的生效。** DKIM 尤其如此 —— 公钥在 DNS 里，不代表 dogado 的
MTA 真的在给我们的邮件签名（有些托管商只签 webmail 发出的信）。必须**实发一封**。

**一封信同时回答三个问题**：DKIM 是否真的签、`d=` 是否对齐、以及 §1 那个
「认证账号能否用别名做 `From:`」。所以特意把 `--from` 指成与 SMTP 账号**不同**的地址：

```bash
# 在 staging 上发一封（收件人用团队自己的邮箱，Gmail 最方便看认证结果）
docker compose exec -T app bin/console mailer:test \
  <团队测试邮箱> --from=no-reply@n-cards.de
```

在收件端打开「显示原始邮件 / Original anzeigen」，确认四行：

```
DKIM-Signature: v=1; a=rsa-sha256; d=n-cards.de; s=cloudpit; ...
                                   ^^^^^^^^^^^^ 必须是 n-cards.de 本身，见下
Authentication-Results: ...; dkim=pass header.d=n-cards.de; spf=pass; dmarc=pass
Return-Path: <no-reply@n-cards.de>
From: no-reply@n-cards.de
      ^^^^^^^^^^^^^^^^^^^ 若被静默改写成 SMTP 账号地址，见下面第二条
```

⚠️ **`d=` 那一行**：托管商有时用**自己的域**签名（`d=dogado.de` 之类）。那样 DKIM 本身
`pass`，但 §2.3 的 `adkim=s`（严格对齐）会判定不对齐，于是 DMARC 只能靠 SPF 撑着 ——
等于回到「没有 DKIM」的处境，适用 §2.2 末尾那两条出路。
（公钥发布在我们自己的 zone 里，说明这大概率不会发生，但必须眼见为实。）

⚠️ **`From:` 那一行有三种结果，含义完全不同**：

| 结果 | 含义 | 动作 |
|---|---|---|
| `From:` 就是传入的值 | 认证账号可以用任意本域地址发信 | §1 那个约束消失，发信地址随便取 |
| SMTP 直接拒收（`550 Sender address rejected` 之类） | 锁死，`From:` 必须等于账号地址 | 按 §1 的取舍定发信地址，记回 ADR-0013 未解决项 f |
| 发出去了，但 `From:` 被**改写**成账号地址 | 最坏的一种 —— 静默改写 | 同上，且**必须**确认 `MAIL_FROM_ADDRESS` 与账号一致，否则线上信头长期与配置不符 |

---

## 3. 通道切换（R1 的处置）

> ⚠️ **先看清这一节现在的前提。** Q3 定案为域名邮箱（[ADR-0013](../adr/0013-mail-via-domain-mailbox.md)）
> 之后，「切换」的成本分成了截然不同的两档：
>
> | 情形 | 处置 | 耗时 |
> |---|---|---|
> | dogado 侧的**临时**问题（认证失败、SMTP 暂时不可达、口令改了） | 改 `MAILER_DSN` 重连，下面的步骤原样适用 | 按分钟 |
> | dogado 的邮箱被**停用**（超配额）、或发信域 / 共享出口 IP 被拉黑 | **没有第二条通道可切** —— 得先去开一个 ESP 账号、验证域名、配新的 DKIM、等 DNS 生效 | **按小时到按天** |
>
> 第二档才是 §3.2 真正接受下来的那个风险。真的落到那一档时，
> 撑住业务的不是本节的切换步骤，是「失败回滚」那一段里的 **90 天滑动 refresh** ——
> 存量用户不受影响，受影响的只有新设备登录与新注册。**对外沟通要按这个口径说。**

### 触发条件

以下任一：

- §14.4 的 **「OTP 转化率骤降」P1 告警**（1 小时窗口 < 80% 且样本 > 20）。
  §3.2 明确写着：**这是邮件侧唯一的缓解措施，告警触发即为重启「不做双活」这个决定的信号。**
- §14.4 的 **「邮件发送失败率 > 5% 持续 10 min」P1 告警**。
- 服务商宣布故障，或发信域名被列入黑名单。
- **dogado 因超发信配额限流或停用了发信账号**（域名邮箱特有，见 §4）。
  症状是死信里成片的 SMTP 4xx/5xx 限额类错误，而不是认证错误 ——
  这一条**不能靠改 DSN 解决**，先看 §4。

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
| 死信里是 SMTP **限额 / 速率**类错误（`4.7.x`、`too many messages`、`quota exceeded` 之类） | **超了 dogado 的发信配额**，不是通道故障 | 走 [§4](#4-发信配额这个通道的天花板)。改 DSN 没用 —— 换个账号发同样超 |

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
- **共享出口 IP 进了黑名单**（域名邮箱特有）→ 域名是干净的，脏的是 dogado 的
  出口 IP，而**我们对它没有任何干预手段**（同池的其他租户发了垃圾邮件）。
  delisting 只能由 dogado 去做。这一条直接触发 [ADR-0013](../adr/0013-mail-via-domain-mailbox.md)
  的升级路径：按 §3.2 原方案补做专业 ESP（2 人日，抽象层已就位），
  届时会拿到专用发信 IP。

---

## 4. 发信配额：这个通道的天花板

专业 ESP 按量计费、配额随套餐走；**域名邮箱有硬配额，而且超了是停用账号，不是计费**。
这是 [ADR-0013](../adr/0013-mail-via-domain-mailbox.md) 记下的头号代价，
也是 R1 在 Q3 定案后新增的一条触发路径。

### 现在的阈值是猜的

`backend/config/packages/ncards_mail.yaml` 里那两个数字（**warn 200 / breaker 500**）
是按「典型域名邮箱配额」保守估的，**不是 dogado 的实测值**。
它们的方向是刻意偏保守的：宁可熔断误伤安全提醒（Advisory 邮件被丢弃、记
`result="suppressed"`，OTP 与 Magic Link 照发），也不能让发信账号被托管商停掉。

**待办**：去 dogado 面板 / 帮助中心查实**每小时**与**每天**的配额，然后：

```
daily_breaker_threshold  ≈  实测日配额 × 0.8      # 留出重投与 staging 的余量
daily_warn_threshold     ≈  daily_breaker × 0.4   # 越过它是「今天不对劲」，不是「要出事」
```

⚠️ 有**每小时**配额时，日阈值可能根本不是约束点 —— 一次注册高峰会在一小时内
打满小时配额而日累计还很低。真是那样的话，把发现记回 ADR-0013 的「未解决项」，
熔断器需要加一个小时维度的计数键（`MailVolumeCounterInterface` 现在只有日粒度）。

### 判据：什么时候该换掉这个通道

```bash
# 当日累计（T-405 接上 Prometheus 之前，直接读 Redis 里的计数键）
docker compose exec -T redis redis-cli --scan --pattern 'mail:volume:*'
```

| 观察 | 结论 | 动作 |
|---|---|---|
| 日累计 < 实测配额 30% | 健康 | — |
| 日累计持续 > 实测配额 **50%** | **触发 §3.2 的重启信号** | 启动 ESP 选型，2 人日。别等打满 |
| 出现限额类死信 | 已经打满过 | 立刻上调紧迫度；短期先确认 breaker 阈值确实低于真实配额 |

> §9.3 的容量目标（12 个月 5 万注册）推算出的日发信量约 **1100 封**，
> **已经越过典型域名邮箱的配额量级**。也就是说上面第二行不是「如果」，是「什么时候」——
> 这个通道能撑住上线，撑不住 §9.3 的 12 个月目标。这不是缺陷，是一个
> 有明确判据的已知天花板；ADR-0013 选它换来的是零第三国传输、少一家子处理者与零采购成本。

### staging 会吃生产的配额

`ncards_staging/main.yml` 里那段「staging 用生产发信域」的理由仍然成立
（要测的就是生产那套 SPF/DKIM 与域名声誉），但现在两个环境**共用同一个邮箱账号，
也就共用同一份配额**。staging 日常量级是个位数，可接受；
**别在 staging 上跑发信压测** —— 那会直接吃掉生产额度，症状是生产侧 OTP 发不出去。
要压测就把 staging 的 `MAILER_DSN` 临时指向 Mailpit 或 `null://null`。

---

## 5. 本地怎么看信

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
