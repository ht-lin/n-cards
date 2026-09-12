# N-Cards — 技术规格书 v1.1

> **品牌名**：N-Cards（Q1 已定案，见 [ADR-0002](adr/0002-brand-name-and-domain.md)）。命名分两层，判据是谁在读：人读的地方写 `N-Cards`（显示文本、域名 `n-cards.de`、法律文件标题），机器读的地方写 `ncards`（包名 `de.ncards`、Gradle 插件 id `ncards.*`、Vault key `ncards-*`、资源名 `Theme.NCards`）。
> **文档状态**：一期（Android + 后端）开发基线，已通过 Staff Engineer / CTO 复审
> **目标读者**：未参与前期讨论的高级工程师。读完本文应能独立启动开发，无需口头补充。
> **最后更新**：2026-08-24

### 变更记录

| 版本 | 日期 | 变更 |
|---|---|---|
| v1.0 | 2026-08-24 | 初版基线 |
| v1.1 | 2026-08-24 | 产品决策收敛（见下表），**范围整体收窄，工期风险下降** |

**v1.1 的 11 项决策变更**（全部为产品侧拍板，已并入相应章节）

| # | 变更 | 影响章节 |
|---|---|---|
| C1 | 卡券录入新增**从图片录入**（相册选图本地识别，图片不落盘、不上传） | §1.3、§10.1、§2(ADR-13) |
| C2 | 社交**取消邀请链接与邮箱邀请**，好友添加唯一路径为 **username 精确搜索** | §1.3、§3.8、§5.2、§6.2、§7.5 |
| C3 | `users` 新增 **`username`**：唯一、不可变、注册时必设，作为好友搜索键 | §3.8、§5.2、§6.2、§17.1 |
| C4 | 好友搜索**不作输入提示**（无 typeahead / 无模糊匹配 / 无候选列表） | §3.8、§6.2、§7.2 |
| C5 | 共享角色收敛为 **owner / viewer 两种**，取消 `editor`；**仅 owner 可编辑**，共享为**单向同步** | §2(ADR-01)、§3.5、§5.2、§6.2 |
| C6 | 共享**必须**互为好友；**解除好友 / 拉黑即级联取消共享**（v1.0 的相反决策已废止） | §1.3、§5.2、§6.2 |
| C7 | 共享邀请**无须选角色**，被分享者可接受 / 拒绝 | §1.4、§6.3.3 |
| C8 | 账号删除：**取消全部共享、卡券一并硬删除，不作所有权转让**（v1.0 的转让流程已废止） | §1.4、§3.7、§8.4 |
| C9 | 邮箱使用**普通商业邮箱**；**一期暂时忽略 Email 单点故障问题**，撤销 ESP 双活等缓解措施，记为已接受风险 | §3.2、§8.3、§9.2、§14.4、R1 |
| C10 | **移除 `display_name`**，`username` 即唯一展示名与检索名 | §5.2、§6.2、§8.2、§10.3、§17.1、§17.2 |
| C11 | **成员可见性**：viewer 只能看到 owner 与自己，**看不到其他 viewer 及其数量** | §5.2、§5.4、§6.2、§17.2 |

> ⚠️ 若你读过 v1.0，请特别注意 **C5 / C6 / C8 是语义反转**（不是增量补充）：`editor` 角色、所有权转让、"解除好友不影响共享"这三处旧设计**已完全移除**，不得在实现中残留。
>
> C9 是**有意识的风险接受**：邮件仍是登录的单点故障，只是一期不为它投入工程。**唯一不可放宽的是合规底线**——邮件服务商仍必须在 EU/EEA 且签 DPA（§8.3）。

---

## 0. 如何阅读本文档

| 你是 | 建议阅读顺序 |
|---|---|
| 后端工程师 | §1 → §2 → §3 → §4.2 → §5 → §6 → §7 → §12.1 → §13 → §14 |
| Android 工程师 | §1 → §2 → §3 → §4.3 → §4.4 → §5.4 → §6 → §7.3 → §11 → §12.2 → §13 |
| 技术负责人 / PM | §1 → §2 → §3 → §9 → §15 → §16 |
| 合规 / 法务 | §1.3 → §5.3 → §7 → §8 |

**约定**：
- **MUST / 必须**：不可协商，违反即阻断合入。
- **SHOULD / 应当**：默认执行，偏离需在 PR 中说明理由。
- **MAY / 可以**：由实现者裁量。
- 所有时间戳均为 UTC，序列化为 RFC 3339（`2026-08-24T10:15:30Z`）。
- 所有标识符为 **UUIDv7**（时间有序，利于索引与客户端生成）。

---

## 1. 产品定义与范围

### 1.1 一句话定义

N-Cards 是一款面向德国及欧盟市场的移动卡券钱包：把散落在实体钱包里的会员卡、积分卡、优惠券的条码/二维码统一收纳，在收银台前用最少的操作调出屏幕，并可与家人朋友**持续共享同一张卡**。

### 1.2 目标用户与场景

**主要用户画像**

| 画像 | 特征 | 核心诉求 |
|---|---|---|
| 家庭主理人（28–50） | 持有 PAYBACK / DeutschlandCard / Lidl Plus / DM / REWE Bonus 等 5–15 张卡 | 钱包减负；把家庭共用的卡共享给伴侣 |
| 高频购物者（20–35） | 城市生活，多品牌会员 | 结账时 3 秒内调出；不想翻 App 列表 |
| 银发用户（55+） | 卡多、视力弱、不熟悉手机 | 大字号、高对比、操作路径极短 |

**决定性场景（"North Star" 场景）**

> 用户在 REWE 收银台，队伍在后面排着，手机在裤兜里。从掏出手机到收银员的扫码枪读出条码，**必须 ≤ 5 秒**，且在超市地下层无网络时**必须同样可用**。

这个场景直接推导出两个不可妥协的架构约束：

1. **离线优先**：本地数据库是 UI 的唯一真相源（§4.3）。
2. **多入口快速调出**：主屏 Widget、快捷设置磁贴、长按图标快捷方式（§10.2）。

### 1.3 一期范围（Scope）

#### ✅ IN — 一期必须交付

| 领域 | 内容 |
|---|---|
| 账号 | Email OTP 无密码登录（6 位码 + Magic Link 兜底）、**注册时设定唯一且不可变的 username**、多设备、设备管理、登出、账号删除 |
| 卡券 | **摄像头扫码录入、从图片录入（相册选图，本地识别）、手动录入**、编辑、删除、排序、置顶、搜索、颜色/首字母图标、备注文本 |
| 码制 | ML Kit 全码制识别（相机流与静态图片共用同一识别器）；ZXing 本地渲染（EAN-13/8、UPC-A/E、Code 128/39/93、ITF、Codabar、QR、Aztec、PDF417、DataMatrix） |
| 快速调出 | 主屏 Widget、全屏条码页（自动最亮 + 防锁屏 + 横屏放大）、快捷设置磁贴、App Shortcuts |
| 同步 | 增量同步引擎、多设备一致、离线队列、冲突解决 |
| 社交 | 双向确认好友关系；**添加好友的唯一路径是 username 精确搜索**；删除好友、拉黑 |
| 共享 | 一张卡分 **owner（卡主）** 与 **viewer（被分享者）**；**仅 owner 可编辑，单向同步**；共享前提是**互为好友**；被分享者可接受/拒绝分享请求、可主动退出；**解除好友或拉黑即自动取消共享** |
| 推送 | FCM 静默推送唤醒同步 + 客户端本地生成通知 |
| 合规 | GDPR 数据导出、删除、同意管理、Impressum / Datenschutzerklärung / AGB 内嵌页 |
| 语言 | 德语（默认）、英语 |
| 平台 | Android 8.0 (API 26) 及以上 |

#### ❌ OUT — 一期明确不做（写在这里是为了防止范围蔓延）

| 不做的东西 | 理由 | 何时再议 |
|---|---|---|
| iOS App | 已决定二期 | 二期 |
| 卡面图片 / 附件**存储** | 增加一整套上传、缩略图、加密对象存储管道，与 3 个月工期冲突。⚠️ 注意与"从图片录入"的区别：录入时图片**只在本机内存中解码，用完即弃**，既不落盘也不上传，因此不构成"图片功能" | 二期 |
| 好友邀请链接 / 邀请码 | 已决定取消（C2）。好友添加收敛为 username 精确搜索，无链接分发面 → 同时消灭了"垃圾邀请"与"链接被转发滥用"两类风险 | 未定 |
| 邮箱邀请（给未注册用户发邀请信） | 已决定取消（C2）。副作用：**外发邮件只剩 OTP 与安全提醒两类**，§3.1 描述的"垃圾邮件跳板"风险大幅下降 | 未定 |
| `editor` 角色（被分享者可编辑） | 已决定取消（C5）。共享语义为单向只读同步 | 二期 |
| 卡片所有权转让 | 已决定取消（C8）。owner 恒为创建者；删号时共享卡直接硬删，不转让 | 二期 |
| username 的展示/搜索型用户目录（模糊搜索、输入提示、推荐好友） | 已决定取消（C4）。任何 typeahead 都是 username 枚举面 | 未定 |
| 商家目录（logo / 模板 / 自动识别品牌） | 需要维护数据集 + 商标法务评估 | 二期 |
| 到期提醒 / 地理围栏提醒 | 地理围栏需额外 GDPR 同意 + POI 数据集 | 二期 |
| 订阅 / 内购 / 配额售卖 | 一期完全免费 | 未定 |
| 通讯录匹配找好友 | 上传通讯录是重量级 GDPR 处理行为 | 未定 |
| Web 端 | — | 未定 |
| 一次性副本分享 / 公开只读链接 | 共享语义收敛为"共享同一张卡" | 二期 |

> ⚠️ **给实现者的提醒**：任何进入 backlog 的"顺手加一下"的需求，若属于上表，一律拒绝并链接到本节。

### 1.4 核心用户旅程

```
J1 首次使用
  安装 → 语言/隐私说明 → 输入邮箱 → 收到 6 位码 → 验证
  → 【设定 username】（唯一、不可变，界面必须明示"设定后无法修改"）→ 进入空钱包
  → 引导"添加第一张卡" → 三选一录入（摄像头扫码 / 从相册图片识别 / 手动输入）
  → 自动识别码制 → 填名称/选颜色 → 保存

J1b 从图片录入
  添加卡 → "从图片选择" → 系统相册选择器（Photo Picker，无需存储权限）
  → 本地 ML Kit 解码（不上传、不落盘）
  → 识别到 1 个码 → 直接进入编辑页
  → 识别到多个码 → 让用户点选（在图片上高亮各码位置）
  → 识别失败 → 提示"未识别到条码"并直接落到手动输入页（不做无意义的重试引导）

J2 结账调出（North Star）
  主屏 Widget 点击卡图标 → 全屏条码页（屏幕自动最亮、常亮）
  → 收银员扫码 → 返回键退出，亮度自动恢复

J3 与伴侣共享（仅好友之间；无须选角色）
  【前置】双方已互为好友（Anna 在搜索框输入 Bob 的完整 username → 发送好友请求 → Bob 接受）
  Anna: 卡详情 → 共享 → 从好友列表选择 Bob → 发送分享请求（无角色选择，被分享者恒为 viewer）
  → Bob 收到静默推送 → 拉取增量 → 本地生成通知"Anna möchte eine Karte mit dir teilen"
  → Bob 选择【接受】或【拒绝】
  → 接受后 Bob 钱包出现该卡，带「共享（只读）」徽章；Bob 只能查看与调出，不能编辑
  → 此后 Anna 的任何修改单向下发给 Bob

J3b 共享终止（三条路径，效果相同：Bob 失去该卡）
  ① Anna 移除成员 Bob   ② Bob 主动退出共享   ③ 任一方解除好友或拉黑（级联取消双方全部共享）
  → Bob 收到成员墓碑 → 本地物理删除该卡

J4 离线新增
  地铁上无网 → 新增卡 → 本地立即可见（状态：待同步）
  → 恢复网络 → WorkManager 推送 → 服务端确认 → 徽章消失

J5 删号
  设置 → 删除账号 → 展示影响清单："你拥有的 N 张卡将被删除，其中 M 张正共享给 K 位好友，
                     这些好友将同时失去它们；卡券不会转让给任何人" → 确认
  → 30 天宽限期（期间任意登录即取消）→ 到期硬删除（含全部共享卡）
```

### 1.5 术语表

| 术语 | 含义 |
|---|---|
| **Card（卡）** | 一个可被调出的条码实体。包含展示元数据 + 加密码值。 |
| **Payload（码值）** | 条码承载的字符串，如会员号 `1234567890128`。最敏感字段。 |
| **Member（成员）** | 对某张卡有访问权的用户，角色为 **owner 或 viewer**（一期无第三种角色）。 |
| **Owner（卡主）** | 每张卡恰有一个，**恒为创建者，不可转让**。唯一可编辑与删卡、可管理成员。 |
| **Viewer（被分享者）** | 只读成员。可查看/调出卡、可设置**自己的**排序与置顶、可主动退出；不可修改卡的任何内容。 |
| **Username** | 用户的唯一、不可变公开标识（如 `anna_b`）。**添加好友的唯一检索键，同时是唯一的展示名**——v1.1 已无 `display_name`（C10）。与 email 一样全局唯一。 |
| **Friendship（好友）** | 双向确认的用户关系，是发起共享的前置条件，也是**共享持续存在的前提**——解除即级联取消共享。 |
| **Revision（修订号）** | 单张卡的乐观锁版本号，服务端每次成功写入 +1。 |
| **Seq（同步序号）** | 全局单调递增的变更日志序号，同步游标基于它。 |
| **Tombstone（墓碑）** | 表示"删除/失去访问"的同步记录，客户端据此清理本地数据。 |
| **DEK / KEK** | 数据加密密钥 / 密钥加密密钥（信封加密，§5.3）。 |
| **ESP** | Email Service Provider（发信服务商）。 |
| **ROPA** | Records of Processing Activities，GDPR Art.30 处理活动记录。 |

---

## 2. 架构决策记录（ADR 摘要）

每条决策的完整背景见对应章节。**修改这些决策需要走 ADR 流程（§13.7）。**

| # | 决策 | 选择 | 主要理由 | 详见 |
|---|---|---|---|---|
| ADR-01 | 共享语义 | 共享同一张卡，**单向同步**：只有 owner 能修改，被分享者（viewer）只能查看 | 家庭共用会员卡是核心差异点，副本分享无法满足"改了名对方也看到"；但双向可写会带来多人并发冲突、成员越权与"谁改坏了"的纠纷，收益远小于成本。**单向同步使冲突面收缩到 owner 自己的多设备之间** | §5.2、§3.5 |
| ADR-02 | 码值加密模型 | 服务端信封加密（Vault Transit） | E2EE 与 Email OTP 恢复模型冲突（换机即数据全丢）；服务端加密可防 DB/备份泄露且共享实现简单 | §5.3 |
| ADR-03 | 登录方式 | Email OTP 唯一 | 零电信成本、无 SMS 反欺诈负担、德国用户接受度高 | §7.1 |
| ADR-04 | 实时通道 | FCM data-only 唤醒 + HTTP 增量拉取 | Android 后台长连不可靠；单一同步代码路径同时服务在线与离线 | §4.4 |
| ADR-05 | 后端架构 | 模块化单体 + 手写 REST | 移动端需要聚合接口与自定义同步语义，API Platform 的 CRUD 惯性会阻碍；Deptrac 强制模块边界 | §4.2 |
| ADR-06 | 客户端架构 | 离线优先，Room 为唯一真相源 | North Star 场景要求无网可用 | §4.3 |
| ADR-07 | 基础设施 | Hetzner（德国）+ Docker Compose | 数据 100% 留在德国，可作营销点；成本极低；无 US CLOUD Act 叙事负担 | §14.1 |
| ADR-08 | 好友模型 | 显式双向确认好友；**唯一添加路径为 username 精确搜索**（无邀请链接、无邮箱邀请、无输入提示） | 共享是持续授权，必须有明确的相互同意；username 需线下/其他渠道交换，天然限制了陌生人骚扰面，且不产生任何可被转发滥用的链接 | §3.8、§5.2 |
| ADR-13 | 卡券图片录入 | 相册选图 → **本机 ML Kit 解码 → 立即丢弃图片** | 磨损/纸质券在灯光下难以对焦，而用户手机里常已有截图或照片；本地解码不引入上传管道，与 ADR-12（不做图片存储）不冲突 | §10.1 |
| ADR-14 | 好友关系与共享的耦合 | 解除好友 / 拉黑 → **级联取消双方之间的全部共享** | 共享的授权基础就是好友关系；基础消失而授权保留，是用户不可理解的权限残留（v1.0 的相反决策已废止） | §5.2、§6.2 |
| ADR-09 | 扫描 / 渲染 | ML Kit（扫描）+ ZXing（渲染） | 磨损卡面的识别率决定录入成功率；渲染不需要 Google 依赖 | §10.1 |
| ADR-10 | 工程规范 | 契约优先 + 硬性质量门禁 | 3–5 人并行，前后端错位成本远高于门禁成本 | §13 |
| ADR-11 | 商业模式 | 一期完全免费，不预留订阅/配额表结构 | YAGNI。但**系统安全限额必须存在**（见 §3.1 修订） | §7.5 |
| ADR-12 | 一期不做图片**存储** | — | 工期约束。**不影响** ADR-13 的图片录入（解码即弃，无存储管道） | §1.3 |

---

## 3. CTO 复审：发现的问题与方案修订

> 本节记录对前期决策的对抗性复审。**每条修订都已并入后续章节**，此处保留是为了让后来者理解"为什么是这样"。

### 3.1 【严重】"完全免费不预留配额" ≠ "没有限额"

**问题**：商业配额（付费解锁）与系统限额（防滥用）被混为一谈。当前设计里，一个账号可以无限建卡、无限发好友请求、无限发共享邀请。这是免费产品的标准 DoS / 垃圾邀请向量。

**修订**：
- 不建 `plan` / `quota` 表（尊重 ADR-11）。
- 但**必须**在配置中硬编码系统限额常量，并在服务端强制校验（§7.5 限额表）。
- 全局日发信量超阈值 → 告警 + 自动熔断非关键邮件（保留 OTP）。

**v1.1 更新：垃圾邮件跳板风险已大幅下降。** 取消邮箱邀请与邀请链接后（C2），外发邮件只剩两类：
1. **OTP / Magic Link**：收件人是请求者输入的**任意**邮箱，由每邮箱 1/min、5/h、10/day 的严格限速兜住（§7.5）。
2. **安全提醒**（新设备登录、refresh 重放检测）：收件人**只能是账号自己**，由系统事件触发，用户无法指定收件人。

因此原"每账号每日 10 封邀请信 + 新账号 24h 冷启动期"的限额**不再需要，已删除**。发信域名被黑仍是 R1 级风险。

> ⚠️ **[ADR-0014](adr/0014-otp-always-sends-a-code.md)（2026-09-06）修正了上面第 1 条的措辞。**
> 本节 v1.1 原文写的是「**用户无法让系统向任意第三方邮箱发信**」，而那句话的前提是
> 「未注册的邮箱不发信」——那个设计让注册变得不可能（新用户永远收不到码），
> 已在 T-104 取消。
>
> 准确的表述是：**用户可以让系统向任意邮箱发信，但每个邮箱每天最多 10 封，
> 且内容只可能是一封验证码信。**
>
> ⚠️ **[ADR-0016](adr/0016-magic-link-delivery-and-landing-page.md)（2026-09-07）
> 给「那封信」补了一句**：它同时带 6 位码与一个 Magic Link（同一条挑战、
> 同一个 `consumed_at`）。**仍然是一封** —— 发两封会让上面那个「10 封」实际
> 变成 20 条消息，而 ADR-0013 的域名邮箱配额至今没有实测。
> 外发邮件的清单因此从四封变成**三封**（`magic_link` 模板已退休）。 T11 的攻击面因此是「对任意邮箱、被三个窗口夹住的
> OTP 轰炸」，而不是原来以为的「仅对自有邮箱」。
>
> 这个代价是明知并接受的：三个窗口是 §7.5 原文就有的，ADR-0014 没有放宽任何一个；
> 而换来的是「注册路径存在」以及一套更强的防枚举（服务端在那条路径上根本不查 `users`）。
> 全局熔断保留不变，但 §14.4 的 `email_send_total` 阈值校准要把未注册地址的那部分算进去。

> 反过来，新增的枚举/骚扰面转移到了 **username 搜索**（§3.8）与**好友请求**上，这两处必须补限额（§7.5）。风险总量下降，但位置变了——不要因为"邮件风险没了"就放松社交侧限流。

### 3.2 【严重 → 一期已接受】Email 是单点故障

**问题**：ADR-03 让登录 100% 依赖邮件送达。若邮件进垃圾箱或邮件服务故障，**所有用户无法登录，包括已有用户在新设备上**。

**v1.1 决策：一期暂时忽略此问题，不做工程缓解。** 邮箱侧**使用普通商业邮箱服务**发信即可，v1.0 提出的一整套缓解（专业 ESP 强制、双活 failover、独立事务子域、送达率监控体系）**全部撤销/后置**。

理由：一期用户规模小（§9.3 目标 12 个月 5 万注册），单点故障的期望损失低于现在就投入的工程与运维成本；且该问题**任何时候补做都不需要改数据模型或客户端**——只是发信通道的替换，属于纯后端可逆决策。

**一期实际执行（降级后的最小集）**

| 项 | v1.0 要求 | v1.1 一期实际 |
|---|---|---|
| 发信通道 | 专业 ESP（EU）强制，禁 SMTP 直发 | **普通商业邮箱服务**（走其 SMTP 或 API 均可），仅要求**数据处理在 EU/EEA 内**（这是 GDPR 硬约束，不可放宽，见 §8.3） |
| 双活 failover | MUST | ❌ 不做。单通道 |
| SPF / DKIM / DMARC | MUST，`p=quarantine`→`reject` | **保留**（这是配置动作，零工程量，且不配置几乎必进垃圾箱） |
| 独立事务子域 | MUST | ⏸ 后置。一期无营销邮件，无隔离必要 |
| 送达率 / bounce 监控体系 | MUST | ⏸ 后置。只保留一个**低成本代理指标**：OTP 请求 → 验证成功的转化率（§14.4 已有该告警，无额外开发） |
| refresh token 90 天滑动 | MUST | **保留**。这是本节唯一真正的缓解措施，且成本为零——它把邮件故障的爆炸半径限制在"新设备登录/新注册"，存量用户不受影响 |

**必须记录为已接受风险**：
- 风险登记册 R1 的缓解措施相应降级（见 §16），状态改为「已接受，二期再议」。
- 上线前**必须**实测一次：向 Gmail / GMX / Web.de / Outlook 四家（德国主流）各发一封 OTP，确认不进垃圾箱。这是 4 次手工验证，不是工程项。
- 若上线后 OTP 转化率告警触发（1 小时窗口 < 80%），**触发条件即为重启本决策的信号**——届时按 v1.0 方案补做专业 ESP + 双活，预计 2 人日。

> ⚠️ 给实现者：本节是**有意识的风险接受**，不是遗漏。请把发信实现收敛在 `Notification` 模块的 `MailSenderInterface` 之后（Symfony Mailer DSN 一行配置即可切换），**不要把邮箱服务商的细节泄漏到业务代码里**——这是保证"未来 2 人日能补回来"的唯一前提。

> **落地情况（2026-09-05）**：通道由 T-102 交付，抽象由 deptrac 强制（`Framework.Mail` / `Framework.Templating` 两个图层只对 `Notification.Infrastructure` 开放，`deptrac:selftest` 场景 ④ 守着），见 [ADR-0012](adr/0012-mail-channel-topology.md)。上表第一行的"普通商业邮箱服务"取值已定：**`n-cards.de` 的域名邮箱（dogado GmbH）**，见 [ADR-0013](adr/0013-mail-via-domain-mailbox.md)。
>
> ⚠️ 该选型给本节的风险接受**追加了一条本节原本没算进去的天花板**：域名邮箱有发信配额上限，而 §9.3 的 5 万注册目标推算出的日发信量（约 1100 封）已经越过典型配额量级。因此本节末尾"重启本决策的信号"从**一条**变成**两条**：除 OTP 转化率 P1 告警外，`email_send_total` 日累计逼近实测配额 50% 同样是信号。判据与处置见 [`docs/runbooks/email-dns.md`](runbooks/email-dns.md) §4。

### 3.3 【严重】服务端加密的威胁边界被高估

**问题**：ADR-02 容易被误读为"用户数据是安全的"。若 Vault 与应用运行在同一台 Hetzner 主机、且 Vault 由 systemd 自动 unseal，那么**攻破主机 = 拿到全部明文**，加密只剩心理安慰。若对外宣称"加密存储"而不说明边界，是合规风险。

**修订**（这是本次复审最重要的产出）：
- 明确写入威胁模型：服务端信封加密防护的是 **① 数据库文件/备份泄露、② 数据库只读凭据泄露、③ 运维误操作导出、④ 物理硬盘处置**。它**不防护**应用主机被完全控制。
- Vault 必须部署在**独立容器 + 独立数据卷**，且 **unseal key 不得存放在同一主机**。一期采用：Vault auto-unseal 关闭，unseal key（Shamir 3-of-5）离线保管，重启需人工 unseal；应用通过 AppRole 认证，token TTL 1 小时自动续期。
  - 代价：主机重启需人工介入（可接受，一期无 HA 要求）。
  - 若团队认为运维负担过大，替代方案：Vault Transit auto-unseal by Hetzner-external KMS，或退化为应用层信封加密 + KEK 通过 `sops`/`age` 在部署时注入内存（不落盘）。**必须二选一并记录，不得默认 systemd 明文 unseal key。**
- 对外文案（App / 官网）只允许表述为 *"Deine Kartennummern werden verschlüsselt gespeichert"*（你的卡号加密存储），**不得**使用 *"Ende-zu-Ende-verschlüsselt"* / *"Zero Knowledge"* / *"Wir können deine Daten nicht sehen"*。这是可诉的虚假宣传风险。
- 二期若要真 E2EE，架构预留：`cards.encryption_scheme` 字段（值 `server_v1`），未来可并存 `e2ee_v1`。

### 3.4 【严重】客户端明文本地库使服务端加密形同虚设

**问题**：服务端加密了，但 Android 端 Room 数据库若明文存储，所有码值在设备上是明文。丢手机 / 恶意备份 / 已 root 设备 = 全部泄露。整条链路的强度取决于最弱环节。

**修订**：
- Room **必须**使用 **SQLCipher**（`net.zetetic:sqlcipher-android`），passphrase 为 32 字节随机值，通过 Android Keystore 的 AES-GCM 密钥包裹后存于 `EncryptedSharedPreferences`。
  - ⚠️ **T-009 实际落地与本行有一处偏差，见 [ADR-0007](adr/0007-android-secret-storage-without-jetpack-security.md)**：
    `androidx.security:security-crypto` 已被 Google 停止维护，而它在本设计里只是
    「Keystore 包裹」之外的**第二层容器**（两层锚在同一个 Keystore 上，不提升防护强度）。
    落地形态是 `core:crypto` 自建的 `SecretStore` 门面：Keystore AES-GCM 包裹后
    写普通 `SharedPreferences`。本行的其余每一条（32 字节随机、AES-GCM 包裹、
    不绑用户认证）全部照做。
- **重要约束**：Keystore 密钥**不得**设置 `setUserAuthenticationRequired(true)`。原因：Widget 与 FCM 后台同步需要在无用户交互时读写数据库。这是有意识的取舍——防护目标是"设备丢失且未解锁"与"应用间越权"，不是"取证级攻击"。此取舍必须写入威胁模型，不得在文案中夸大。
- App 内提供可选的**生物识别应用锁**（BiometricPrompt），只锁 UI 不锁数据库。
- `android:allowBackup="false"`、`android:fullBackupContent` 排除数据库，防止通过 adb backup / 云备份外泄。
- Token 存储使用 Tink / EncryptedSharedPreferences，**不得**明文写入 SharedPreferences 或 Room。

### 3.5 【高 → 中】离线优先 + 共享 = 并发冲突，必须定义语义

**问题**：ADR-06（离线优先）意味着客户端可在无网时修改，恢复网络后才推送。若同一张卡在两处被并发修改，没有冲突语义就会静默丢数据。

**v1.1 的关键收窄（C5）**：**只有 owner 可以修改卡片，被分享者（viewer）不能修改卡片。** 这条决策直接消灭了本节最难的那一半问题：

| 冲突场景 | v1.0（editor 可写） | v1.1（仅 owner 可写） |
|---|---|---|
| 两个**不同用户**同时改同一张卡 | ✅ 会发生，需跨用户合并 + "谁改的"提示 | ❌ **结构性不可能**（服务端拒绝非 owner 的写） |
| 同一 owner 的**两台设备**同时改同一张卡 | ✅ 会发生 | ✅ **仍会发生**，协议必须保留 |
| viewer 修改自己的排序/置顶 | 非冲突（成员私有字段） | 非冲突（成员私有字段，不走 revision 锁） |

**修订**：冲突协议（§5.4.3）**完整保留**，但适用范围收缩为「**同一 owner 的多设备之间**」：
- 每张卡有 `revision`，客户端写入必须带 `If-Match`。
- 服务端不匹配 → `409 Conflict` + 返回服务端当前实体。
- 客户端执行**字段级三路合并**（base / local / remote）：无重叠字段自动合并后重试一次。
- 重叠字段：**服务端赢（LWW by server revision）**，本地修改丢弃，UI 提示"此卡已在另一台设备上更新"（**文案从"已被 Anna 更新"改为设备口径**——因为对方用户已不可能是修改者）。
- **唯一例外**：若冲突字段是 `barcode_value`，客户端**必须**把本地版本另存为一张新卡（标题后缀 `(Konflikt)`），因为码值是唯一不可再生的信息，静默丢弃不可接受。

**服务端必须强制（不能只靠客户端自觉）**：
- `PATCH /v1/cards/{id}`、`DELETE /v1/cards/{id}` 对非 owner 一律 `403 insufficient_role`，**即使请求体合法、revision 正确**。
- viewer 客户端**不得**为共享卡入 outbox（除 placement 外）；若因 bug 入队，服务端 403 会使其进入 `FAILED` 并上报 Sentry（§5.4.3 的 4xx 不重试规则）。
- 权限矩阵测试（§13.4）必须覆盖 `viewer × PATCH/DELETE → 403`。

### 3.6 【高】FCM 静默推送不可靠，无兜底

**问题**：ADR-04 依赖 FCM data-only 消息。在 Doze 模式、以及小米/华为/OPPO 等激进省电 ROM 上，data-only 消息**不保证送达**，也不保证及时。若唯一同步触发器是 FCM，共享变更可能数小时不可见。

**修订**：四层同步触发（§4.4）：
1. FCM data-only（尽力而为，P50 < 3s）
2. App 进入前台（`ON_START`）立即同步
3. WorkManager 周期任务：每 6 小时；`NetworkType.CONNECTED` 约束，网络恢复即触发
4. 钱包页下拉刷新（用户可控兜底）

并在设置页提供"电池优化白名单"引导（`ACTION_REQUEST_IGNORE_BATTERY_OPTIMIZATIONS`），文案说明用途。

### 3.7 【高】账号删除与共享卡的所有权冲突，未定义即违规

**问题**：GDPR Art.17 要求删除；但如果 Anna 删号，她拥有的、已分享给 Bob 的卡怎么办？删掉 = 破坏 Bob 的数据；不删 = Anna 的数据仍在。这是**必须在写代码前定死**的语义。

**v1.1 决策（C8）：账号删除后，共享一律取消，共享卡一并硬删除，卡券不作任何转让。**

**修订**（§8.4）：
- 删号流程：
  - 用户拥有的卡 → **全部硬删除**，无论是否有 viewer。删除对每位 viewer 表现为一条成员墓碑 + 卡墓碑，其本地行被物理清除。
  - 用户作为 viewer 参与的卡 → 仅移除成员关系（对 owner 是普通"成员离开"事件），**卡本身不受影响**。
  - 用户的好友关系 → 双向硬删除，对方的好友列表中该用户直接消失（不留"已注销用户"占位，避免残留个人数据）。
  - 用户发出/收到的待处理共享邀请、好友请求 → 一并硬删除。
- 删除确认页**必须**明示影响面：「你拥有的 N 张卡将被永久删除，其中 M 张正共享给 K 位好友，他们将同时失去这些卡。卡片不会转让给任何人。」（对应 `GET /v1/me/deletion/preview`）
- 30 天宽限期（`status = pending_deletion`），期间成功登录即自动取消删除；宽限期内该用户对所有卡不可见、不接收推送。**宽限期内共享关系保持不变**（因为删除可撤销，提前撤销共享会让"取消删除"无法完整还原）。
- 宽限期结束由每日定时任务执行硬删除 + 审计日志（审计日志只留 `user_id` 与时间，不留邮箱）。

**为什么"不转让"是正确的（而不是偷懒）**

| 维度 | 转让给 viewer（v1.0） | 直接删除（v1.1） |
|---|---|---|
| 用户预期 | 差：Bob 某天醒来发现自己"拥有"了 Anna 的卡，且已无法追溯来源 | 好：与"Anna 注销了"直觉一致 |
| 法律 | 含糊：把 Anna 的数据强行归属给 Bob，缺乏法律基础 | 清晰：Art.17 彻底删除 |
| 实现 | 复杂：继任者选举、owner 唯一性索引竞争、审计链 | 简单：级联删除 + 墓碑 |
| 数据损失 | 无 | **有：Bob 会失去一张在用的卡** |

最后一行是唯一的代价，且是可控的——因为**码值本身在实体卡上**，Bob 重新扫一次即可恢复。而 §5.4.3"码值不可静默丢弃"的原则在这里通过**事前明确告知 Bob 卡的来源**来满足：UI 必须在共享卡上持久显示「由 {username} 共享」，使 Bob 始终知道该卡不属于自己、可能消失。

> **给实现者**：删号是 §13.4 强制要求的"删号完整性测试"的对象。v1.1 后该测试**必须**新增断言：owner 删号后，viewer 端收到卡墓碑，且 `cards` / `card_members` 两表均无残留行。

### 3.8 【中】邮箱明文存储、账号枚举，与 username 的引入

**问题**：`users.email` 明文存储，DB 泄露即得到全量邮箱列表（本身就是可售卖的个人数据）。另外，"通过邮箱加好友"若返回"该用户不存在"，即构成账号枚举接口——邮箱是可批量生成/购买的字典，枚举结果可直接变现为"邮箱有效性验证服务"。

**修订（邮箱侧，不变）**：
- `users` 表存 `email_hash`（HMAC-SHA256，pepper 存 Vault）用于唯一约束与查找，`email_encrypted`（Vault Transit）用于发信。**不存明文列**。
- 涉及邮箱的端点（仅剩 OTP 请求）**必须**恒定返回 `202 Accepted`，响应体与耗时不区分账号是否存在（对不存在的邮箱也返回一个哑 `challenge_id`）。

**v1.1 新增（C2 / C3 / C4）：好友添加改为 username 精确搜索，邮箱彻底退出社交路径。**

`users` 表新增 `username`：**与 email 一样全局唯一，且不可变**，作为搜索添加好友的唯一检索键。

**username 规范（MUST，服务端强制）**

| 项 | 规则 | 理由 |
|---|---|---|
| 字符集 | `^[a-z0-9_]{3,20}$`（仅小写字母、数字、下划线） | 排除大小写混淆、同形异义字（西里尔 `а` vs 拉丁 `a`）、空格与 emoji，避免"看起来一样但不是同一个人"的社工攻击 |
| 输入规范化 | 客户端与服务端均对输入 `trim` + `toLowerCase`（`Locale.ROOT`，**不得**用系统 locale——土耳其语的 `I→ı` 会破坏匹配） | 用户不会记得自己用的大小写 |
| 唯一性 | 大小写不敏感唯一（存归一化后的小写值，普通 `UNIQUE` 约束即可） | |
| 可变性 | **不可变**。设定后无 API 可修改（连管理员也不通过 API 改，需 DBA 手工介入 + 审计） | 若可变，Bob 改名后 Anna 的好友列表指向的人就变了；不可变使 username 成为稳定的社交锚点 |
| 设定时机 | 注册流程内**必须**设定（首次 OTP 验证成功后的强制步骤），未设定不得进入钱包 | 避免出现"无 username 的用户不可被搜索"的半残状态 |
| 保留词 | 黑名单：`admin`、`support`、`ncards`、`help`、`root`、`system`、`info`、`kontakt`、`datenschutz` 等（配置文件维护） | 防冒充官方 |
| 可发现性 | username **是公开可搜索的伪名**，不含个人数据即为设计目标。UI 在设定页**必须**提示"其他人可通过该名字找到你，请勿使用真实姓名或邮箱" | GDPR 数据最小化 + 用户知情 |

> **重要**：username **不可变**意味着 UI 上的"确认设定"是一个不可逆动作，**必须**用二次确认对话框，且文案明确（`Dieser Name kann später nicht geändert werden.`）。这是本项目为数不多的不可逆用户操作之一，和删号同级对待。

**搜索行为（C4）：不作输入提示**

- **只支持精确匹配**（`WHERE username = :normalized_input`）。**禁止**前缀匹配、模糊匹配、`LIKE '%x%'`、相似度排序。
- **禁止** typeahead / 自动补全 / 边输边搜。客户端**只能**在用户显式点击「搜索」后发起一次请求。
- 结果只有两种：命中（返回 `{user_id, username}` 两个字段）或未命中（`404`）。**不返回候选列表、不返回"你是不是要找…"**。
- 这不是产品体验的妥协，而是安全设计：任何 typeahead 都会把 username 空间变成可批量遍历的目录。精确匹配 + 限速（§7.5：30/min、300/day/用户）使枚举成本远高于收益。

**关于"username 搜索是否构成账号枚举"——是，且这是可接受的**

必须理解这里与邮箱枚举的**本质差别**，不要错误地把邮箱那套"恒定 202"照搬过来：

| | 邮箱枚举 | username 枚举 |
|---|---|---|
| 泄露了什么 | 「这个**真实邮箱**注册了 N-Cards」——邮箱是跨服务的强身份标识，可直接用于钓鱼/撞库 | 「`anna_b` 这个**伪名**存在」——伪名是用户为本服务自选的，与真实身份无关联（前提是 UI 已提示勿用真名） |
| 字典成本 | 极低（可购买亿级真实邮箱表） | 需猜测用户自选的 3–20 字符串 |
| 能否消除 | 能（**恒发码**，两条路径合并成一条，功能不受损，见 [ADR-0014](adr/0014-otp-always-sends-a-code.md)） | **不能**——"搜不到就是不存在"是搜索功能的固有语义，返回假的"找到了"会让功能不可用 |
| 结论 | 必须防护 | **接受，以限速 + 精确匹配 + 伪名化控制风险**（记为 §7.2 T18） |

搜索响应**必须**只返回 `user_id` / `username` **两个**字段（v1.1 移除 `display_name` 后更是如此），**绝不**返回邮箱、注册时间、好友数、卡数等任何附加信息——那才是真正会把枚举变成情报收集的地方。

> 副作用：由于搜索结果只有一个 username，用户**无法**在发送好友请求前"确认这是不是我认识的那个人"。这是可接受的——username 本来就需要通过其他渠道（当面、IM）从对方那里拿到，拿到的就是对的；而任何"帮助确认身份"的附加信息都会同时帮助枚举者。

### 3.9 【中】Widget 泄露风险

**问题**：主屏 Widget 若直接渲染条码，则锁屏预览、他人瞥屏、截屏分享时都会泄露会员号。

**修订**：Widget **只**显示卡名称 + 颜色/首字母图标，**绝不**渲染条码或码值。点击后启动 App 全屏页（若启用了应用锁，先过生物识别）。

### 3.10 【中】离线 App 的数据库/接口演进未受约束

**问题**：离线优先意味着旧版本客户端会长期存在（用户不更新）。任何破坏性 schema 或 API 变更都会让老客户端同步失败甚至数据损坏。

**修订**（§13.5、§13.6）：
- 后端 DB 迁移**必须**遵循 expand–contract：加可空列 → 双写回填 → 切读 → 删旧列，跨 ≥3 次发布。禁止一次性 `ALTER ... NOT NULL` / 改列名 / 删列。
- `/v1` 内只允许向后兼容变更。客户端**必须**忽略未知 JSON 字段（`ignoreUnknownKeys = true`）。
- 提供 `GET /v1/config` 下发 `min_supported_client`，低于该版本的客户端进入强制升级墙。
- 同步协议携带 `schema_version`；服务端判定客户端过旧时返回 `409 full_resync_required`。

### 3.11 【中】3 个月 / 3–5 人 与"全套 NFR"存在冲突

**问题**：契约优先 + 硬门禁 + 完整 GDPR + 威胁建模 + SLO + 无障碍 + 双语 + Widget + 同步引擎 + 共享，在 12 周内由 3–5 人完成，是过载的。不裁剪必然以隐性方式裁剪（跳过测试）。

**修订**：明确"上线必需"与"上线后 30 天内"两档（§15.4）。上线必需：GDPR 数据主体权利 + 法律页面 + 威胁模型中的高危缓解 + 关键路径无障碍 + 核心 SLI 埋点。可后置：完整 Grafana 看板体系、非关键路径无障碍打磨、E2E 测试矩阵扩充、性能压测报告。

### 3.12 【低但必须记】FCM 是美国数据处理方

**问题**：Google FCM 作为处理者涉及第三国传输。若推送载荷包含"Anna 分享了 PAYBACK 卡给你"，就把用户关系与消费偏好交给了 Google。

**修订**（这条恰好与 ADR-04 天然契合，需要显式固化）：
- FCM 载荷**必须**为纯 data-only 且**不含任何个人数据**，固定为 `{"t":"sync","v":1}`。
- 通知文案由客户端在本地同步后自行生成。
- 因此 ROPA 中 FCM 的处理数据仅为「设备推送令牌 + 唤醒信号」，显著降低传输评估的复杂度。
- **禁止**集成 Firebase Analytics、Crashlytics（用自托管 Sentry 替代）、Google Analytics 或任何广告 SDK。

### 3.13 【低】"一期不做图片"与优惠券形态的落差

**问题**：很多优惠券（尤其是纸质/邮件优惠券）除了码之外还有条款、有效期、门槛。没有图片也没有结构化字段的话，用户会觉得"记不全"。

**修订**：不做图片（坚持 ADR-12），但**必须**提供：
- `note` 自由文本字段（≤ 2000 字符，与码值同等加密）
- `expires_on` 日期字段（仅本地展示与排序，一期不做推送提醒——提醒是二期功能，但字段先有，避免二期改表）

> 注：这与"不预留订阅字段"不矛盾。`expires_on` 是一期即可见的产品价值（列表按到期排序），订阅字段则是纯投机。

### 3.14 复审后的净变化清单

| 变更 | v1.0 估算 | v1.1 调整后 |
|---|---|---|
| 新增系统限额层（Redis + 校验） | +3 人日 | +3 人日 |
| ~~ESP 双活 + 送达监控~~ | +2 人日 | **0**（§3.2 已接受风险，不做） |
| Vault 独立部署 + 人工 unseal 手册 | +2 人日 | +2 人日 |
| SQLCipher 集成 | +2 人日 | +2 人日 |
| 冲突解决协议（含码值冲突副本） | +5 人日 | **+3 人日**（仅 owner 可写 → 无跨用户合并与"谁改的"归因，§3.5） |
| 四层同步触发 | +2 人日 | +2 人日 |
| ~~删号所有权转移流程~~ | +3 人日 | **+1 人日**（改为级联硬删 + 影响预览，§3.7） |
| 邮箱 hash + 加密存储 | +2 人日 | +2 人日 |
| 强制升级墙 + full_resync | +2 人日 | +2 人日 |
| **【新】username：规范校验 + 保留词 + 注册内强制设定 + 精确搜索 + 限速** | — | **+2 人日** |
| **【新】从图片录入（Photo Picker + 静态图解码 + 多码选择 + 降采样）** | — | **+2 人日** |
| **【新】解除好友/拉黑 → 级联取消共享（事务 + 双向墓碑 + UI 告知）** | — | **+1.5 人日** |
| **【减】取消邀请链接 + 邮箱邀请**（表、端点、邮件模板、限额、App Links 落地页、兑换流程） | — | **−4 人日** |
| **【减】取消 `editor` 角色与改角色端点**（权限矩阵组合数近乎减半） | — | **−1.5 人日** |
| **合计** | **约 23 人日** | **约 16 人日（≈ 3.2 人周）** |

以 4 人团队计，从占 12 周总容量的 10% 降至约 **7%**。**这部分成本必须计入 §15 排期，不得挤占测试时间。**

> **v1.1 净效应：范围收窄约 7 人日，且收窄的都是"高复杂度低价值"的部分**（邀请链接的分发/兑换/防滥用、editor 的跨用户并发、所有权转移的继任者选举）。新增的三项都是低风险的线性工作量。这使 R5（工期不足）的压力实质性下降。

---

## 4. 系统架构

### 4.1 总体拓扑

```
┌──────────────────────────┐          ┌────────────────────────────────────────┐
│      Android App         │          │        Hetzner (Nürnberg, DE)          │
│  ┌────────────────────┐  │          │                                        │
│  │ Compose UI         │  │  HTTPS   │  ┌──────────┐    ┌──────────────────┐  │
│  │ ViewModel          │  │◄────────►│  │  Caddy   │───►│  FrankenPHP /    │  │
│  │ Repository         │  │  JSON    │  │  (TLS)   │    │  Symfony 7.x     │  │
│  │ ┌────────────────┐ │  │          │  └──────────┘    └────────┬─────────┘  │
│  │ │ Room+SQLCipher │ │  │          │                           │            │
│  │ │ (真相源)        │ │  │          │       ┌───────────────────┼──────────┐ │
│  │ └────────────────┘ │  │          │       ▼                   ▼          ▼ │
│  │ SyncEngine         │  │          │  ┌─────────┐        ┌─────────┐  ┌────────┐
│  │ WorkManager        │  │          │  │Postgres │        │  Redis  │  │ Vault  │
│  └────────────────────┘  │          │  │   16    │        │    7    │  │Transit │
│  Glance Widget / QS Tile │          │  └─────────┘        └─────────┘  └────────┘
└───────────▲──────────────┘          │       │                                  │
            │                          │       │  ┌────────────────────────────┐  │
            │ data-only push           │       └─►│ Messenger Worker (async)   │  │
            │                          │          │  邮件 / 推送 / 清理 / 重加密 │  │
   ┌────────┴────────┐                 │          └──────────┬─────────────────┘  │
   │  Google FCM     │◄────────────────┼─────────────────────┤                    │
   └─────────────────┘                 │                     ▼                    │
                                       │          ┌──────────────────┐            │
   ┌─────────────────┐                 │          │ 邮件服务商（单一， │            │
   │ 用户邮箱         │◄────────────────┼──────────│  EU/EEA，§3.2）   │            │
   └─────────────────┘                 │          └──────────────────┘            │
                                       │  ┌────────────────────────────────────┐  │
                                       │  │ Prometheus / Grafana / Loki /Sentry│  │
                                       │  └────────────────────────────────────┘  │
                                       └────────────────────────────────────────┘
                                          备份 → Hetzner Storage Box (加密, FI)
```

**关键说明**
- 单主机 Docker Compose 起步（`CCX23`：4 vCPU / 16 GB / 80 GB NVMe 起）。所有组件容器化，通过 Compose profile 区分。
- Vault 使用**独立数据卷**，unseal key 离线保管（见 §3.3）。
- 无 CDN、无第三方前端资源（App 内所有资源打包在 APK 内）。
- 备份跨可用区：Hetzner Storage Box（可选芬兰机房，仍在 EU/EEA 内，GDPR 无第三国传输问题）。

### 4.2 后端模块划分（模块化单体）

```
Identity        用户、username（唯一/不可变/保留词）、Email OTP 挑战、会话、Refresh Token、设备
Wallet          卡实体、加密码值、排序、搜索
Sharing         卡成员（owner/viewer）、共享邀请、退出共享、好友解除时的级联撤销
Social          好友关系、好友请求、username 精确查找、拉黑
Sync            change_log、游标、增量同步 API
Notification    FCM 发送、邮件模板与发送、通知偏好
Compliance      数据导出、账号删除编排、审计日志
Shared          跨模块内核：UUID、时钟、加密门面、限流、HTTP 问题详情、事件总线
```

**模块间通信规则（Deptrac 强制，违反即 CI 失败）**

1. 模块**不得**直接引用其他模块的 `Domain` 或 `Infrastructure` 命名空间。
2. 跨模块同步调用**只能**通过被调方 `Application\Port\*Interface` 暴露的接口（依赖注入）。
3. 跨模块异步通知**只能**通过 Domain Event（Symfony Messenger，`async` transport）。
4. 所有模块**可以**依赖 `Shared`。`Shared` **不得**依赖任何模块。
5. 数据库层面：一张表由且仅由一个模块拥有。跨模块查询**必须**走接口，不得写跨模块 JOIN。
   - 例外：`Sync` 模块为性能考虑允许只读跨模块查询，但**必须**通过 `Sync\Infrastructure\Doctrine\SyncReadModel` 单一入口，且该类需在 Deptrac 中显式豁免并加注释说明。

**已知的跨模块协作点（v1.1 新增，实现前请先读这里）**

| 场景 | 方向 | 通信方式 | 为什么 |
|---|---|---|---|
| 解除好友 / 拉黑 → 取消双方全部共享（ADR-14） | `Social` → `Sharing` | **同步**，经 `Sharing\Application\Port\ShareRevokerInterface::revokeAllBetween(userA, userB)`，与好友关系变更在**同一事务**内 | 若用异步事件，会出现"已非好友但仍能看到对方卡"的可见窗口，且 `change_log` 的两条记录不在同一事务，客户端可能只收到其中一半。**这是本项目唯一强制要求跨模块同步 + 同事务的协作点** |
| 发起共享邀请 → 校验"互为好友" | `Sharing` → `Social` | **同步**，经 `Social\Application\Port\FriendshipCheckerInterface::areFriends(a, b)` | 前置条件校验，必须实时 |
| 接受共享邀请 → 二次校验"仍为好友" | `Sharing` → `Social` | 同上 | 邀请发出到接受之间可能已解除好友，**不可省略此次重校验** |
| 共享变更 → 推送唤醒 | `Sharing` → `Notification` | **异步** Domain Event（`CardSharedWith` / `MemberRemoved`） | 推送失败不应回滚业务事务 |
| 注册完成 → username 落库 | `Identity` 内部 | — | username 属 `Identity`，`Social` 通过 `UserDirectoryInterface::findByUsername()` 只读查询 |

**依赖方向（编译期强制）**

```
Http → Application → Domain
              ↓
       Infrastructure  (实现 Domain 定义的接口)
```

Domain 层**不得** import Doctrine、Symfony HttpFoundation 或任何框架类型。

### 4.3 Android 架构

**分层**

```
UI (Compose)        无状态 Composable + 预览；只消费 UiState
  ↓ collectAsStateWithLifecycle
ViewModel           持有 StateFlow<UiState>；只调用 UseCase / Repository
  ↓
Domain (可选 UseCase) 仅当逻辑跨多个 Repository 时才建 UseCase，否则 ViewModel 直调 Repository
  ↓
Repository          唯一决定"数据从哪来"的地方
  ↓
Room (真相源)  +  Remote (Retrofit)  +  SyncEngine
```

**离线优先的铁律**

1. UI **永远**从 Room 的 `Flow` 读取，**绝不**直接消费网络响应渲染。
2. 用户的写操作：**先**写 Room（乐观更新，`sync_state = PENDING`），**再**入 outbox，由 SyncEngine 异步推送。
3. 网络失败**不得**回滚 UI，只更新 `sync_state`（`PENDING` / `SYNCING` / `SYNCED` / `FAILED`）并在卡片上显示细微徽章。
4. 客户端生成 UUIDv7 作为主键，因此离线创建的实体从一开始就有稳定 ID，无需"临时 ID → 服务端 ID"的重映射。

**技术选型**

| 领域 | 选择 | 备注 |
|---|---|---|
| 语言 / UI | Kotlin 2.x + Jetpack Compose (Material 3) | 全 Compose，无 XML 布局 |
| DI | Hilt | |
| 数据库 | Room + SQLCipher | §3.4 |
| 网络 | Retrofit + OkHttp + kotlinx.serialization | `ignoreUnknownKeys = true` **必须** |
| 后台任务 | WorkManager | 同步、周期任务 |
| 偏好存储 | DataStore (Proto) + EncryptedSharedPreferences（令牌） | |
| 相机 | CameraX | |
| 扫描 | ML Kit Barcode Scanning（**bundled** 模型） | bundled 避免首次使用时下载失败 |
| 渲染 | ZXing core（`com.google.zxing:core`，仅编码） | 不引 `zxing-android-embedded` |
| Widget | Glance | |
| 推送 | Firebase Messaging（**仅** FCM，不引其他 Firebase 组件） | §3.12 |
| 崩溃 | Sentry Android（自托管 / EU） | 不用 Crashlytics |
| 测试 | JUnit5 + MockK + Turbine + Robolectric + Compose UI Test | |
| 构建 | Gradle Version Catalogs + Convention Plugins（`build-logic`） | |

**最低 API**：26（Android 8.0）。覆盖 ~98% 活跃设备，且 Keystore / 通知渠道 / Java 8 API 均可用。

### 4.4 实时同步链路

**写入方（Anna = owner，修改了共享卡；Bob = viewer，只读接收）**

```
Anna App          Backend                              Bob App
   │ PATCH /v1/cards/{id}  (If-Match: rev=7)
   ├─────────────────────►│
   │                      │ 1. 校验权限（Sharing）：必须是 owner，否则 403
   │                      │ 2. 校验 revision（乐观锁）
   │                      │ 3. Vault 加密 payload（若变更）
   │                      │ 4. UPDATE cards SET revision=8
   │                      │ 5. INSERT change_log(audience=[Anna,Bob])
   │                      │    ── 以上 1 个事务 ──
   │◄─────────────────────┤ 200 {card, revision: 8}
   │                      │
   │                      │ 6. Messenger async: PushSyncSignal(userIds=[Bob])
   │                      │──────────► FCM data-only {"t":"sync","v":1}
   │                      │                              ├────────────────►│
   │                      │                              │  7. onMessageReceived
   │                      │◄─────────────────────────────┤  GET /v1/sync?since=...
   │                      │  8. 返回 change_log 增量 + 实体当前态
   │                      ├─────────────────────────────►│
   │                      │                              │  9. 写入 Room（事务）
   │                      │                              │ 10. Flow 触发 UI 重组
   │                      │                              │ 11. 本地生成通知
```

**四层触发（§3.6）**：FCM → 前台 `ON_START` → WorkManager（6h / 网络恢复）→ 下拉刷新。
所有触发**必须**汇聚到同一个 `SyncEngine.sync()`，由互斥锁（`Mutex`）保证同一时刻只有一次同步在跑，重入者等待并复用结果。

**目标延迟**：Anna 保存 → Bob 前台可见，P95 ≤ 10 秒（App 在前台）；P95 ≤ 60 秒（App 在后台且未被 ROM 冻结）。

> **单向性在同步引擎上的体现（v1.1）**：Bob 侧是**纯消费者**——他的 `SyncEngine` 对共享卡只做"下行写入 Room"，永远不会为共享卡产生上行 outbox 记录（`placement` 除外）。因此上文时序图**不存在**镜像方向的版本；Bob 的本地修改能力在 UI 层就被禁用（编辑入口不可见，而非点击后报错）。

---

## 5. 数据模型

### 5.1 ER 概览

```
                 ┌────────────────────┐
                 │       users        │
                 │ (email_hash,       │
                 │  username ← 唯一   │
                 │   不可变、搜索键)   │
                 └────┬───────────────┘
        ┌─────────────┼──────────────┬────────────────┐
        │             │              │                │
   ┌────▼─────┐  ┌────▼──────┐  ┌────▼────────┐  ┌────▼──────────┐
   │ devices  │  │ sessions  │  │ friendships │  │ card_members  │
   └────┬─────┘  └───────────┘  └──────┬──────┘  │ (owner|viewer)│
        │                              │         └────┬──────────┘
   (fcm_token)             解除好友 → 级联撤销 ────────┤
                                                 ┌────▼─────┐
   ┌──────────────────┐                          │  cards   │
   │ otp_challenges   │                          └────┬─────┘
   └──────────────────┘                               │
                                                 ┌────▼──────────────┐
   ┌──────────────────┐   ┌──────────────────┐   │ share_invitations │
   │   change_log     │   │  audit_log       │   │  (无 role 列)     │
   └──────────────────┘   └──────────────────┘   └───────────────────┘
```

> **v1.1 表级变化**：`users` 新增 `username`；`friend_invite_links` 表**已删除**（取消邀请链接）；`share_invitations.role` 列**已删除**（恒为 viewer）；`card_members.role` 的取值域收缩为 `owner|viewer`。

### 5.2 表定义

> 数据库：PostgreSQL 16。所有表带 `created_at TIMESTAMPTZ NOT NULL DEFAULT now()`。
> 命名：`snake_case`，表名复数。所有外键显式命名 `fk_<table>_<column>`。

#### `users`

| 列 | 类型 | 约束 | 说明 |
|---|---|---|---|
| `id` | UUID | PK | UUIDv7 |
| `email_hash` | BYTEA | UNIQUE NOT NULL | HMAC-SHA256(lower(trim(email)), pepper)，pepper 存 Vault |
| `email_encrypted` | TEXT | NOT NULL | Vault Transit 密文（`vault:v1:...`），用于发信 |
| **`username`** | **TEXT** | **UNIQUE, NULL**（见下） | **v1.1 新增**。已归一化的小写值，`^[a-z0-9_]{3,20}$`。**唯一且不可变**，是添加好友的唯一检索键（§3.8）。**明文存储**——它是用户自选的公开伪名，不是个人数据，且必须支持等值查询 |
| ~~`display_name`~~ | — | — | **v1.1 移除**（C10）。`username` 即用户在全局唯一的展示名与检索名，见下方说明 |
| **`username_attempts`** | **SMALLINT** | **NOT NULL DEFAULT 0** | **T-107 新增**。`POST /v1/me/username` 已被打过几次（§7.5：10 次总计）。**生命周期计数**，不是滑动窗口 —— §8.2 的 ROPA 把 Redis 限流计数的保留期定为 24 小时，而这个计数要跨越整个账号生命周期，见 [ADR-0017](adr/0017-username-assignment-and-lifetime-attempt-counter.md) |
| `locale` | TEXT | NOT NULL DEFAULT 'de' | `de` / `en` |
| `status` | TEXT | NOT NULL DEFAULT 'active' | `active` / `pending_deletion` |
| `deletion_requested_at` | TIMESTAMPTZ | NULL | 宽限期起点 |
| `created_at` / `updated_at` | TIMESTAMPTZ | NOT NULL | |

> ⚠️ **不存明文邮箱列**（§3.8）。任何需要邮箱的地方（发信）都走 `email_encrypted` → Vault 解密。

**为什么移除 `display_name`（C10）**

有了 `username` 之后，`display_name` 是**同一职能的第二个名字**，代价大于收益：

| 维度 | 保留两个名字 | 只保留 username |
|---|---|---|
| UI | 每处都要决定"显示哪个/是否两个都显示"，好友列表、共享徽章、通知、成员列表各写一遍 | 一个名字，无分支 |
| **冒名风险** | **`display_name` 可变且不唯一 → Bob 可把自己的 display_name 设成 `anna_b`**，在通知与列表里冒充他人。要防就得处处并列显示 username，那 display_name 就失去了意义 | 结构上不可能 |
| 个人数据 | 自由文本，用户大概率填真名（"Anna Müller"），是**比 username 更敏感的个人数据**，还要进 ROPA、导出、删除 | 只有一个用户自选的伪名，数据最小化 |
| 同步 | 多一个可变字段要下发、要处理冲突 | — |

代价是 username 只能是 `[a-z0-9_]`，德语用户无法拥有带变音符号和大小写的名字（`anna_mueller` 而非 "Anna Müller"）。在这个"好友数量 3–10 人、彼此本就认识"的场景里，可读性损失有限，不值得为它引入上述全部复杂度。

> **考虑过但拒绝的中间方案**：像 Twitter 那样保留大小写展示形（`Anna_B` 展示、`anna_b` 匹配）。拒绝理由是它只解决了大小写，解决不了变音符号与空格，却让"两个名字是否相等"变成一个需要处处小心的问题。
>
> **二期可选的补偿**：**本地备注名**（viewer 在自己设备上给好友起的别名，仅存 Room，不上传）。它能拿回可读性，且天然没有冒名与个人数据问题——因为它只存在于起名者自己的手机里。一期不做。

**关于 `username` 为何是 `NULL` 而非 `NOT NULL`**

存在一个**不可避免的中间态**：`POST /auth/otp/verify` 成功即创建 `users` 行（首次验证即注册），但此时用户还没设 username。三种设计中我们选第 3 种：

| 方案 | 问题 |
|---|---|
| 注册时由服务端随机生成 username | 用户会得到 `user_8f3a2b` 这种名字，而且**不可变**——等于永久惩罚 |
| 把 username 放进 `otp/verify` 请求体 | 强迫客户端在验证码界面之前就收集 username；且验证失败时输入全丢 |
| **✅ 允许 `NULL`，用应用层状态机强制补全** | 需要一个"未完成注册"的中间态，但这是唯一对用户友好的 |

**因此必须实现（MUST）**：
- `username IS NULL` 的用户处于 `onboarding_incomplete` 状态。除 `GET /v1/me`、`POST /v1/me/username`、`POST /v1/auth/logout` 外，**所有** `/v1` 端点对其返回 `403 username_required`。这由**单一的 Kernel 事件监听器**统一拦截，不得逐端点判断。
- `GET /v1/me` 返回 `"onboarding_complete": false`，客户端据此路由到 username 设定页；该页**不可跳过、不可返回**。
- `POST /v1/me/username` 是**一次性**写入：若 `username` 已非空 → `409 username_immutable`。
- 每日清理任务：删除 `username IS NULL` 且 `created_at < now() - 7 days` 的僵尸行（用户中途放弃注册）。他们只是一条邮箱哈希，无任何关联数据，可安全物删。
- 唯一性冲突返回 `409 username_taken`（**这是有意为之的枚举面**：注册时必须告诉用户"这个名字被占了"，否则功能不可用；已由 §7.5 的注册限速覆盖）。
- 迁移期：`username` 列以可空加入即满足 §13.5 expand–contract，**无需**分三次发布（一期尚无存量数据）。

#### `otp_challenges`

| 列 | 类型 | 说明 |
|---|---|---|
| `id` | UUID PK | 即 `challenge_id`，返回给客户端 |
| `email_hash` | BYTEA NOT NULL | 索引 |
| `code_hash` | BYTEA NOT NULL | HMAC-SHA256(code, pepper) |
| `magic_token_hash` | BYTEA NULL | Magic Link 令牌哈希。**T-106 起有写入方**：每条挑战都签发一个。⚠️ 存的是**本地 SHA-256**，与同一行上 Vault HMAC 的 `code_hash` 口径不同 —— 6 位码可全枚举、pepper 是它唯一的防线，而 32 字节 CSPRNG 没有可枚举的字典（[ADR-0016](adr/0016-magic-link-delivery-and-landing-page.md)）。**不要顺手统一** |
| `purpose` | TEXT NOT NULL | `login`（一期仅此一种） |
| `attempts` | SMALLINT NOT NULL DEFAULT 0 | ≥5 即作废 |
| `expires_at` | TIMESTAMPTZ NOT NULL | now() + 10 min |
| `consumed_at` | TIMESTAMPTZ NULL | |
| ~~`is_decoy`~~ | BOOLEAN NOT NULL DEFAULT false | **已废弃**（[ADR-0014](adr/0014-otp-always-sends-a-code.md)）：不再有任何写入方，恒为 false。**T-113 已切读**（`VerifyOtpService` 不再看这一位）；列、ORM 映射与实体属性必须**同一次发布**一起删（`schema:validate` 比的是映射与真库），归后续卡 —— 前置条件与步骤见 `docs/tasks/M1.md` 的 T-113 与 [ADR-0022](adr/0022-daily-cleanup-via-symfony-scheduler-single-replica-no-lock.md) 决定 8 |
| **`email_encrypted`** | **TEXT NULL** | **T-104 新增**。Vault Transit 密文，与 `users.email_encrypted` 同一把密钥。**首次验证成功时用它建 `users` 行** —— 那时明文邮箱早已不在系统里 |
| **`locale`** | **TEXT NULL** | **T-104 新增**。请求验证码时选的语言，注册时进 `users.locale`。收到英文码信却拿到 `locale=de` 的账号是用户能看见的 bug |
| `request_ip_hash` | BYTEA NULL | 限流与滥用分析用，30 天后清理 |

> ⚠️ **ADR-0014 起，本端点对任意邮箱都真发码。** 原来的「哑挑战」（`is_decoy`：
> 不发信、验证恒失败、耗时与真实路径一致）已取消 —— 它让**注册变得不可能**，
> 因为新用户永远收不到码，而契约里没有第二条注册路径。
> 防枚举因此从「配平两条路径」升级成「服务端在这条路径上根本不查 `users`」。
>
> ⚠️ 两个新列让这张表从「只有哈希」变成**含加密的个人数据** ——
> §8.2 的 ROPA 分级与 §8.4 的数据导出要把它算进去（保留期不变，挑战本就短命）。

#### `devices`

| 列 | 类型 | 说明 |
|---|---|---|
| `id` | UUID PK | 客户端生成，安装级唯一（重装即新设备） |
| `user_id` | UUID FK → users | ON DELETE CASCADE |
| `platform` | TEXT | `android` |
| `model` / `os_version` / `app_version` | TEXT | 展示于设备管理页 |
| `push_token` | TEXT NULL | FCM token |
| `push_token_updated_at` | TIMESTAMPTZ NULL | |
| `last_seen_at` | TIMESTAMPTZ | |
| `revoked_at` | TIMESTAMPTZ NULL | 用户远程登出 |

#### `sessions`

| 列 | 类型 | 说明 |
|---|---|---|
| `id` | UUID PK | JWT 的 `sid` claim |
| `user_id` | UUID FK | |
| `device_id` | UUID FK | |
| `refresh_token_hash` | BYTEA NOT NULL UNIQUE | SHA-256（令牌本身是 32 字节随机） |
| `previous_token_hash` | BYTEA NULL | 轮换重放检测 |
| `expires_at` | TIMESTAMPTZ NOT NULL | now() + 90d，每次轮换顺延 |
| `revoked_at` / `revoked_reason` | TIMESTAMPTZ / TEXT | `logout` / `reuse_detected` / `user_revoked` / `account_deleted` |

#### `cards`

| 列 | 类型 | 约束 | 说明 |
|---|---|---|---|
| `id` | UUID | PK | **客户端生成** UUIDv7 |
| `owner_id` | UUID | FK → users, NOT NULL | 可转移 |
| `title` | TEXT | NOT NULL | ≤ 100 字符 |
| `merchant_label` | TEXT | NULL | 用户自填的商家名（一期无目录） |
| `color` | TEXT | NOT NULL | 预设调色板的枚举值，如 `blue_600` |
| `barcode_format` | TEXT | NOT NULL | `EAN_13` / `CODE_128` / `QR_CODE` / … |
| `barcode_value_encrypted` | TEXT | NOT NULL | Vault Transit 密文 |
| `barcode_value_fingerprint` | BYTEA | NULL | HMAC(payload)，用于重复卡检测，不可逆 |
| `note_encrypted` | TEXT | NULL | Vault Transit 密文，明文 ≤ 2000 字符 |
| `expires_on` | DATE | NULL | §3.13 |
| `encryption_scheme` | TEXT | NOT NULL DEFAULT 'server_v1' | 为二期 E2EE 预留 |
| `revision` | BIGINT | NOT NULL DEFAULT 1 | 乐观锁 |
| `deleted_at` | TIMESTAMPTZ | NULL | 软删（墓碑同步用），90 天后硬删 |
| `created_at` / `updated_at` | TIMESTAMPTZ | NOT NULL | |

> **注意**：`sort_order` 与 `is_pinned` **不在** `cards` 上，而在 `card_members` 上——每个成员对同一张卡可以有自己的排序与置顶。这是共享模型的必然结果，容易被漏掉。

#### `card_members`

| 列 | 类型 | 说明 |
|---|---|---|
| `card_id` | UUID FK → cards | 复合 PK |
| `user_id` | UUID FK → users | 复合 PK |
| `role` | TEXT NOT NULL | **`owner` / `viewer` 两种**（v1.1：`editor` 已移除）。owner 行恒为创建者，不可转让、不可降级 |
| `sort_order` | INTEGER NOT NULL DEFAULT 0 | **每成员私有** |
| `is_pinned` | BOOLEAN NOT NULL DEFAULT false | **每成员私有** |
| `added_by` | UUID FK → users NULL | |
| `joined_at` | TIMESTAMPTZ NOT NULL | |
| `left_at` | TIMESTAMPTZ NULL | 墓碑：用于向该用户下发"你已失去访问"的同步记录 |

约束：每张卡有且仅有一行 `role = 'owner'` 且 `left_at IS NULL`（用 partial unique index 强制）。

```sql
CREATE UNIQUE INDEX uq_card_single_owner
  ON card_members (card_id) WHERE role = 'owner' AND left_at IS NULL;
```

**角色权限矩阵（v1.1）**

| 操作 | owner | viewer | 非成员 |
|---|:---:|:---:|:---:|
| 查看卡 / 码值 / 全屏调出 | ✅ | ✅ | ❌ |
| 修改 title / color / merchant / note / expires_on | ✅ | ❌ | ❌ |
| 修改 barcode_value / barcode_format | ✅ | ❌ | ❌ |
| 修改**自己的** sort_order / is_pinned | ✅ | ✅ | ❌ |
| 邀请新成员（限已确认好友） | ✅ | ❌ | ❌ |
| 移除成员 | ✅ | ❌（只能移除自己＝退出） | ❌ |
| 自行退出（离开共享） | ❌（owner 无"退出"概念，只能删卡） | ✅ | — |
| 删除整张卡 | ✅ | ❌ | ❌ |
| ~~改成员角色~~ | 端点已移除（只有一种可授予的角色） | | |
| ~~转让 owner~~ | 端点已移除（C8） | | |

**读作一句话**：**owner 是唯一的写入者；viewer 只有两项权利——看，和摆放自己钱包里的位置。**

#### 成员可见性（v1.1 MUST，容易被漏掉的隐私要求）

**viewer 只能看到两个人：owner 和他自己。viewer 之间互相不可见。**

| 视角 | 能看到的成员 |
|---|---|
| owner | 全部成员（自己 + 每一位 viewer），含加入时间 |
| viewer | **仅** owner + 自己。**看不到**其他 viewer 的存在、数量、身份 |

**为什么这是硬要求**：Anna 把家庭卡分别共享给伴侣 Bob 和同事 Carol，**Bob 与 Carol 之间没有任何关系，甚至可能互不认识**。共享的授权链是「Anna↔Bob」与「Anna↔Carol」两条独立的边，绝不构成 Bob↔Carol 的关系。把共同成员暴露给彼此，等于凭空创造了一条社交连接——这既违反数据最小化，也会造成真实的社交事故（"你怎么把这张卡也给她了？"）。同理，成员**数量**也是信息，不能给 viewer。

**三个必须同时做到的层次**（只做第一层是最典型的错误实现）：

1. **API 过滤**：`GET /v1/cards/{id}/members` 按调用者角色裁剪响应（见 §6.2）。
2. **同步层过滤（关键）**：`card_member` 变更的 `audience` **只含 [owner, 该成员本人]**，绝不含其他 viewer——否则 Bob 的 Room 里会直接躺着 Carol 的成员行，UI 过滤得再干净也没用，本地数据库已经泄露了。
3. **字段级过滤**：`Card.member_count` **只对 owner 返回**，对 viewer 省略（§17.2）。owner 需要它来显示"这张卡共享给 N 人"和删除前的警告；viewer 拿到它就等于知道了还有几个人。

> 推论：**viewer 的本地库中，任何一张共享卡都恰好有 2 行 `card_members`**（owner 一行、自己一行）。这可以写成客户端的断言，也是 §13.4 集成测试的断言点。

> 这条收敛（C5）不只是省事：它使"卡的内容"始终有唯一权威来源，因此 §5.4 的同步协议在共享场景下**退化为单写者多读者**——这是分布式数据同步中最容易做对的一类模型。任何试图恢复 `editor` 的提案都必须先重读 §3.5 的冲突矩阵。

#### `friendships`

| 列 | 类型 | 说明 |
|---|---|---|
| `id` | UUID PK | |
| `user_low_id` / `user_high_id` | UUID FK | **规范化**：始终 `user_low_id < user_high_id`（按 UUID 字节序），保证一对用户只有一行 |
| `status` | TEXT | `pending` / `accepted` / `blocked` |
| `requested_by` | UUID FK | 谁发起的 |
| `blocked_by` | UUID FK NULL | 谁拉黑的 |
| `responded_at` | TIMESTAMPTZ NULL | |

唯一约束：`UNIQUE (user_low_id, user_high_id)`。

**好友关系的建立（v1.1：唯一路径）**

```
Anna 输入 Bob 的完整 username → GET /v1/users/lookup?username=bob_m
  → 命中：显示 {username} → Anna 点「发送好友请求」
  → POST /v1/friends/requests {user_id}  → friendships(pending, requested_by=anna)
  → Bob 收到同步 + 本地通知 → 接受 → status=accepted
```

**好友关系的终止 → 级联撤销共享（ADR-14，MUST 在同一事务内完成）**

`DELETE /v1/friends/{userId}`（解除）与 `POST /v1/friends/{userId}/block`（拉黑）**必须**执行完全相同的级联：

```sql
-- 单一事务
1. UPDATE/DELETE friendships                    -- 解除 → 删行；拉黑 → status='blocked'
2. UPDATE card_members SET left_at = now()
     WHERE left_at IS NULL
       AND (  (user_id = :a AND card_id IN (owner 为 :b 的卡))
           OR (user_id = :b AND card_id IN (owner 为 :a 的卡)) );   -- 双向
3. UPDATE share_invitations SET status='revoked'
     WHERE status='pending' AND {inviter,invitee} = {:a,:b};        -- 待处理邀请一并作废
4. INSERT change_log × N                        -- 成员墓碑，audience=[双方]
```

关键点：
- **双向**。Anna 可能既共享了卡给 Bob，也持有 Bob 共享的卡，两个方向都要撤销。
- **只撤销这一对用户之间的共享**。Bob 与 Carol 的共享不受影响，Anna 卡上的其他 viewer 也不受影响。
- **拉黑与解除效果一致**，差别只在 `friendships` 行的去留（拉黑保留 `blocked` 行以阻止再次请求）。
- 解除好友后重新加回**不会**恢复共享——owner 必须重新发起分享请求。这必须在 UI 二次确认中写明。
- 客户端在解除好友前**必须**调用 `GET /v1/friends/{userId}/shared-summary` 展示"这将取消 N 张共享卡（你分享给对方 X 张，对方分享给你 Y 张）"，用户确认后才提交。

#### `share_invitations`

| 列 | 类型 | 说明 |
|---|---|---|
| `id` | UUID PK | |
| `card_id` / `inviter_id` / `invitee_id` | UUID FK | inviter 必须是卡的 owner；invitee 必须是**已确认好友** |
| ~~`role`~~ | — | **v1.1 移除**：被分享者恒为 `viewer`，无角色可选（C7） |
| `status` | TEXT | `pending` / `accepted` / `declined` / `revoked` / `expired` |
| `expires_at` | TIMESTAMPTZ | 默认 14 天 |
| `responded_at` | TIMESTAMPTZ NULL | |

> 邀请**必须**经被邀请者接受才生成 `card_members` 行。不允许"直接塞卡进别人钱包"。
>
> **接受时必须重新校验**（MUST）：① 双方**仍**是 `accepted` 好友；② 卡仍存在且未被 owner 删除；③ 邀请未过期/未撤回；④ 成员数未超限。邀请发出到被接受之间可能间隔数天，任一条件失效即返回 `409` 并把邀请置为 `revoked`/`expired`。**漏掉 ① 会让"解除好友"的级联撤销出现绕过路径。**

#### `change_log`（同步引擎核心）

```sql
CREATE TABLE change_log (
  seq         BIGSERIAL PRIMARY KEY,
  entity_type TEXT        NOT NULL,   -- card | card_member | friendship | share_invitation | user_profile
  entity_id   UUID        NOT NULL,
  op          TEXT        NOT NULL,   -- upsert | delete
  audience    UUID[]      NOT NULL,   -- 应当看到此变更的 user_id 列表
  occurred_at TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp()
);
CREATE INDEX idx_change_log_audience ON change_log USING GIN (audience);
CREATE INDEX idx_change_log_seq_time ON change_log (seq, occurred_at);
```

**写入规则（MUST）**：任何对 `cards` / `card_members` / `friendships` / `share_invitations` 的写入，**必须**在**同一数据库事务内**写入对应的 `change_log` 行。这由 `Shared\Infrastructure\Doctrine\ChangeLogSubscriber` 统一处理，禁止业务代码手动拼装。

`audience` 计算：
- `card` 变更 → 该卡当前所有 `left_at IS NULL` 的成员（owner + 全部 viewer）。**卡的内容对所有成员一致，这是唯一的广播型记录。**
- `card_member` 新增 → **`[owner, 该成员本人]`**，**不含**其他 viewer（v1.1 成员可见性，§5.2）。
- `card_member` 移除 → **`[owner, 被移除者]`**，**不含**其他 viewer。被移除者据此收到成员墓碑 + 卡墓碑。
- `friendship` → 双方。
- **解除好友 / 拉黑（v1.1）** → 一次操作会产生**多条**记录：1 条 `friendship` 变更（audience = 双方）+ 每张受影响卡 1 条 `card_member` 墓碑。这些记录**必须**与业务写入同事务，且被移除方还需要**卡本身的墓碑**——否则它的 Room 里会留下一张没有成员关系的孤儿卡。
- **owner 删号 / 删卡（v1.1）** → `card` 删除记录的 audience **必须**是**删除前**的成员快照。这是最容易写错的一处：若先删 `card_members` 再算 audience，viewer 将永远收不到墓碑，卡会永久残留在他们的本地库里。

> **实现提示**：把"先快照 audience，再执行删除"固化在 `ChangeLogSubscriber` 里，不要留给业务代码。§13.4 的 "change_log audience 测试" 必须专门覆盖这两条级联路径。

> ⚠️ **两条规则的不对称是有意的，不是笔误**：`card` 走广播（全体成员），`card_member` 走点对点（owner + 当事人）。写 `ChangeLogSubscriber` 时**必须**为这两类实体分别实现 audience 计算，**不得**图省事复用同一个"取该卡全部成员"的函数——那正是泄露 viewer 名单的方式，而且泄露发生在数据库里，任何前端修补都无法挽回。

保留期：**90 天**，每日清理任务删除更早记录。客户端游标早于保留窗口 → 强制全量重同步。

#### `audit_log`

| 列 | 说明 |
|---|---|
| `id` / `occurred_at` | |
| `actor_user_id` | 可为 NULL（系统操作） |
| `action` | `login_success` / `login_failed` / `session_revoked` / `username_set` / `card_shared` / `share_accepted` / `share_declined` / `member_removed` / `member_left` / `shares_revoked_by_unfriend` / `friend_removed` / `friend_blocked` / `account_deletion_requested` / `account_hard_deleted` / `data_exported` / `key_rotated`（v1.1：移除 `ownership_transferred`，新增级联撤销与 username 相关动作） |
| `target_type` / `target_id` | |
| `metadata` | JSONB，**不得**包含码值、邮箱明文、备注内容 |

保留期 **12 个月**（安全事件调查所需，GDPR Art.6(1)(f) 正当利益）。

### 5.3 加密方案（信封加密）

**总体**

```
明文 payload ──► Vault Transit `encrypt` (key: ncards-card-v1) ──► "vault:v1:BASE64" ──► Postgres TEXT
读取时反向；Vault 内部密钥永不出 Vault。
```

**为什么用 Vault Transit 而不是应用层自管 DEK**
- 条码 payload 极小（< 100 字节），无需分层 DEK 优化。
- Transit 原生支持密钥版本化、`rotate`、`rewrap`（重加密无需解密到应用侧）。
- 应用永远拿不到密钥材料，只拿加解密能力。

**性能**：钱包列表可能有 50–200 张卡。**必须**使用 Vault Transit 的 **batch 接口**（`/v1/transit/decrypt/<key>` 的 `batch_input`），一次请求解密全部。禁止在循环中逐条调用 Vault。
- 目标：200 条 batch decrypt P95 < 80 ms（同主机容器内网）。
- 缓存策略：**不缓存明文**。若压测不达标，允许在单个 HTTP 请求生命周期内的进程内 memo（请求结束即销毁），不得跨请求缓存。

**密钥清单**

| 密钥 | 用途 | 类型 | 轮换周期 |
|---|---|---|---|
| `transit/ncards-card` | 卡 payload、note | AES-256-GCM96 | 12 个月，或事故时立即 |
| `transit/ncards-pii` | `users.email_encrypted` | AES-256-GCM96 | 12 个月 |
| `transit/ncards-hmac` | email_hash / code_hash / fingerprint 的 pepper | HMAC-SHA256 | **不轮换**（轮换会使 hash 失效，需专门迁移流程） |
| JWT 签名密钥 | Access Token（EdDSA Ed25519） | 存 Vault KV | 6 个月，双密钥重叠期 24h |

**轮换流程（必须写成 runbook）**
1. `vault write -f transit/keys/ncards-card/rotate` → 产生 v2。
2. 新写入自动用 v2。
3. 后台 Messenger 任务 `RewrapCardSecrets` 分批调用 Transit `rewrap`（无需解密），更新密文列。
4. 全部 rewrap 完成后 `min_decryption_version` 提升到 2。
5. 记录 `audit_log(action='key_rotated')`。

**明确的威胁边界**（复述 §3.3，必须在安全文档与对外文案中保持一致）

| 场景 | 是否防护 |
|---|---|
| Postgres 数据文件 / 备份文件被窃 | ✅ |
| Postgres 只读凭据泄露、SQL 注入读表 | ✅ |
| 运维误操作 `pg_dump` 外发 | ✅ |
| 硬盘报废未擦除 | ✅ |
| **应用主机被完全控制（RCE / SSH 沦陷）** | ❌ 攻击者可通过应用的 AppRole 调用 Vault 解密 |
| **恶意/被胁迫的管理员** | ❌ |
| Android 设备被 root 且解锁 | ❌（见 §3.4 取舍） |

### 5.4 同步协议与冲突解决

#### 5.4.1 游标语义

游标是 base64url 编码的 JSON：`{"seq": 123456, "sv": 1}`（`sv` = schema_version）。

**读取查询**

```sql
SELECT seq, entity_type, entity_id, op
FROM change_log
WHERE audience @> ARRAY[:user_id]::uuid[]
  AND seq > :since_seq
  AND occurred_at < now() - INTERVAL '2 seconds'   -- 稳定窗口
ORDER BY seq
LIMIT :limit;
```

**为什么需要 2 秒稳定窗口**：`BIGSERIAL` 在并发事务下可能出现"较小的 seq 后提交"，直接按 seq 推进游标会漏读。事务时长远小于 2 秒，因此该窗口足以保证不漏。代价是同步延迟固定增加 ≤2 秒（在 10 秒 SLO 内可接受）。

> 若未来延迟要求收紧，替代方案是基于 `pg_snapshot_xmin()` 的水位线；一期不引入这个复杂度。

**去重与折叠**：同一 `entity_id` 在窗口内多次变更时，服务端只返回**该实体的当前状态**一次（按最大 seq 折叠）。游标推进到本批最大 seq。

**全量重同步触发条件**（返回 `409` + `{"code":"full_resync_required"}`）：
- `since_seq` < `change_log` 中最小 seq（超出 90 天保留期）
- 客户端 `sv` 与服务端不兼容
- 服务端检测到该用户的 change_log 有过清理/修复

#### 5.4.2 响应结构

```jsonc
{
  "changes": {
    "cards":              [ { /* 完整卡实体 */ } ],
    "card_tombstones":    [ "uuid", "uuid" ],
    // card_members：由 audience 天然裁剪——viewer 只会收到 owner 与自己的行（C11）
    "card_members":       [ { "card_id": "...", "user_id": "...", "role": "viewer", ... } ],
    "member_tombstones":  [ { "card_id": "...", "user_id": "..." } ],
    "friendships":        [ { ... } ],
    "friendship_tombstones": [ "uuid" ],
    "share_invitations":  [ { ... } ],
    "users":              [ { "id": "...", "username": "anna_b" } ]  // 仅好友与共享卡 owner 的最小资料
  },
  "next_cursor": "eyJzZXEiOjEyMzQ1Niwic3YiOjF9",
  "has_more": false,
  "server_time": "2026-08-24T10:15:30Z"
}
```

`has_more = true` 时客户端**必须**立即用 `next_cursor` 继续拉取，直到 `false`，并**在全部拉完后**才提交 Room 事务？— **不**：分页各批独立提交（避免大事务），但游标只在每批成功写入后才持久化。这保证崩溃后从上一批续传，最坏情况是重放（幂等 upsert，安全）。

#### 5.4.3 上行写入与冲突

> **v1.1 前提**：由于只有 owner 能写卡（C5），本节的冲突场景**只发生在同一 owner 的多台设备之间**。viewer 客户端除 `placement` 外不产生任何上行写入（§3.5）。

**创建**：`POST /v1/cards`，body 含客户端生成的 `id`。若 `id` 已存在且属于同一用户 → 返回 `200` + 现有实体（幂等）。若属于他人 → `409 id_conflict`（客户端重新生成 id 重试，UUIDv7 碰撞概率可忽略，此分支仅为防御）。

**更新**：`PATCH /v1/cards/{id}`，header `If-Match: "<revision>"`。
- 匹配 → 应用，`revision += 1`，返回 `200` + 新实体。
- 不匹配 → `409` + body 含 `{"code":"revision_conflict","current": {/* 服务端实体 */}}`。

**客户端冲突解决（MUST 实现）**

```
本地保存三份：base（上次同步成功的服务端态）、local（当前本地态）、remote（409 返回的服务端态）

for each field:
    if local[f] == base[f]:            → 取 remote[f]（本地没改）
    else if remote[f] == base[f]:      → 取 local[f]（对方没改）
    else:                              → 真冲突
                                          if f == barcode_value or barcode_format:
                                              → 触发「冲突副本」流程
                                          else:
                                              → 取 remote[f]，记录一条待展示的提示

若无真冲突 → 用合并结果重试 PATCH（If-Match = remote.revision），最多重试 2 次
若有非码值真冲突 → 应用 remote，UI 顶部 Snackbar：
                   "„{title}" wurde auf einem anderen Gerät geändert."
                   （v1.1：文案为**设备**口径而非人名口径——共享卡只有 owner 能改，
                     冲突方必然是自己的另一台设备）
若有码值真冲突   → 应用 remote 到原卡；同时用本地 barcode_value 新建一张卡，
                   title = "{原标题} (Konflikt)"，仅自己可见（不继承成员），
                   并弹出说明对话框让用户自行取舍后删除多余的一张
```

**为什么码值特殊**：title/color 改错了用户能一眼看出并改回；码值改错了、原值丢了，用户只能重新翻实体卡。不可再生的数据不允许静默丢弃。

**删除**：`DELETE /v1/cards/{id}`（仅 owner）→ 软删 + 墓碑（audience 取删除前的成员快照）。客户端收到墓碑即物理删除本地行。**owner 删卡会使全部 viewer 失去该卡**，因此删除确认对话框必须显示"该卡正共享给 N 位好友，他们也将失去它"（成员数为 0 时不显示此句）。

**退出共享**：`DELETE /v1/cards/{id}/members/{myUserId}`（viewer 移除自己）→ 只写 `left_at`，卡本身不变。owner 侧收到成员移除记录，viewer 侧收到成员墓碑 + 卡墓碑。

**离线队列（Outbox）**

```
Room 表 sync_outbox(id, entity_type, entity_id, op, payload_json, attempt_count,
                    next_attempt_at, last_error, created_at)
```
- 同一实体的多次修改在入队时**合并**（同 entity_id 的 pending upsert 只保留最新）。
- 重试：指数退避 `min(2^n * 5s, 30min)`，最多 10 次后标 `FAILED` 并在 UI 提示。
- `4xx`（除 409/429）不重试，直接 `FAILED` + 上报 Sentry（说明是客户端 bug）。
- outbox 推送顺序：先 `card` 后 `card_member`，保证引用完整性。

---

## 6. API 设计

### 6.1 通用约定

| 项目 | 约定 |
|---|---|
| 基址 | `https://api.n-cards.de/v1`（staging：`https://api.staging.n-cards.de/v1`） |
| 传输 | 仅 HTTPS/TLS 1.3（TLS 1.2 作为最低回退），HSTS `max-age=63072000; includeSubDomains; preload` |
| 编码 | `application/json; charset=utf-8`，字段名 `snake_case` |
| 认证 | `Authorization: Bearer <access_jwt>` |
| 幂等 | 所有 `POST` 支持 `Idempotency-Key` header（UUID），Redis 存 24h。创建类接口另有客户端生成 ID 的天然幂等 |
| 分页 | 游标式：`?cursor=&limit=`（默认 50，最大 200）。**不使用** offset |
| 版本 | 路径版本 `/v1`。仅做向后兼容变更（§13.6） |
| 时间 | RFC 3339 UTC |
| 追踪 | 请求头 `X-Request-Id`（客户端可选提供，否则服务端生成），响应回显；写入所有日志 |
| 客户端标识 | `X-Client: android/1.4.0 (26)` — 必填，用于强制升级判定与指标切分 |
| 压缩 | `gzip` / `br` |

**错误格式**：RFC 9457 Problem Details

```json
{
  "type": "https://api.n-cards.de/problems/revision-conflict",
  "title": "Revision conflict",
  "status": 409,
  "code": "revision_conflict",
  "detail": "The card was modified by another member.",
  "instance": "/v1/cards/0192f3a1-...",
  "request_id": "0192f3a1-b2c3-7d4e-8f01-23456789abcd",
  "errors": [ { "field": "title", "code": "too_long", "message": "..." } ],
  "current": { }
}
```

- `code` 是**机器可读的稳定标识**，客户端只能对 `code` 分支，不得解析 `detail`。
- `detail` 为英文开发者文案；**面向用户的文案一律由客户端本地化生成**（服务端不返回可展示的德语文案）。

**统一错误码表**

| HTTP | code | 含义 | 客户端应对 |
|---|---|---|---|
| 400 | `validation_failed` | 字段校验失败 | 显示字段错误 |
| 400 | `malformed_request` | 请求体不是合法 JSON / 不是 JSON 对象 / 为空 | 客户端 bug，上报 Sentry |
| 401 | `token_expired` | Access token 过期 | 静默刷新后重试一次 |
| 401 | `token_invalid` | 令牌无效/会话已撤销 | 清空本地会话，跳登录 |
| 403 | `insufficient_role` | 角色不足（viewer 试图改卡/删卡/邀请成员） | 提示只读；**同时上报 Sentry**——正常 UI 不应产生此请求 |
| 403 | `not_a_member` | 无权访问该卡 | 从本地删除该卡 |
| 403 | `username_required` | 注册未完成（`username IS NULL`） | 跳转 username 设定页 |
| 403 | `not_friends` | 共享操作要求双方是已确认好友 | 提示需先加为好友；刷新好友列表 |
| 404 | `not_found` | 含 username 查无此人 | 显示"未找到该用户" |
| 405 | `method_not_allowed` | 路由存在但方法不允许 | 客户端 bug，上报 Sentry |
| 409 | `revision_conflict` | 乐观锁失败 | 走冲突解决（§5.4.3） |
| 409 | `full_resync_required` | 游标失效 | 清库全量重同步 |
| 409 | `already_exists` | 好友已存在 / 已是成员 / 已有待处理邀请 | 幂等处理 |
| 409 | `username_taken` | username 已被占用 | 提示重新输入 |
| 409 | `username_immutable` | 试图修改已设定的 username | 客户端 bug，上报 Sentry |
| 409 | `id_conflict` | 客户端生成的 id 已属于他人（§5.4.3） | 重新生成 id 重试 |
| 409 | `idempotency_in_progress` | 同一 `Idempotency-Key` 的前一次请求仍在处理中（响应带 `Retry-After`） | 退避重试（outbox 本就重试 409） |
| 413 | `payload_too_large` | 请求体超过上限 | 客户端 bug，上报 Sentry |
| 415 | `unsupported_media_type` | `Content-Type` 不是 `application/json` | 客户端 bug，上报 Sentry |
| 422 | `username_invalid` | 不符字符集/长度/保留词 | 显示具体规则 |
| 422 | `limit_exceeded` | 触达系统限额 | 显示限额说明 |
| 422 | `idempotency_key_reused` | 同一 `Idempotency-Key` 配了不同的请求体 | 客户端 bug，**不重试**，上报 Sentry |
| 426 | `client_too_old` | 低于最低支持版本 | 强制升级墙 |
| 429 | `rate_limited` | 限流（响应带 `Retry-After`） | 退避重试 |
| 500 | `internal_error` | 未预期的服务端故障（`detail` 恒为固定文案） | 提示稍后重试；上报 Sentry |
| 503 | `service_unavailable` | 维护中（响应带 `Retry-After`） | 显示维护页 |

> **T-004 的扩表说明**：`malformed_request` / `method_not_allowed` / `id_conflict` /
> `idempotency_in_progress` / `payload_too_large` / `unsupported_media_type` /
> `idempotency_key_reused` / `internal_error` 是 T-004 补入的。§13.6 允许新增错误码
> （向后兼容），但**禁止**改变已有 code 的含义。
> 其中 `id_conflict` 原本就在 §5.4.3 里定义过，只是本表漏了 —— 属于修正规格自相矛盾。
>
> 本表是 Android 侧 T-010 生成 `ApiError` sealed class 的**唯一输入**。
> 后端的落地是 `App\Shared\Domain\Error\ErrorCode`，两者由
> `backend/tests/Unit/Shared/Domain/Error/ProblemDetailsSchemaTest` 与
> `docs/api/schemas/problem-details.schema.json` 三方钉死，改一处不改另两处会 CI 红。

**`errors[].code` 词表**（T-004 补：原文只用 `too_long` 举了个例子，没有成表；
§13.6 的「禁止改变 code 含义」对这一层同样成立，所以必须列全）

| code | 含义 |
|---|---|
| `required` | 必填字段缺失或为 null |
| `too_short` | 长度/元素个数低于下限 |
| `too_long` | 长度/元素个数超过上限 |
| `invalid_format` | 不符合字符集或格式（UUID、RFC 3339 时间、`X-Client`……） |
| `invalid_type` | JSON 类型不对 |
| `out_of_range` | 数值超出允许区间 |
| `not_unique` | 唯一性冲突的字段级形态 |
| `unknown_field` | 请求体里出现本端点不认识的字段 |
| `unsupported_parameter` | 参数被本 API 明确不支持（如 `?offset=`） |

**通用列表信封**（T-004 补：§6.1/§6.2 原本从未明说；§5.4.2 的同步响应已在用
`next_cursor` / `has_more`，此处只是把它推广到全部列表端点，唯一的新名字是 `items`）

```json
{ "items": [ ], "next_cursor": "eyJ2IjoxLC...", "has_more": true }
```

`has_more` 为 `false` 时 `next_cursor` 恒为 `null`。

**Content-Type 的澄清**（T-004）：上表「编码」一行的
`application/json; charset=utf-8` 适用于**成功响应**；错误响应用 RFC 9457 规定的
`application/problem+json`，该媒体类型**不带** charset 参数（JSON 按定义就是 UTF-8）。

**`Idempotency-Replayed`**（T-004 新增的响应头）：命中幂等回放时为 `true`。
标准里没有这个 header，是本项目自定义的 —— 客户端据此区分「真的执行了」与「拿到了回放」。

### 6.2 端点清单

#### 认证（无需 Bearer）

| 方法 | 路径 | 说明 |
|---|---|---|
| `POST` | `/v1/auth/otp/request` | body `{email, locale}` → **恒** `202 {challenge_id, expires_at, resend_after_seconds}` |
| `POST` | `/v1/auth/otp/verify` | body `{challenge_id, code, device:{id,platform,model,os_version,app_version}}` → `200 {access_token, expires_in, refresh_token, user}` |
| `POST` | `/v1/auth/magic/consume` | body `{token, device}`（Magic Link 消费，见 §7.1）。⚠️ 契约要求 `device` —— 它签发的是一次真实登录，所以**调用方是 App 而不是落地页**（[ADR-0016](adr/0016-magic-link-delivery-and-landing-page.md)） |
| `POST` | `/v1/auth/token/refresh` | body `{refresh_token}` → 轮换后的新令牌对 |
| `POST` | `/v1/auth/logout` | 需 Bearer。撤销当前会话 |

#### 我 / 设备

| 方法 | 路径 | 说明 |
|---|---|---|
| `GET` | `/v1/me` | 用户资料（含 `username`、`onboarding_complete`） |
| `POST` | `/v1/me/username` | **一次性**设定 username。body `{username}` → `200 {user}`；`409 username_taken` / `409 username_immutable` / `422 username_invalid`。**无 `PATCH`/`PUT` 对应端点——不可变是靠"没有这个端点"保证的** |
| `PATCH` | `/v1/me` | `locale`（v1.1：**已无 `display_name`**；若请求体出现 `username` 字段 → `409 username_immutable`，不静默忽略）。⚠️ **不在 onboarding 白名单里** —— 未设 username 的用户调它得到 `403 username_required`，豁免清单只有 `GET /me`。**通知偏好延后**：本表原先列了它，但全仓库没有任何数据模型（§17.1 的 `users` DDL、契约的 `User`、`Notification` 模块里都没有），M1 也没有任何用户可关的通知（OTP 信与新设备提醒信都是安全类）。§13.6 允许后续作为**可选字段**新增，见 T-108 落地记录与 [ADR-0018](adr/0018-onboarding-interceptor-placement-and-the-first-inverted-shared-port.md) |
| `GET` | `/v1/me/devices` | 设备列表（含当前设备标记） |
| `DELETE` | `/v1/me/devices/{id}` | 远程登出某设备 |
| `PUT` | `/v1/me/devices/{id}/push-token` | 更新 FCM token |
| `POST` | `/v1/me/export` | 发起 GDPR 导出 → `202 {job_id}` |
| `GET` | `/v1/me/export/{job_id}` | 导出状态 / 下载链接 |
| `POST` | `/v1/me/deletion` | 发起删号 → `200 {scheduled_for}`（v1.1：**无 `ownership_transfers`**，不存在转让） |
| `GET` | `/v1/me/deletion/preview` | **删号前**预览影响面 → `{owned_cards_count, shared_cards_count, affected_friends_count, cards_i_will_lose_count}`。UI **必须**先调此接口并展示（§3.7） |
| `DELETE` | `/v1/me/deletion` | 取消删号 |

#### 卡

| 方法 | 路径 | 说明 |
|---|---|---|
| `GET` | `/v1/cards` | 全量列表（首次登录用），游标分页 |
| `POST` | `/v1/cards` | 创建（body 含客户端生成 `id`） |
| `GET` | `/v1/cards/{id}` | 单卡 |
| `PATCH` | `/v1/cards/{id}` | **仅 owner**（viewer → `403 insufficient_role`）；需 `If-Match: "<revision>"` |
| `DELETE` | `/v1/cards/{id}` | 仅 owner。会使全部 viewer 失去该卡（墓碑） |
| `PUT` | `/v1/cards/{id}/placement` | 当前用户对该卡的 `sort_order` / `is_pinned`（**不走** revision 锁，成员私有字段）。**owner 与 viewer 均可调用**——这是 viewer 唯一的写入端点 |

#### 共享

| 方法 | 路径 | 说明 |
|---|---|---|
| `GET` | `/v1/cards/{id}/members` | **响应随调用者角色裁剪**（§5.2）：owner → 全部成员；viewer → **仅 owner 与自己两行**。服务端在查询层裁剪，**不是**返回全量再让客户端过滤 |
| `POST` | `/v1/cards/{id}/invitations` | 仅 owner。body `{invitee_user_id}`（**无 `role`**，恒为 viewer）；invitee 必须是已确认好友，否则 `403 not_friends` |
| `DELETE` | `/v1/cards/{id}/invitations/{invId}` | owner 撤回邀请 |
| `DELETE` | `/v1/cards/{id}/members/{userId}` | owner 移除某 viewer，或 viewer 移除自己（退出）。**owner 不可移除自己** → `403` |
| `GET` | `/v1/invitations` | 我收到的待处理共享邀请 |
| `POST` | `/v1/invitations/{id}/accept` | 接受（服务端重校验好友关系等四项，见 §5.2） |
| `POST` | `/v1/invitations/{id}/decline` | 拒绝 |

**v1.1 已移除的端点**（若在旧契约/旧代码中出现，必须删除，不得保留为 410）：

| 已移除 | 原因 |
|---|---|
| `PATCH /v1/cards/{id}/members/{userId}`（改角色） | 只剩一种可授予角色，无可改 |
| `POST /v1/cards/{id}/ownership`（转让） | C8：不作转让 |

#### 好友

| 方法 | 路径 | 说明 |
|---|---|---|
| `GET` | `/v1/users/lookup` | `?username=<精确值>` → `200 {user_id, username}` 或 `404 not_found`。**精确匹配、单次查询**；服务端**不得**实现前缀/模糊匹配，客户端**不得**在输入过程中调用（§3.8 C4）。限速见 §7.5 |
| `GET` | `/v1/friends` | 已确认好友 |
| `GET` | `/v1/friends/requests` | `?direction=incoming\|outgoing` |
| `POST` | `/v1/friends/requests` | body `{user_id}`（由 `/users/lookup` 得到）→ `201`/`409 already_exists`。**不再接受 `{email}`** |
| `POST` | `/v1/friends/requests/{id}/accept` / `/decline` | |
| `GET` | `/v1/friends/{userId}/shared-summary` | 解除前的影响预览 → `{shared_by_me, shared_with_me}`（两个计数）。UI **必须**先调此接口 |
| `DELETE` | `/v1/friends/{userId}` | 解除好友 → **级联取消双方之间的全部共享**（§5.2 事务），响应回传 `{revoked_shares}` 供 UI 确认展示 |
| `POST` | `/v1/friends/{userId}/block` / `DELETE` 同路径 | 拉黑 / 解除拉黑。**拉黑执行与解除好友完全相同的级联** |

> **设计决策（v1.1，与 v1.0 相反）**：解除好友**自动且立即**取消双方之间的全部共享。
>
> v1.0 曾担心"误触解除好友导致对方突然失去超市会员卡"。但把授权基础（好友）与授权本身（共享）解耦，会产生一个用户无法理解也无法审计的状态："我们已经不是好友了，为什么他还能看我的卡？"——而且这个残留权限**没有任何 UI 入口能让用户发现**（好友列表里已经没有这个人了）。权限残留是比"误触丢卡"严重得多的问题，何况码值本身在实体卡上、可重新扫描。
>
> 因此改为级联，并用**事前告知**消化误触风险：解除确认对话框必须先调 `shared-summary` 并明示"这将取消 N 张共享卡，且重新加为好友也不会自动恢复"。

**v1.1 已移除的端点**：

| 已移除 | 原因 |
|---|---|
| `POST /v1/friends/invite-links` | C2：取消邀请链接 |
| `GET /v1/friends/invite-links/{code}` | 同上 |
| `POST /v1/friends/invite-links/{code}/redeem` | 同上 |
| `POST /v1/friends/requests`（`{email}` 形态） | C2：邮箱退出社交路径，改为 `{user_id}` |

> 连带影响：`https://app.n-cards.de/l/*` 的 App Links **只剩 Magic Link 一种用途**（`/l/magic/*`），好友邀请落地页与其兑换流程整体删除。`assetlinks.json` 仍需保留（§7.1）。

#### 同步

| 方法 | 路径 | 说明 |
|---|---|---|
| `GET` | `/v1/sync?cursor=&limit=` | 增量同步（§5.4） |
| `GET` | `/v1/sync/bootstrap` | 全量初始快照 + 起始游标（首次登录 / full_resync） |

#### 元信息（无需认证）

| 方法 | 路径 | 说明 |
|---|---|---|
| `GET` | `/v1/config` | `{min_supported_client, latest_client, maintenance:{active,message_key,retry_after}, feature_flags:{}}`。T-112 落地时把三处语义定死：① `maintenance.message_key` 取值仅 `maintenance.scheduled`（窗口 24h 内将开始）/ `maintenance.in_progress` / `null`，窗口两头来自 env（RFC 3339，offset 必填），三个字段全部按服务端时钟派生；② `retry_after` 是 §6.1 那个 `Retry-After` 的同义物，**仅 `active` 为 true 时有值**（到窗口结束的秒数）—— 提前公告阶段刻意不给，横幅里的时间由客户端按本节固定的窗口写死；③ **`feature_flags` 永不下发 §7.5 的限额数字**（T-111 移交笔记）。⚠️ 本端点免认证但**不免 `X-Client`**：过旧客户端在这里拿到的是 `426`，而那正是强制升级墙的信号源（T-158），不是缺陷。它也是整个 `/v1` 里**唯一可缓存**的响应（`public, max-age=60` + `Vary: X-Client`） |
| `GET` | `/health/live` / `/health/ready` | 探活 / 就绪（不在 `/v1` 下，不对外暴露细节） |

### 6.3 关键流程时序

#### 6.3.1 Email OTP 登录

```
Client                          API                       Vault      ESP
  │ POST /auth/otp/request {email:"a@b.de"}
  ├──────────────────────────────►│
  │                               │ 限流检查（email_hash + IP + 全局）
  │                               │ email_hash = HMAC(email)  ◄──pepper──┤
  │                               │ ⚠️ **不查 users** —— 无分支【注1】
  │                               │ 建 challenge（含 email_encrypted + locale）
  │                               │ 生成 6 位码
  │                               │ Messenger async: SendOtpEmail
  │◄──────────────────────────────┤ 202 {challenge_id, expires_at, resend_after:60}
  │                               │                                  ├──►│
  │                               │                                      │ 邮件送达
  │ 用户输入 6 位码
  │ POST /auth/otp/verify {challenge_id, code, device}
  ├──────────────────────────────►│
  │                               │ 常量时间比较 code_hash
  │                               │ attempts++；>5 或过期 → 401【注3】
  │                               │ 成功 → upsert user（首次即注册）
  │                               │        创建 device + session
  │                               │        签发 JWT(15min) + refresh(90d)
  │                               │        audit_log(login_success)
  │                               │        若为新设备 → 通知其他设备 + 提醒邮件
  │◄──────────────────────────────┤ 200 {access_token, refresh_token, user}
  │
  │ 【v1.1】若 user.username == null（首次注册）
  │ POST /v1/me/username {username:"anna_b"}
  ├──────────────────────────────►│ 归一化(trim+toLowerCase ROOT)
  │                               │ 校验字符集/长度/保留词 → 422
  │                               │ UNIQUE 冲突 → 409 username_taken
  │                               │ 已有 username → 409 username_immutable
  │◄──────────────────────────────┤ 200 {user}   ← 此后才可访问其他端点
```

**注1（v1.1 修订，[ADR-0014](adr/0014-otp-always-sends-a-code.md)）**：**无论邮箱是否注册，都真发一封验证码信。**
服务端在这条路径上不查 `users`，因此在结构上就无法按存在性分支 —— 这比原来的
「建哑挑战 + 配平两条路径的做功与耗时」更强，也更难写错。

原设计（哑挑战 `is_decoy`、不发信）取消的直接原因是：**它让注册变得不可能**。
未注册的邮箱收不到码 → 走不到 verify → 而 §5.2 与本节都规定「首次验证成功即注册」，
且契约里没有第二条注册路径。

代价是 §3.1 那句「用户无法让系统向任意第三方发信」不再成立，准确的表述变成
「**只能向任意邮箱发一封 OTP，且每邮箱每天 10 封封顶**」（§7.5 的三个窗口，未放宽）。
T11 的攻击面因此从「对自有邮箱的轰炸」回到「对任意邮箱、但被三个窗口夹住的轰炸」。

**注3**：五种拒绝形状（码错 / 过期 / 已消费 / 次数耗尽 / 上个版本留下的哑挑战）
返回**逐字相同**的 401，且做功与耗时相同。ADR-0014 之后攻击面搬到了这里：
攻击者能对任意邮箱拿到一个真实的 `challenge_id`，再用错码来问
「这个邮箱注册过吗」。挡它的是「拒绝路径不查 `users`」+ `ncards.otp.verify_budget_ms` 的耗时填充。

**注2（v1.1）**：`otp/verify` 成功即创建用户行，但在 `username` 设定前该用户处于 `onboarding_incomplete`——除 `GET /v1/me`、`POST /v1/me/username`、`POST /v1/auth/logout` 外一律 `403 username_required`（§5.2）。**已注册用户的后续登录不经过 username 步骤**（`username` 已非空）。

#### 6.3.2 首次登录后的数据装载

```
POST /auth/otp/verify → 200
  → GET /v1/sync/bootstrap        （全量：cards + members + friends + 起始 cursor）
  → 写入 Room（单事务）
  → 持久化 cursor
  → PUT /v1/me/devices/{id}/push-token
  → 进入钱包
```

#### 6.3.3 共享一张卡（v1.1：无角色选择，需对方接受）

```
【前置】Anna 与 Bob 互为 accepted 好友（经 username 精确搜索建立）

Anna: GET /v1/friends                          → 选择 Bob（UI 无角色选择控件）
Anna: POST /v1/cards/{id}/invitations {invitee_user_id: bob}
        服务端: 校验 ① Anna 是该卡 owner（非 owner → 403 insufficient_role）
                    ② Bob 是 accepted 好友（否则 403 not_friends）
                    ③ 成员数 < 20  ④ 无重复 pending 邀请
        → 建 share_invitation(pending) + change_log(audience=[anna,bob])
        → FCM(bob) {"t":"sync","v":1}
Bob:  收到静默推送 → GET /v1/sync → 本地出现 pending invitation
      → 本地生成通知 "Anna möchte eine Karte mit dir teilen"
Bob:  【选择接受或拒绝】
      ├ POST /v1/invitations/{id}/accept
      │   服务端: 重校验 ①仍是好友 ②卡未删 ③未过期 ④成员数未超限
      │           → 建 card_members(bob, role='viewer') + change_log(audience=[anna,bob])
      │           → FCM(anna, bob)
      │   Bob:  GET /v1/sync → 卡进入钱包，带「共享 · 只读」徽章 + "von anna_b geteilt"
      │         → 编辑/删除入口在 UI 层即不可见
      │   Anna: GET /v1/sync → 成员列表出现 Bob
      └ POST /v1/invitations/{id}/decline
          服务端: status='declined' + change_log(audience=[anna,bob])
          → Anna 侧显示"Bob 已拒绝"，可再次发起
```

**此后的单向同步**：Anna 每次 `PATCH` → Bob 收到新版本。Bob 无任何写回路径。Bob 唯一能改的是自己钱包里这张卡的 `sort_order` / `is_pinned`（`PUT /v1/cards/{id}/placement`，成员私有，不参与 revision）。

---

## 7. 安全

### 7.1 认证与会话

**OTP 参数（MUST）**

| 项 | 值 |
|---|---|
| 码长 | 6 位数字（`random_int`，CSPRNG） |
| 有效期 | 10 分钟 |
| 最大尝试 | 5 次（超过即作废整个 challenge） |
| 存储 | `HMAC-SHA256(code, pepper)`，pepper 在 Vault；**不存明文** |
| 比较 | `hash_equals()` 常量时间 |
| 重发间隔 | 60 秒 |
| 单次登录只允许一个活跃 challenge | 新建时作废该 email 的旧 challenge |

**Magic Link 的关键陷阱（MUST 正确实现）**

企业邮件安全网关（Microsoft Defender、Barracuda 等）会**自动 GET 邮件里的所有链接**做扫描。若 Magic Link 是 `GET` 即消费，用户还没点开链接就已失效。

因此：
1. 邮件中的链接指向一个 **App Link / 落地页**，`GET` 只渲染"点击继续登录"按钮，**不消费令牌**。
2. 实际消费走 `POST /v1/auth/magic/consume`。
3. 落地页对 `HEAD`、预取（`Purpose: prefetch`）请求不做任何状态变更。
4. Android 端注册 App Links（`https://app.n-cards.de/l/*`，配合 `assetlinks.json`），已安装 App 直接拉起。

> **[ADR-0016](adr/0016-magic-link-delivery-and-landing-page.md)（T-106 落地）把上面四条钉死成了具体形态：**
>
> - **落地页是一份静态 HTML**，由 Caddy 上一个独立的 `app.n-cards.de` 站点块
>   `file_server` 出去，**没有 `reverse_proxy`**。后端在 `/l/` 下不注册任何路由，
>   `tests/Api/RouteInventoryTest` 断言这一点。于是第 1 与第 3 条不再是「要记得
>   别在那儿改状态」的约定，而是**没有代码可以违反**的结构事实 ——
>   与 ADR-0014 把防枚举升级成「服务端根本没查」是同一步棋。
> - **POST 的发起者是 App，不是落地页。** 落地页的按钮是一次 `intent://` 交接。
>   浏览器构造不出合法请求体：`device.platform` 的取值域只有 `android`，
>   `device.id` 是安装级的客户端生成 UUID，而 refresh token 按本节只存在
>   EncryptedSharedPreferences 里。
> - **令牌与 6 位码在同一条挑战上**（`otp_challenges` 一行同时挂 `code_hash` 与
>   `magic_token_hash`，共用一个 `consumed_at`）。它们是同一次登录的两个入口，
>   不是两次机会：用掉任何一个，另一个立刻 401。
> - **消费端点不做恒定耗时填充，也没有 `attempts` 计数。** 两者在这里都没有对象：
>   令牌 2^256 种，编不出来；而猜错的令牌**找不到任何一行**可以累加。
>   完整论证见 ADR-0016 的 Alternatives ⑤。
> - `magic_token_hash` 存的是**本地 SHA-256**，不是 Vault HMAC —— 与同一行上的
>   `code_hash` 口径不同，理由与 `sessions.refresh_token_hash` 相同（§17.1 的注释）。

**令牌**

| 令牌 | 格式 | 有效期 | 存储 |
|---|---|---|---|
| Access | JWT，EdDSA (Ed25519)，claims `sub, sid, did, jti, iat, exp` | 15 分钟 | 客户端内存 + EncryptedSharedPreferences |
| Refresh | 32 字节随机（Base64url），**不透明** | 90 天滑动 | EncryptedSharedPreferences，仅此一处 |

**Refresh 轮换与重放检测（MUST）**
- 每次刷新签发新 refresh token，旧 token 立即失效，`previous_token_hash` 记录。
- 若收到已被使用过的 refresh token → 判定为**令牌被窃**：撤销该会话家族全部令牌、写 `audit_log(reuse_detected)`、告警、给用户发安全提醒邮件。
- 服务端**不**做 access token 黑名单（15 分钟窗口可接受），但会话撤销后 refresh 立即失效。

**新设备登录的防护**
- 成功登录新设备 → 向该用户所有既有设备发推送 + 向邮箱发送提醒信（含设备型号、大致时间、粗粒度地区）。
- 邮件内提供"这不是我"的一键撤销链接（同样走 POST 确认）。

### 7.2 威胁模型（STRIDE）

| # | 类别 | 威胁 | 影响 | 缓解 | 状态 |
|---|---|---|---|---|---|
| T01 | Spoofing | 攻击者用他人邮箱请求 OTP 并猜码 | 账号接管 | 6 位码 + 5 次尝试上限 + 10 分钟 + 每邮箱限速 → 猜中概率 < 5/10⁶ | ✅ 一期 |
| T02 | Spoofing | 邮箱被接管 → 应用账号被接管 | 全部卡泄露，含好友共享卡 | 无法根治（Email OTP 的固有属性）。缓解：新设备登录提醒、设备管理页、二期加 Passkey 作为第二因素 | ⚠️ 已接受 |
| T03 | Tampering | 越权修改他人卡 | 数据破坏 | 每个端点强制 `card_members` 校验；集成测试**必须**覆盖每个角色 × 每个端点的矩阵 | ✅ 一期 |
| T04 | Repudiation | 用户否认共享/删除操作 | 纠纷 | `audit_log` 记录关键动作 12 个月 | ✅ 一期 |
| T05 | Info Disclosure | DB / 备份泄露 | 全量会员号 | Vault Transit 加密 payload、note、email | ✅ 一期 |
| T06 | Info Disclosure | **邮箱**枚举（OTP 端点） | 邮箱有效性验证服务 | 恒定 202 + **服务端不查 users**（ADR-0014，取代原「decoy challenge」）+ verify 侧五种拒绝逐字相同 + 两侧常量时间填充。**v1.1：好友邮箱端点已删除，此面收窄至仅 OTP** | ✅ 一期 |
| T07 | Info Disclosure | 应用主机 RCE | 全量明文 | ❌ 不防护。缓解：最小攻击面（无文件上传、无反序列化用户输入）、依赖漏洞扫描、容器非 root、只读根文件系统 | ⚠️ 已接受，见 §3.3 |
| T08 | Info Disclosure | 手机丢失 | 本机全部卡 | SQLCipher + Keystore + `allowBackup=false` + `dataExtractionRules`（含 `device-transfer`）+ 可选生物识别锁 + 远程登出。**边界（§3.4 的有意取舍，T-009 落地）**：Keystore 密钥**不绑定用户认证**（`setUserAuthenticationRequired(false)`），因为 Widget 与 FCM 后台同步必须在无用户交互时读写数据库。因此防护的是「**设备丢失且未解锁**」与「应用间越权」；对**已解锁设备上以本应用 UID 执行代码**的攻击者（已 root、取证工具）**不防护** —— 他能让 Keystore 替他解密。对外文案不得超出这条边界 | ✅ 一期（边界见右） |
| T09 | Info Disclosure | Widget / 锁屏泄露码值 | 会员号被瞥见 | Widget 不渲染条码（§3.9） | ✅ 一期 |
| T10 | Info Disclosure | FCM 载荷含个人数据 | Google 获知社交关系 | data-only 空载荷（§3.12） | ✅ 一期 |
| T11 | DoS | OTP 邮件轰炸 | 域名进黑名单 → 全站无法登录 | 多层限流 + 全局熔断 + SPF/DKIM/DMARC。**v1.1：攻击面收窄**（取消邮箱邀请后，用户无法让系统向任意第三方发信，§3.1）；但 **ESP 双活已撤销**，故障恢复能力降低 → 记为已接受风险（§3.2、R1） | ⚠️ 部分缓解 |
| T12 | DoS | 同步接口刷量 | 数据库压力 | 每设备 60 req/min；`limit` 上限 200；GIN 索引 | ✅ 一期 |
| T13 | Elevation | viewer 试图写卡或提权 | 数据破坏 / 夺卡 | **v1.1：角色变更与所有权转让端点已不存在**（无可提权的目标）；`PATCH`/`DELETE` 强制 owner 校验；`uq_card_single_owner` 索引兜底 | ✅ 一期 |
| T14 | Elevation | 批量加好友后骚扰 | 垃圾共享邀请 | **v1.1：邀请链接已取消**，加好友需知道对方完整 username 且经对方接受；好友请求 50/日；共享邀请需接受；可拉黑（拉黑同时级联撤销共享） | ✅ 一期 |
| T18 | Info Disclosure | **username 枚举**（`/v1/users/lookup` 遍历） | 得到"哪些 username 已注册"的名单 | **有意接受**（搜索功能不可能同时防枚举，§3.8）。控制手段：仅精确匹配、无 typeahead、响应只含 `user_id` + `username` 两个字段、30/min + 300/day 限速、username 是用户自选伪名且 UI 提示勿用真名 | ⚠️ 已接受 |
| T19 | Repudiation / Abuse | 恶意 owner 反复"分享→撤销"骚扰好友 | 通知轰炸 | 共享邀请 100/日限额；被邀请者可拒绝；可拉黑（立即级联切断）；`audit_log` 留痕 | ✅ 一期 |
| T20 | Tampering | 解除好友的级联撤销未在同事务内完成 | **权限残留**：非好友仍能读取卡 | 强制同事务（§4.2 协作点表）；接受共享邀请时**重校验**好友关系；集成测试断言级联后 `card_members.left_at` 非空 | ✅ 一期 |
| T21 | Info Disclosure | **共同成员泄露**：viewer 通过 API 响应或同步下发得知同一张卡的其他 viewer 是谁 / 有几个 | 凭空暴露两个陌生人之间的社交连接（Anna 的伴侣与同事互相看见） | `card_member` 的 `audience` **只含 [owner, 当事人]**；`GET /members` 按角色在查询层裁剪；`member_count` 仅对 owner 返回；数据导出同样裁剪（§5.2、§8.4） | ✅ 一期 |
| T15 | Tampering | 中间人 / 证书伪造 | 令牌与码值泄露 | TLS 1.3 + HSTS preload + `networkSecurityConfig` 禁明文。**证书固定一期不做** —— 见下方注与 T-150 落地记录 | ⚠️ 部分缓解，已接受风险 |
| T16 | Info Disclosure | 依赖供应链漏洞 | 任意 | Dependabot + `composer audit` + OWASP dependency-check 纳入 CI 门禁 | ✅ 一期 |
| T17 | Info Disclosure | 日志泄露敏感数据 | 码值 / 邮箱进日志 | 结构化日志 + 敏感字段脱敏处理器；CI grep 检查（禁止 `dump(`、`error_log(`、`Log.d` 打印实体） | ✅ 一期 |

> **证书固定的风险**：pin 配错会导致全量客户端无法连接且**无法远程修复**。因此：必须固定到**两个** pin（当前 + 备份密钥），必须在 staging 演练轮换，且 pin 过期时间必须早于证书过期至少 60 天并有日历提醒。若团队对此没有把握，**允许一期不做证书固定**（HSTS + 系统 CA 已提供合理保护），但需在此表记录为"已接受风险"。
>
> **T-150 的决定：一期不做，记为已接受风险。** 理由是上面那三条前置条件（双 pin、staging 轮换演练、日历提醒）都不是「在 `NetworkModule` 里加一行 `CertificatePinner`」能满足的 —— 它们是一条持续的运维承诺，而 T-150 是一张 1.5 人日的客户端卡。把 pin 配上而不配套那三条，换来的不是更安全，是一颗到期就让全量客户端离线且无法远程修复的定时炸弹。
>
> 现有缓解：TLS 1.3 + HSTS preload + `networkSecurityConfig` 的 `cleartextTrafficPermitted="false"`（§7.3）+ 系统 CA 信任链。**未缓解的部分**：用户自行安装了 CA 的设备（企业 MDM、调试代理）上的中间人 —— 攻击者仍需要那台设备的物理或管理访问。
>
> **要做时需要一张独立的卡**，交付物是：两个 pin（当前 + 备份公钥）、`CertificatePinner` 接线、staging 上真跑一次轮换、过期日历提醒、以及一份 runbook（「pin 配错了怎么办」的答案必须是发版之外的东西）。

### 7.3 客户端安全清单（Android）

**MUST**

- [ ] Room 走 SQLCipher；密钥经 Android Keystore 包裹
- [ ] `android:allowBackup="false"`，`android:dataExtractionRules` 排除所有数据
- [ ] Release 构建启用 R8 + 混淆 + 资源压缩；`minifyEnabled true`
- [ ] 禁止在 release 构建输出任何日志（`Timber` 只在 debug 种植 `DebugTree`）
- [ ] `networkSecurityConfig` 禁止明文流量（`cleartextTrafficPermitted="false"`）
- [ ] 令牌只存 `EncryptedSharedPreferences` —— ⚠️ 落地形态改为 `core:crypto` 的
      `SecretStore`（Keystore AES-GCM 包裹后写普通 `SharedPreferences`），
      理由见 [ADR-0007](adr/0007-android-secret-storage-without-jetpack-security.md)。
      **要求不变**：令牌绝不明文写 `SharedPreferences` 或 Room（T-150 复用同一门面）
- [ ] 深链接（App Links）必须校验 `assetlinks.json`，`autoVerify="true"`
- [ ] 所有 `Activity` 默认 `exported="false"`，仅必要的入口显式导出
- [ ] 不使用 `WebView` 加载远程内容（法律页面用本地 HTML 或原生渲染）
- [ ] 依赖 Play Integrity? → **不做**（一期无需，且增加对 Google 的依赖）
- [ ] **图片录入：使用 Photo Picker，不申请 `READ_MEDIA_IMAGES` / `READ_EXTERNAL_STORAGE`**（§10.1）
- [ ] **图片录入：所选图片不复制到应用私有目录、不上传、不写入日志与 Sentry 附件**；解码后立即 `recycle()`
- [ ] **viewer 的共享卡在 UI 层即无编辑/删除入口**（不是"点了才报错"），且服务端仍独立强制（§3.5）

**SHOULD**

- [ ] 全屏条码页提供设置项"允许截屏"（默认允许；关闭时加 `FLAG_SECURE`）
- [ ] 检测到设备 root 时**仅提示**不阻断（阻断是徒劳的，且伤害合法用户）
- [ ] 应用切到后台时对最近任务快照打码（`FLAG_SECURE` 或 placeholder Activity）

### 7.4 后端安全清单

- [ ] 容器以非 root 用户运行，根文件系统只读（`read_only: true` + tmpfs for `/tmp`, `var/cache`）
- [ ] Postgres / Redis / Vault **不**暴露宿主机端口，仅 Docker 内网
- [ ] SSH 仅密钥登录，禁 root 登录，`fail2ban`，端口非 22
- [ ] Caddy 自动 TLS；安全响应头：`HSTS`、`X-Content-Type-Options: nosniff`、`Referrer-Policy: no-referrer`、`X-Frame-Options: DENY`、`Content-Security-Policy: default-src 'none'`（API 无 HTML）
- [ ] 所有输入经 Symfony Validator 约束；Doctrine 全部使用参数绑定（禁止字符串拼 SQL）
- [ ] 禁用 `X-Powered-By` / `Server` 版本回显
- [ ] `.env` 不入库；生产配置由 `sops` 加密后随 Ansible 下发
- [ ] Vault AppRole：`secret_id` TTL 24h 自动续期，policy 最小化（仅 `transit/encrypt|decrypt|rewrap` 对指定 key）
  - ⚠️ **T-005 实际落地与本行有两处偏差，见 [ADR-0004](adr/0004-manual-vault-unseal.md) 的 Consequences**：
    ① `secret_id_ttl=0`（不过期）而非 24h —— 24h 需要 Vault Agent 一类的自动投递机制，
    T-005 不交付那个，配上会让服务在部署 24 小时后集体认证失败；token 侧才是 1h 自动续期。正解归 T-406。
    ② `rewrap` **不授予**应用，归独立的 `ncards-ops`（§17.4 的策略文本即如此）。
- [ ] 每周自动依赖漏洞扫描 + 每月手动镜像基线更新

### 7.5 系统限额（§3.1 的落地）

配置于 `config/packages/ncards_limits.yaml`，**服务端强制**，超限返回 `422 limit_exceeded`。

| 限额 | 值 | 理由 |
|---|---|---|
| 每用户卡数 | 500 | 远超真实使用（P99 < 30） |
| 每卡成员数 | 20（1 owner + 19 viewer） | 家庭 / 小团体 |
| 每用户好友数 | 500 | |
| barcode payload 长度 | 1024 字节 | 覆盖 PDF417/QR 的实际上限 |
| note 长度 | 2000 字符 | |
| title 长度 | 100 字符 | |
| **username 长度** | **3–20 字符，`[a-z0-9_]`** | §3.8。⚠️ 不合规是 `422 username_invalid`（专门的 code），**不是** `limit_exceeded` |
| **`POST /v1/me/username` 每用户设定尝试** | **10 次总计** | v1.1 曾把这一行印在下面的速率表里。**T-107 改归此表**：它没有窗口长度也永不恢复，是存量上限而不是速率，所以返回 `422 limit_exceeded`——429 会强迫编一个假的 `Retry-After`，客户端照着退避并永远失败。计数落在 `users.username_attempts` 列上（Redis 存不下跨账号生命周期的计数，§8.2）。⚠️ 只有**走到唯一性检查**的请求消耗它：`username_invalid` 与 `username_immutable` 都不消耗，否则客户端预校验的一个 bug 就能把账号永久钉死在 onboarding。见 [ADR-0017](adr/0017-username-assignment-and-lifetime-attempt-counter.md) |
| 每用户每日发出好友请求 | 50 | 防骚扰 |
| 每用户每日发出共享邀请 | 100 | |
| ~~每用户每日给未注册邮箱的邀请~~ | **已删除** | v1.1 取消邮箱邀请（C2），该能力不复存在 |
| ~~每用户活跃邀请链接数~~ | **已删除** | v1.1 取消邀请链接（C2） |

**速率限制**（Symfony RateLimiter + Redis 滑动窗口）

| 端点 | 维度 | 限制 |
|---|---|---|
| `POST /auth/otp/request` | email_hash | 1/min, 5/h, 10/day |
| `POST /auth/otp/request` | IP | 20/h |
| `POST /auth/otp/verify` | challenge_id | 5 总计 |
| `POST /auth/otp/verify` | IP | 60/h |
| **`POST /auth/magic/consume`** | **IP** | **60/h**（T-106；**没有** challenge 维度的次数上限 —— 令牌是 32 字节 CSPRNG，猜错的令牌找不到任何行可以累加） |
| `POST /auth/token/refresh` | session | 60/h |
| `GET /v1/sync` | device | 60/min |
| **`GET /v1/users/lookup`** | **user** | **30/min，300/day**（v1.1，抑制 username 枚举 T18） |
| **`GET /v1/users/lookup`** | **IP** | **100/h**（覆盖多账号协同枚举） |
| **`GET /v1/config`** | **IP** | **300/min**（T-112 追加）。免鉴权，IP 是唯一可用的主体。⚠️ 按**分钟**而不像上面三条 IP 策略按小时：那几条守的是登录与枚举（真实用户一天碰几次），而本端点是客户端**每次冷启动都拉一次**的那一个。⚠️ 配额留足 **CGNAT** 余量 —— 德国移动运营商一个公网 IPv4 背后可能有上千订户共用这一个桶；配紧的症状是一整个运营商出口在晚高峰被限流，那批用户的强制升级墙与维护横幅一起失灵，而日志里看起来完全像「限流生效了」。⚠️ 这是本表**第二条** fail-open 的策略，见 [ADR-0021](adr/0021-second-fail-open-rate-limit-for-the-config-endpoint.md) |
| ~~`POST /v1/me/username`~~ | ~~user~~ | **已移入上面的限额表**（T-107）。它不是滑动窗口，也不返回 429 —— 见那一行与 [ADR-0017](adr/0017-username-assignment-and-lifetime-attempt-counter.md)。`POST /auth/otp/verify` 的「challenge_id 5 次总计」出于同一个理由也是持久层计数（`otp_challenges.attempts`），只是它的失败码本来就是 401 |
| 全部写接口 | user | 300/min |
| 全局 | 邮件外发总量 | 阈值告警 + 熔断（保留 OTP） |

> **为什么 lookup 要同时按 user 和 IP 限速**：单账号 300/day 看似很紧，但注册成本仅为一个邮箱。IP 维度是对"批量注册 + 分摊枚举"的第二道闸。两者都触发时优先返回更长的 `Retry-After`。

限流响应**必须**带 `Retry-After` 与 `X-RateLimit-Remaining`。

> **降级方向**：本表默认 **fail-closed**（Redis 不可达 → `503 service_unavailable`，不是 429 —— 429 的语义是「我判定你超限了」，而真实情况是「我**无法判定**」）。例外**恰好两条**，判据相同（纯防 DoS，不是安全控制）：`全部写接口`（[ADR-0005](adr/0005-rate-limiting-topology.md) 决定 3）与 `GET /v1/config`（[ADR-0021](adr/0021-second-fail-open-rate-limit-for-the-config-endpoint.md)）。`RateLimitPolicyCoverageTest` 把这个白名单断言成封闭集合 —— 加第三条要先写 ADR。

---

## 8. GDPR 与合规

> 本节由工程团队实现，**必须**由律师（Fachanwalt für IT-Recht）复核后上线。文中条款引用为工程参考，非法律意见。

### 8.1 角色与法律基础

- **控制者（Verantwortlicher）**：运营主体（需在 Impressum 中列明，TMG §5 / DDG §5）。
- **法律基础**：
  | 处理活动 | 基础 |
  |---|---|
  | 账号、卡存储、同步、共享 | Art. 6(1)(b) 合同履行 |
  | 安全日志、限流、滥用防护、审计日志 | Art. 6(1)(f) 正当利益（需做 LIA 记录） |
  | 崩溃诊断（Sentry） | Art. 6(1)(f)，且默认关闭详细诊断，设置页可开关 |
  | 推送通知 | Art. 6(1)(b)（服务必要）；系统级通知权限由 Android 13+ 运行时授权承担 |
  | 营销邮件 | **一期不做**。若做，必须 Art. 6(1)(a) 明示同意 + double opt-in |

### 8.2 处理活动记录（ROPA，Art. 30 摘要）

| 数据类别 | 字段 | 目的 | 保留期 | 存储位置 |
|---|---|---|---|---|
| 身份 | email（加密）、email_hash、**username（明文伪名，唯一展示名）**、locale | 账号与登录、**好友检索** | 账号存续期 + 30 天宽限 | Hetzner DE |
| 卡内容 | title, merchant, color, format, **payload（加密）**, **note（加密）**, expires_on | 核心服务 | 账号存续期 | Hetzner DE |
| 关系 | friendships, card_members | 共享功能 | 关系存续期 | Hetzner DE |
| 设备 | device id, model, os/app 版本, push_token | 多设备与推送 | 设备撤销后即删 | Hetzner DE |
| 安全 | ip_hash, audit_log, 限流计数 | 滥用防护 | ip_hash 30 天；audit 12 个月；限流 24 小时 | Hetzner DE / Redis |
| 认证 | `otp_challenges`：email_hash、**email_encrypted**（Vault Transit）、locale、code_hash、request_ip_hash | 登录与注册（§6.3.1） | 挑战 10 分钟过期；**死后满 24 小时由每日任务删整行**（T-113，宽限期供排障，参数 `ncards.cleanup.otp_challenge_grace_hours`）。request_ip_hash 另有 30 天上限，由一条独立的兜底任务强制（当前配置下恒 0 行，见 [ADR-0022](adr/0022-daily-cleanup-via-symfony-scheduler-single-replica-no-lock.md) 决定 4 的「负面」段） | Hetzner DE |
| 外发邮件队列 | `messenger_messages.body`：收件邮箱 + OTP 码 / 提醒内容，**整条消息体经 Vault Transit 加密**（`ncards-pii`） | 异步投递登录码与安全提醒 | **消费即删行**；投递失败重投 3 次（约 13 秒）后转入 `failed` 队列，由人工处置后删除 | Hetzner DE |
| 诊断 | 崩溃栈、`X-Request-Id`、脱敏日志 | 稳定性 | 30 天 | Hetzner DE（自托管 Sentry / Loki） |

### 8.3 子处理者清单（Art. 28，均需签 AVV/DPA）

| 子处理者 | 处理内容 | 所在地 | 第三国传输 |
|---|---|---|---|
| Hetzner Online GmbH | 全部托管与备份 | 德国（备份可选芬兰） | 无 |
| dogado GmbH（`n-cards.de` 的域名邮箱 = **唯一**发信通道，一期不做双活，§3.2） | 收件邮箱地址、邮件内容（OTP 码、安全提醒） | 德国（多特蒙德） | 无 |
| Google Ireland Ltd.（FCM） | **仅** 设备推送令牌 + 空唤醒信号 | IE / 全球基础设施 | 有（美国）→ 依据 EU-US Data Privacy Framework + SCC；**因载荷不含个人数据，风险显著降低（§3.12）** |
| Google Ireland Ltd.（Play） | 分发与账单（无内购则无账单） | IE | 有 |

> ML Kit Barcode Scanning 使用 **bundled** 模型，**完全在设备本地运行**，不向 Google 传输图像或识别结果。**从图片录入（ADR-13）同样是纯本地解码**——所选图片不上传、不落盘、不进入任何日志，解码完成即释放。这两点都必须在隐私声明中明确写出（它们是相对竞品的实质优势，值得写在显眼处）。

> ⚠️ **§3.2 放宽的是可用性要求，不是合规要求。** 即使一期只用"普通商业邮箱服务"，仍**必须**满足：① 处理地在 EU/EEA（或有充分性认定）；② 签署 AVV/DPA；③ 列入本表与隐私声明。**不得**为图省事使用个人邮箱、消费级邮箱（Gmail/GMX 个人账户）或美国 SaaS 的免费层——那是无 DPA 的第三国传输，属于合规硬伤，与"要不要做双活"是两回事。

> **Q3 已决（2026-09-05，[ADR-0013](adr/0013-mail-via-domain-mailbox.md)）：用 `n-cards.de` 自己的域名邮箱（dogado），不采购专业 ESP。** 判据是上面那三条，不是"是不是 ESP"——一份有合同、有 AVV、处理地在德国的商业托管邮箱三条全中，与上一段禁止的"个人 / 消费级免费账户"是两回事。
>
> ⛔ **两项合规动作仍然欠着，上线前必须关闭**：① **与 dogado 签署 AVV 并归档**（Art. 28，本表的前提）；② `no-reply@n-cards.de` 是一个**真实存在的双向收件箱**（域名邮箱不同于 ESP 的纯发信地址），退信与用户误回复会让**真实邮箱地址**积累在那里，而 §8.2 的 ROPA 里**没有这条数据流**——需要决定它的处置（自动回复 / 转发 / 定期清空）并据此决定是否补进 ROPA。两项都归 T-450。

### 8.4 数据主体权利实现

| 权利 | 端点 / 流程 | SLA |
|---|---|---|
| 访问 + 可携带（Art. 15/20） | `POST /v1/me/export` → 异步生成 ZIP（`cards.json`（**含解密后的明文码值**）、`friends.json`、`shares.json`、`devices.json`、`audit.json`、`README.txt`）→ 生成 24 小时有效的一次性下载令牌，通过 App 内下载（**不通过邮件发链接**） | ≤ 24 小时（目标 < 5 分钟） |
| 更正（Art. 16） | App 内直接编辑 | 即时 |
| 删除（Art. 17） | §3.7 流程：预览（含"N 张共享卡将从好友钱包消失"）→ 确认 → 30 天宽限 → 硬删（**含全部自有卡，不转让**） | 30 天 |
| 限制处理（Art. 18） | 一期通过客服邮箱人工处理（账号置 `suspended`），记录在案 | ≤ 30 天 |
| 反对（Art. 21） | 关闭诊断上报开关；核心处理基于合同无法反对 | 即时 |
| 撤回同意 | 仅诊断上报涉及同意 | 即时 |

**导出内容的关键决策**：导出包中的码值**必须**是解密后的明文——这是用户自己的数据，Art. 20 要求"结构化、通用、机器可读"。下载令牌一次性、24 小时、绑定会话。

**导出也必须遵守成员可见性（C11）**：`shares.json` 按导出者的角色裁剪——
- 作为 owner 的卡：列出全部 viewer 的 username（这是他本就能看到的）。
- 作为 viewer 的卡：**只列 owner 的 username 与自己**，不得出现其他 viewer。
- 好友列表导出 `friends.json` 只含 username，不含对方的邮箱等任何其他字段。

> 数据导出是最容易绕过权限检查的地方——它通常由一段独立的批处理代码直接查库，而不复用 API 的裁剪逻辑。**必须**让 `DataExporter` 复用与 `GET /v1/cards/{id}/members` 相同的查询，或为其单独写一条断言测试。

**硬删除范围**（每日 `PurgeDeletedAccounts` 任务）

```
-- v1.1：无所有权转移分支，删除即彻底
0. 快照 audience：收集该用户所拥有的每张卡的当前成员列表   ← 必须最先做（§5.2）
1. INSERT change_log：为每张自有卡写 card 墓碑（audience = 步骤 0 的快照）
                      为每条成员关系写 card_member 墓碑
2. DELETE cards            WHERE owner_id = :user_id        -- 含全部共享卡，不转让
   （card_members 经 ON DELETE CASCADE 一并清除）
3. DELETE card_members     WHERE user_id  = :user_id        -- 该用户作为 viewer 的关系
4. DELETE friendships（双向）
5. DELETE share_invitations（作为 inviter 或 invitee 的全部行）
6. DELETE users, devices, sessions, otp_challenges（按 email_hash）
   -- username 随 users 行一并物理删除，随即可被他人重新注册（见下）
7. UPDATE change_log SET audience = array_remove(audience, :user_id)
   → 随后 DELETE FROM change_log WHERE audience = '{}'
   -- 注意：步骤 1 写入的墓碑其 audience 含其他用户，故不会被此步清空，
   --      仍能正常下发给 viewer（这正是步骤 1 必须早于步骤 7 的原因）
8. audit_log 中该用户的行：保留 action + 时间 + 匿名化 id（Art.17(3)(e) 法律主张所需），
   但 actor_user_id 替换为随机化的假名，且不可再关联到自然人
```

> ⚠️ 硬删除必须有集成测试逐表断言，否则一定会漏表。v1.1 新增两条断言：**① viewer 端确实收到了卡墓碑**（而不只是数据库干净）；② `friend_invite_links` 表已不存在，若迁移中仍有残留 DDL，属于遗漏。

**username 的删除后可复用性（必须明确的语义）**

删号硬删后，该 `username` 释放，**可被新用户重新注册**。这带来一个真实风险：Anna 注销后，攻击者注册同名 `anna_b`，Anna 的老好友以为还是她。

一期的处理：**接受该风险，但不放大它**——
- 好友关系在删号时已双向硬删，因此老好友的列表里**不会**出现"复活"的同名用户，必须重新走一次搜索 + 双向确认才能加上。也就是说，冒名者无法继承任何既有关系，只能重新骗一次。
- 不实现 username 保留期（tombstone）。理由：保留期会把已删除用户的标识符继续留在库里，与 Art.17 的"彻底删除"直觉相冲突，且对抗价值有限（攻击者可以等）。
- 若未来出现实际滥用，再引入"90 天冷却期"是纯服务端变更，无需改客户端。

### 8.5 TTDSG / § 25 TDDDG（终端设备存储）

App 在设备上存储的全部数据（Room 库、令牌、偏好）均为**提供用户明确请求的服务所严格必需**，落入 § 25 Abs. 2 Nr. 2 例外，**无需**同意弹窗。前提是：不集成任何分析/广告 SDK（§3.12 已保证）。诊断上报（Sentry）**不是**必需，因此**必须**默认关闭并提供开关。

### 8.6 应用内法律页面（必须，德语 + 英语）

| 页面 | 依据 | 位置 |
|---|---|---|
| Impressum | § 5 DDG（原 TMG） | 设置 → 关于；**不得**藏在多层菜单下（"Zwei-Klick-Regel"） |
| Datenschutzerklärung | Art. 13 GDPR | 设置 → 隐私；首次启动时链接可见 |
| AGB / Nutzungsbedingungen | 合同基础 | 注册页链接 |
| 开源许可 | 各依赖许可 | 设置 → 关于 → 开源许可 |

Google Play 需额外提交：Data Safety 表单（**必须**与上述 ROPA 一致）、隐私政策 URL、账号删除 URL（Play 政策要求提供**站外**的账号删除入口 → 需一个最小 Web 页面 `https://n-cards.de/delete-account`）。

### 8.7 DPIA（数据保护影响评估）

依 Art. 35，本项目**大概率不强制** DPIA（无大规模特殊类别数据、无系统性监控、无自动化决策）。但由于处理"消费行为可推断的会员卡数据"，**应当**做一份简化的必要性评估（2–3 页）并归档，成本极低而抗辩价值高。**责任人：技术负责人，M4 里程碑内完成。**

---

## 9. 非功能需求与 SLO

### 9.1 性能预算

| 指标 | 目标 | 测量方式 |
|---|---|---|
| **卡面调出**：Widget 点击 → 条码完全渲染 | P95 ≤ 800 ms（冷启动 ≤ 1.8 s） | Macrobenchmark |
| 钱包列表首帧（温启动） | P95 ≤ 400 ms | Macrobenchmark |
| 列表滚动 | 无掉帧（P99 帧耗时 < 16.6 ms，200 张卡） | JankStats |
| APK 大小（下载） | ≤ 20 MB | Play Console |
| `GET /v1/sync`（增量 0–50 条） | P95 ≤ 250 ms（服务端） | Prometheus histogram |
| `GET /v1/sync/bootstrap`（200 张卡） | P95 ≤ 700 ms | 同上 |
| `PATCH /v1/cards/{id}` | P95 ≤ 200 ms | 同上 |
| `POST /auth/otp/verify` | P95 ≤ 300 ms | 同上 |
| Vault batch decrypt（200 条） | P95 ≤ 80 ms | 自定义指标 |

### 9.2 SLO（对外承诺的内部目标）

| SLI | SLO | 错误预算（30 天） |
|---|---|---|
| API 可用性（非 5xx 且 < 2s 的请求占比） | 99.5% | 3.6 小时 |
| OTP 邮件送达（请求 → 用户成功验证的转化率） | ≥ 92% | — |
| ~~OTP 邮件时延（发送 → ESP 确认投递）~~ | **一期不测量**：需要接入服务商的投递 webhook，属 §3.2 后置项。用上一行的转化率作为唯一代理指标。⚠️ Q3 定案后（域名邮箱，[ADR-0013](adr/0013-mail-via-domain-mailbox.md)）这条**不是后置，是不可得**——域名邮箱没有投递 webhook，也没有 bounce / 投诉反馈回路。想要它就必须先换成专业 ESP | — |
| 共享变更端到端可见（前台） | P95 ≤ 10 s | — |
| 同步数据正确性（客户端上报的校验失败率） | ≤ 0.01% | — |
| 崩溃自由用户率 | ≥ 99.5% | — |
| ANR 率 | ≤ 0.3% | — |

> 一期为单主机部署，**不承诺** HA。计划内维护窗口：每周二 03:00–04:00 CET，通过 `/v1/config` 的 `maintenance` 字段提前 24 小时下发，客户端展示横幅。**离线优先架构使维护窗口对用户几乎无感——这是该架构的额外收益。**

### 9.3 容量假设（一期）

- 目标 12 个月内 50,000 注册用户，DAU 15%。
- 人均 15 张卡 → 750K 行 `cards`，约 300 MB（含密文）。
- 同步请求峰值：7,500 DAU × 10 次/天，峰值集中在 17:00–19:00 → 约 15 req/s。
- 单台 CCX23 绰绰有余。**扩容路径**：垂直升配 → 拆 Postgres 到独立机 → 应用层多副本 + 负载均衡（应用本身无状态，Session 存 DB/Redis，天然可水平扩展）。

> ⚠️ **本节唯一撑不到 5 万注册的资源不是主机，是发信配额。** 按上面的 DAU 假设推算，日发信量约 **1100 封**（推导见 `backend/config/packages/ncards_mail.yaml`），而 Q3 定案的域名邮箱（dogado，[ADR-0013](adr/0013-mail-via-domain-mailbox.md)）有每小时 / 每天配额，量级通常在几百。**主机的扩容路径解决不了这个** —— 它的扩容路径是换通道：按 §3.2 原方案补做专业 ESP，2 人日，抽象层（`MailSenderInterface`）已就位。触发判据写在 [`docs/runbooks/email-dns.md`](runbooks/email-dns.md) §4。

### 9.4 可靠性

| 项 | 要求 |
|---|---|
| 备份 | Postgres：每日 `pg_dump` 全量 + WAL 归档（`pgBackRest` 或 `wal-g`）到 Hetzner Storage Box，客户端加密（`age`）。保留 30 天。 |
| 恢复演练 | **每季度一次**必须实际执行恢复到 staging 并验证。未演练的备份视为不存在。 |
| RPO / RTO | RPO ≤ 15 分钟（WAL 归档间隔）；RTO ≤ 4 小时 |
| Vault 恢复 | unseal key（Shamir 3-of-5）与 recovery key 离线分存于 ≥2 个物理位置。**Vault 数据丢失 = 全部卡数据不可解密**，因此 Vault 存储卷单独每日快照。 |
| 迁移回滚 | 每个 Doctrine migration **必须**实现可用的 `down()`；expand-contract 保证任一步都可回滚 |

---

## 10. Android 端关键实现规范

### 10.1 扫描与渲染

**扫描（CameraX + ML Kit）**

```
CameraX Preview + ImageAnalysis(STRATEGY_KEEP_ONLY_LATEST)
  → BarcodeScanning.getClient(options)   // 不限定格式，全码制
  → 连续 3 帧识别到同一 (format, rawValue) 才确认   ← 防误识别（关键）
  → 触觉反馈 + 声音 → 停止分析 → 进入编辑页
```

- **必须**使用 **bundled** 模型依赖（`com.google.mlkit:barcode-scanning`），不用 `play-services-mlkit-*`（后者首次使用需下载模型，弱网/无 GMS 设备会失败）。约增加 ~2.5 MB APK，值得。
- **必须**提供手动输入回退入口（`ITF`/`Codabar` 等磨损卡识别率低）。
- **必须**提供手电筒开关（超市光线差）。
- 相机权限被拒 → 直接进入**图片录入或手动输入**页，不做无意义的挽留弹窗。

**从图片录入（v1.1 新增，ADR-13）**

```
「添加卡」页三个入口并列，无主次：[扫描] [从图片] [手动输入]

从图片：
  ActivityResultContracts.PickVisualMedia(ImageOnly)     ← 系统 Photo Picker
    → 得到 content:// Uri（临时读权限，随 Activity 结束失效）
    → decode：ImageDecoder + setTargetSampleSize，长边降采样至 ≤ 2048 px
    → InputImage.fromBitmap(bitmap, rotationDegrees=0)    ← ImageDecoder 已应用 EXIF 旋转
    → 同一个 BarcodeScanner 实例（与相机流共用，格式配置一致）
    → 结果分支：
        0 个   → "未识别到条码" + 两个按钮：[换一张图片] [手动输入]
        1 个   → 直接进入编辑页，预填 format + rawValue
        ≥2 个  → 在缩略图上按 boundingBox 画框，用户点选其一
                （常见于超市小票、会员卡正反面合影、含多码的优惠券页）
    → 无论成败：bitmap.recycle()，不持有 Uri，不复制到 App 私有目录
```

**MUST**

- 使用 **Photo Picker**（`PickVisualMedia`），**不得**申请 `READ_MEDIA_IMAGES` / `READ_EXTERNAL_STORAGE`。Photo Picker 在 API 19+ 通过 backport 可用，用户只授予单张图片的临时访问，是权限最小化的正解——也让隐私声明可以写"我们无法访问你的相册"。
- **图片绝不落盘、绝不上传、绝不进日志**（含 Sentry 附件与面包屑）。这是 ADR-12（不做图片存储）与 ADR-13 能够共存的唯一前提，也是 §8.3 隐私声明中的承诺。
- **必须**降采样后再解码。手机相册里 4000×3000 的照片直接 `Bitmap` 化在 2 GB RAM 的低端机（§13.8 要求的验证设备）上会 OOM。目标长边 2048 px：既能保证 1D 条码的最小模块宽 ≥ 2 px，又把内存控制在 ~16 MB。
- 解码在 `Dispatchers.Default`，UI 显示不确定进度指示；超过 5 秒视为失败（防超大图卡死）。
- 识别结果**必须**经过与相机路径**完全相同**的校验与格式映射（`core:barcode` 的同一入口），**禁止**为图片路径写第二套 `when` 分支。

**SHOULD**

- 支持从其他 App 分享图片进来（`ACTION_SEND` + `image/*`，manifest intent-filter），直达图片录入流程。用户常在邮件/微信里收到优惠券截图，这条路径能省掉"先存相册再打开 App"两步。
- 多码选择界面按 `boundingBox` 面积降序排列候选，最大的通常是主码。
- 对 1D 码，若图片明显倾斜（ML Kit 返回的 `cornerPoints` 不接近矩形）导致识别失败，提示"试试把条码摆正后重拍"，而不是笼统的"识别失败"。

> **不做**：图片裁剪/旋转编辑器、批量导入多张图片、从 PDF 导入。前两者是无底洞，后者需要额外渲染引擎——都属于 §1.3 的范围蔓延，遇到需求请指回本节。

**渲染（ZXing）**

- 使用 `com.google.zxing:core` 的 `MultiFormatWriter` 生成 `BitMatrix`，自行转 Bitmap。
- **必须**按目标 `View` 尺寸生成（不要生成小图再放大 → 边缘模糊会导致扫码枪读不出）。
- **必须**强制白底黑码，无论 App 主题是深色还是浅色。深色模式下的条码是扫码失败的经典原因。
- 1D 条码：`margin` 至少 10 模块宽（静区），否则部分扫码枪拒读。
- 渲染在 `Dispatchers.Default`，结果缓存于内存 LruCache（key = format + value + size）。

**格式映射表**（ML Kit → ZXing → 展示名）必须集中在 `core:model` 的单一 `BarcodeFormat` 枚举中，禁止在各处散落 `when`。

### 10.2 快速调出入口

| 入口 | 实现 | 约束 |
|---|---|---|
| **主屏 Widget** | Glance `GlanceAppWidget`，2×2 / 4×2 / 4×4 三档尺寸 | **只**显示名称 + 颜色/首字母；点击 → `PendingIntent` 打开全屏页。数据源为 Room Flow，通过 `updateAll()` 刷新（防抖 ≥ 5 s） |
| **全屏条码页** | 独立 `Activity`（便于设置窗口属性） | 亮度 = 1.0f；`FLAG_KEEP_SCREEN_ON`；退出恢复原亮度（`onPause` 必须恢复，否则用户会投诉耗电）；支持横屏放大 1D 码 |
| **快捷设置磁贴** | `TileService`，显示最近使用的卡 | 点击直达该卡全屏页；Android 14+ 需处理 `startActivityAndCollapse` 的 `PendingIntent` 变更 |
| **App Shortcuts** | 动态 shortcuts，最多 4 个最常用卡 | `ShortcutManagerCompat`，按使用频次更新（≥ 1 天更新一次，避免频繁写） |

**全屏页无障碍要求**：码值必须以**可选中的大字号文本**显示在条码下方，且带 `contentDescription`，使 TalkBack 用户可以让系统朗读、口述给收银员。这是银发用户与视障用户的关键功能，**不是可选项**。

### 10.3 通知

- 单一通知渠道组 + 三个渠道：`sharing`（共享相关）、`security`（新设备登录）、`sync`（默认关闭，仅调试）。
- Android 13+ `POST_NOTIFICATIONS` 运行时权限：**不在启动时索要**，而在用户首次成功共享或接受共享后，用一句上下文说明再申请。
- 所有通知文案由客户端本地化生成（§3.12）。
- 通知点击 → 深链接到对应页面（好友请求 / 共享请求 / 卡详情）。

**`sharing` 渠道必须覆盖的六类事件（v1.1）**——尤其注意后三类"卡消失"的场景，它们是 R12 的直接缓解：

| 事件 | 文案要点 | 动作 |
|---|---|---|
| 收到好友请求 | "{username} 想加你为好友" | 打开好友请求页 |
| 收到共享请求 | "{username} 想与你共享一张卡" | 打开邀请详情，可接受/拒绝 |
| 自己的共享被接受/拒绝 | "{username} 已接受/拒绝你的共享" | 打开卡详情 |
| **卡被 owner 删除** | "「{title}」已被 {username} 删除" | 无（卡已不存在） |
| **被移除成员 / 自己退出** | "你已不再拥有「{title}」的访问权限" | 无 |
| **因解除好友而失去** | "与 {username} 解除好友后，你们之间共享的 N 张卡已移除" | 打开好友列表 |

> 这三类"消失"事件**必须**分开写文案，不得合并成一句笼统的"部分卡片已移除"——用户无法据此判断是不是 Bug（R12）。

### 10.4 状态管理约定

- 每个 feature 一个 `UiState` sealed interface（`Loading` / `Content` / `Empty` / `Error`）。**禁止**用多个独立布尔标志表达状态。
- 一次性事件（Snackbar、导航）用 `Channel` + `receiveAsFlow()`，**不放进** `StateFlow`。
- `ViewModel` **不得** import Android framework 类（`Context`、`Uri` 除外的资源引用一律用资源 id 或 sealed 的 `UiText`）。

---

## 11. 国际化与无障碍

### 11.1 国际化

| 项 | 要求 |
|---|---|
| 语言 | 德语（**默认**，`values/` 即德语）、英语（`values-en/`）。**注意**：默认资源目录放德语，因为主要市场是德国，且避免"英语兜底"在德语环境下漏翻 |
| 文案来源 | 所有用户可见字符串必须在 `strings.xml`；CI 用 lint 规则 `HardcodedText` 阻断硬编码 |
| 复数 | 必须用 `plurals`，德语有 one/other |
| 日期 | `java.time` + `DateTimeFormatter.ofLocalizedDate(FormatStyle.MEDIUM)`，德语显示 `24.08.2026` |
| 布局 | 德语单词长（`Benachrichtigungseinstellungen`），所有按钮/标签必须允许换行且做长文本预览测试 |
| RTL | 一期不支持阿拉伯语等，但布局使用 `start/end` 而非 `left/right`（零成本，为将来留路） |
| 后端 | 邮件模板双语（按 `users.locale`）；API 不返回本地化文案（§6.1） |

### 11.2 无障碍（WCAG 2.1 AA 为设计目标）

**法规状态说明**：欧洲无障碍法案（EAA）在德国由 BFSG 落地，自 2025-06-28 适用。BFSG 主要覆盖**面向消费者的电商服务**，且对微型企业（< 10 人**且** 年营业额 ≤ 200 万欧元）提供服务方面的豁免。本 App 一期免费、无电商功能，**强制适用的可能性低**。但——目标用户含大量银发群体，无障碍是**产品竞争力而非合规负担**。因此按 WCAG 2.1 AA 做，不因"可能不强制"而降级。

**上线必须满足（M4 门禁）**

- [ ] 所有交互元素触摸目标 ≥ 48 dp
- [ ] 文本对比度 ≥ 4.5:1；大文本 ≥ 3:1（卡片颜色调色板需逐个校验）
- [ ] 全部图标按钮有 `contentDescription`；装饰性图片为 `null`
- [ ] 支持系统字体缩放至 200% 不截断（使用 `sp`，`TextView`/`Text` 不设固定高度）
- [ ] TalkBack 可完成核心流程：登录 → 添加卡 → 打开条码 → 共享
- [ ] 焦点顺序合理；对话框正确抢焦点并可返回
- [ ] 不以颜色作为唯一信息载体（共享徽章需带图标或文字）
- [ ] 支持"减少动画"系统设置

**可后置**：非核心路径的 TalkBack 打磨、无障碍服务的自定义动作、完整的自动化 a11y 测试矩阵。

---

## 12. 项目结构

### 12.1 仓库策略

**单仓（monorepo）**：

```
n-cards/
├── README.md
├── docs/
│   ├── TECHNICAL_SPEC.md          ← 本文档
│   ├── adr/                       ← 后续架构决策
│   │   └── 0001-....md
│   ├── runbooks/
│   │   ├── vault-unseal.md
│   │   ├── key-rotation.md
│   │   ├── restore-from-backup.md
│   │   ├── esp-failover.md
│   │   └── incident-response.md
│   └── api/
│       └── openapi.yaml           ← 契约真相源（§13.1）
├── backend/
├── android/
├── infra/
│   ├── compose/                   ← docker-compose.{base,prod,staging}.yml
│   ├── ansible/
│   ├── caddy/
│   ├── vault/
│   └── monitoring/                ← prometheus.yml, grafana dashboards, loki
└── .github/workflows/
```

理由：契约（`docs/api/openapi.yaml`）被前后端同时消费，单仓保证一次 PR 内可以同步修改契约 + 双端实现 + 契约测试，是"契约优先"落地的前提。

### 12.2 后端目录（Symfony 7.x / PHP 8.3+）

```
backend/
├── bin/console
├── composer.json
├── phpstan.neon              # level 8, src 全量
├── deptrac.yaml              # 模块边界强制
├── .php-cs-fixer.dist.php    # @Symfony + @PHP83Migration
├── phpunit.xml.dist
├── config/
│   ├── bundles.php
│   ├── packages/
│   │   ├── doctrine.yaml  messenger.yaml  security.yaml
│   │   ├── rate_limiter.yaml  monolog.yaml
│   │   └── ncards_limits.yaml        # §7.5 限额
│   ├── routes/{identity,wallet,sharing,social,sync,compliance}.yaml
│   └── services/                     # 每模块一个 services 文件
├── migrations/
├── public/index.php
├── src/
│   ├── Kernel.php
│   ├── Shared/
│   │   ├── Domain/          Uuid.php  Clock.php  DomainEvent.php  DomainException.php
│   │   │   └── Crypto/      CryptoKey.php  Ciphertext.php
│   │   │                    CryptoFailed.php  CryptoUnavailable.php
│   │   ├── Application/     CommandBusInterface.php  EventBusInterface.php
│   │   │   └── Crypto/      CryptoServiceInterface.php  BatchDecryptorInterface.php
│   │   │                    HmacHasherInterface.php
│   │   ├── Infrastructure/
│   │   │   ├── Crypto/      VaultTransitCrypto.php  VaultBatchDecryptor.php
│   │   │   │                VaultHmacHasher.php
│   │   │   ├── Vault/       VaultClient.php  AppRoleTokenProvider.php
│   │   │   │                StaticTokenProvider.php  VaultTokenProviderFactory.php
│   │   │   ├── Doctrine/    UuidType.php  ChangeLogSubscriber.php  TransactionalRunner.php
│   │   │   ├── RateLimit/   LimitEnforcer.php
│   │   │   └── Http/        ApiProblemExceptionListener.php  ClientVersionListener.php
│   │   │                    IdempotencyMiddleware.php  RequestIdListener.php
│   │   └── Http/            AbstractApiController.php  CursorPaginator.php
│   └── Module/
│       ├── Identity/
│       │   ├── Domain/
│       │   │   ├── Entity/          User.php  Device.php  Session.php  OtpChallenge.php
│       │   │   ├── ValueObject/     Username.php        # 归一化 + 字符集 + 保留词校验
│       │   │   ├── Repository/      UserRepositoryInterface.php  ...
│       │   │   ├── Service/         OtpCodeGenerator.php  TokenIssuer.php
│       │   │   └── Event/           UserRegistered.php  NewDeviceLoggedIn.php
│       │   ├── Application/
│       │   │   ├── Command/         RequestOtp.php  VerifyOtp.php  RefreshToken.php
│       │   │   │                    SetUsername.php     # 一次性写入，§5.2
│       │   │   ├── Handler/
│       │   │   ├── Query/
│       │   │   ├── Dto/
│       │   │   └── Port/            UserDirectoryInterface.php   # 供其他模块使用
│       │   │                        #   findByUsername() / findById()
│       │   ├── Infrastructure/      Doctrine/  Jwt/  Mail/
│       │   └── Http/Controller/     AuthController.php  DeviceController.php
│       │                            MeController.php  UsernameController.php
│       ├── Wallet/          # Card 实体、加密载荷、列表查询
│       ├── Sharing/         # CardMember(owner|viewer)、ShareInvitation、退出共享
│       │                    #   Port/ShareRevokerInterface  ← 供 Social 级联撤销（§4.2）
│       ├── Social/          # Friendship、FriendRequest、UsernameLookup、Block
│       │                    #   Port/FriendshipCheckerInterface ← 供 Sharing 校验好友
│       ├── Sync/            # ChangeLog、Cursor、SyncReadModel、SyncController
│       ├── Notification/    # FcmSender、MailSender、模板
│       └── Compliance/      # DataExporter、AccountDeleter、AuditLogger
├── templates/email/{de,en}/       # Twig 邮件模板
└── tests/
    ├── Unit/                      # Domain / Application，无容器
    ├── Integration/               # 带 DB/Redis/Vault（testcontainers 或 compose）
    └── Api/                       # 端到端 HTTP + 契约校验
```

**模块内分层职责**

| 层 | 允许做 | 禁止做 |
|---|---|---|
| `Http/Controller` | 反序列化请求、调用 Application、序列化响应 | 任何业务逻辑、直接用 Doctrine |
| `Application` | 编排、事务边界、权限检查、DTO 组装 | 直接写 SQL、了解 HTTP |
| `Domain` | 实体、值对象、不变量、领域服务、领域事件 | import 任何框架类型 |
| `Infrastructure` | Doctrine 映射与仓储实现、外部 HTTP 客户端、Vault、Mailer | 被 Domain 直接引用（只能实现其接口） |

> **加密门面为什么跨三层**（T-005 修正）：本节早先把 `CryptoServiceInterface` 画在
> `Shared/Infrastructure/Crypto/` 下，但那样**没有任何模块能引用它** —— deptrac 里
> 每个模块 `Application` 的允许列表是 `[_Ports, <M>.Domain, Shared.Domain,
> Shared.Application, Framework.Core]`，不含 `Shared.Infrastructure`；`Domain` 更窄。
> 于是加解密只能发生在各模块自己的 Infrastructure 层，被迫塞进 Doctrine 仓储里，
> 而 T-109 的「列表查询用 BatchDecryptor」恰恰是 Application 层的编排。
>
> 因此：**值对象**（`CryptoKey` / `Ciphertext` / 两个异常）放 `Shared/Domain/Crypto`，
> **接口**放 `Shared/Application/Crypto`，**实现**留在 `Shared/Infrastructure/Crypto`。
> 与 `ErrorCode` 放在 `Shared/Domain/Error` 是同一套论证。
> 副作用是 T-005 那条验收标准「无模块直接 import `VaultTransitCrypto`」变成**结构性成立**：
> `Shared.Infrastructure` 不在任何模块的允许列表里，想违规都违不了
> （由 `tools/deptrac-selftest.sh` 的场景 ③ 钉住）。

### 12.3 Android 目录

```
android/
├── settings.gradle.kts
├── gradle/libs.versions.toml            # 版本目录，唯一依赖声明处
├── build-logic/                         # convention plugins
│   └── convention/src/main/kotlin/
│       ncards.android.application.gradle.kts
│       ncards.android.library.gradle.kts
│       ncards.android.feature.gradle.kts
│       ncards.android.hilt.gradle.kts
│       ncards.android.room.gradle.kts
│       ncards.kotlin.serialization.gradle.kts
├── app/                                 # 组装、NavHost、Application、DI 根
├── core/
│   ├── model/            # 纯 Kotlin：Card, Member, Friend, BarcodeFormat, SyncState
│   ├── common/           # Result, DispatcherProvider, Clock, UiText
│   ├── designsystem/     # Theme, Color tokens, Typography, 基础组件
│   ├── ui/               # 跨 feature 的复合组件（CardTile, EmptyState, ErrorPane）
│   ├── database/         # Room + SQLCipher, entities, DAOs, migrations
│   ├── datastore/        # Proto DataStore（cursor、设置）
│   ├── crypto/           # Keystore 包装、EncryptedPrefs、DB passphrase 供给
│   ├── network/
│   │   ├── api/          # ← openapi-generator 输出，只读，禁止手改
│   │   └── impl/         # OkHttp 配置、认证拦截器、错误映射、重试
│   ├── barcode/          # 单一入口：相机流扫描 + 静态图片解码（ML Kit）与渲染（ZXing）
│   │                     #   ImageBarcodeDecoder（降采样 + 多码结果），格式映射唯一实现处
│   └── testing/          # 测试替身、规则、假数据
├── data/
│   ├── auth/  card/  friend/  sharing/  sync/    # Repository 实现 + Entity↔Model 映射
├── sync/                 # SyncEngine, Outbox, ConflictResolver, Workers, FcmService
├── feature/
│   ├── onboarding/  wallet/  carddetail/  cardedit/  scan/
│   │     └ onboarding 含 username 设定页（不可跳过、二次确认）
│   ├── imageimport/ # Photo Picker → 解码 → 多码选择（§10.1）
│   ├── sharing/     friends/  settings/  legal/
│   │     └ friends 含 username 精确搜索（显式提交，无 typeahead）
├── widget/               # Glance AppWidget + TileService + Shortcuts
└── benchmark/            # Macrobenchmark（启动、Widget→条码）
```

**模块依赖规则（Gradle 强制）**

```
app → feature:* → data:* → core:*
feature:* 之间 禁止 互相依赖（跨 feature 导航通过 app 的 NavHost + core:model 的路由定义）
core:* 不得依赖 data:* / feature:*
sync 可依赖 data:* 与 core:*，不得被 feature:* 直接依赖（feature 只经 Repository）
```

违反用 Gradle 的模块可见性 + 自定义 lint 规则检查。

---

## 13. 开发规范

### 13.1 契约优先（MUST）

1. `docs/api/openapi.yaml`（OpenAPI 3.1）是**唯一真相源**。任何接口变更**必须**先改契约，在同一 PR 中一并修改双端。
2. CI 用 **Spectral** lint 契约（自定义规则集：必须有 `operationId`、必须有错误响应、必须有示例）。
3. **后端**：`tests/Api` 中的契约测试用 `league/openapi-psr7-validator` 对每个端点的真实请求/响应做 schema 校验。契约与实现不符 → CI 失败。
   - **不用** API Platform / NelmioApiDocBundle 自动生成契约（自动生成会让契约跟着实现漂移，方向反了）。
4. **Android**：CI 用 `openapi-generator`（`kotlin` + `retrofit2` + `kotlinx-serialization`）生成 `core:network:api`。生成产物**提交入库**（便于 code review 看到接口变化），但**禁止手改**——CI 会重新生成并 diff，不一致即失败。
5. 契约中所有 schema 必须设 `additionalProperties: true`（前向兼容）；客户端反序列化必须 `ignoreUnknownKeys = true`。

### 13.2 分支与提交

- **Trunk-based**：`main` 永远可发布。功能分支生命周期 **≤ 3 天**。
- 分支命名：`feat/<ticket>-<slug>`、`fix/…`、`chore/…`、`docs/…`。
- **Conventional Commits**（`commitlint` 在 CI 校验）：
  `feat(wallet): add card pinning` / `fix(sync): handle 409 on outbox flush`
- PR 要求：
  - 标题为 Conventional Commit 格式
  - 关联 issue
  - **1 个 approve** + 全部 CI 绿
  - **Squash merge**，squash 后的 commit message 即 PR 标题
  - PR 模板包含：变更说明、契约是否变更、迁移是否向后兼容、测试说明、截图（UI 变更）
- `main` 分支保护：禁止直推、禁止 force push、要求线性历史。
- 未完成功能用 **feature flag**（后端 `feature_flags` 从 `/v1/config` 下发；客户端 `BuildConfig` + 远程开关）合入 main，**不要**长期分支。

### 13.3 质量门禁（CI 阻断）

**后端**

| 检查 | 阈值 |
|---|---|
| `php-cs-fixer --dry-run` | 0 差异 |
| `phpstan analyse` | level **8**，`src/` 全量，0 error，baseline 只允许缩小不允许增长 |
| `deptrac analyse` | 0 violation |
| `composer audit` | 0 高危 |
| PHPUnit 单元 + 集成 | 全绿 |
| 行覆盖率 | 整体 ≥ **70%**；`src/Module/*/Domain` 与 `Application` ≥ **85%** |
| 契约测试 | 全绿 |
| Doctrine 迁移检查 | `doctrine:schema:validate` 通过；迁移可 `up`+`down` 往返 |

**Android**

| 检查 | 阈值 |
|---|---|
| `ktlintCheck` | 0 |
| `detekt` | 0 weighted issues（配置文件纳入版本控制） |
| Android Lint | 0 `error` 级；`HardcodedText`、`MissingTranslation`、`ContentDescription` 提升为 error |
| 单元测试 | 全绿；`core:*` 与 `data:*` 行覆盖 ≥ **70%** |
| Instrumentation 测试 | 关键路径（登录、加卡、开条码、共享）在 Gradle Managed Device 上通过 |
| `assembleRelease` | 成功，且 APK 大小回归 ≤ +500 KB（超出需 PR 说明） |
| 生成代码 diff | `core:network:api` 与契约重新生成结果一致 |

**通用**

| 检查 | 说明 |
|---|---|
| Secret 扫描 | `gitleaks`，0 命中 |
| 敏感日志扫描 | 自定义 grep：禁止 `dump(`、`var_dump`、`Log.d/v/i` 打印实体对象、禁止日志中出现 `barcode_value` / `email` 变量名直接插值 |
| TODO 检查 | `TODO` 必须带 issue 编号（`// TODO(#123):`），否则失败 |

### 13.4 测试策略

| 层 | 后端 | Android |
|---|---|---|
| 单元 | Domain 不变量、冲突判定、限额、权限矩阵 | ViewModel（Turbine）、ConflictResolver、映射器、BarcodeFormat 映射 |
| 集成 | 仓储 + 真实 Postgres/Redis/Vault（docker compose）、change_log 事务一致性 | Room DAO（in-memory + SQLCipher）、WorkManager（`TestListenableWorkerBuilder`） |
| 契约 | 每端点 request/response schema 校验 | 生成代码 diff |
| 端到端 | `tests/Api`：完整共享流程、删号流程、full_resync | Compose UI Test：J1/J2/J3 三条旅程 |
| 性能 | k6 压测脚本（sync 与 bootstrap） | Macrobenchmark（启动、Widget→条码） |

**必须存在的高价值测试（Definition of Done 的一部分）**

1. **权限矩阵测试**：对 `owner / viewer / 非成员` × 每个卡相关端点，断言允许/拒绝。参数化写，一个 data provider 覆盖全部组合（v1.1 取消 `editor` 后组合数减少，**但覆盖率要求不变——不允许因为"只剩两种角色"就抽样**）。必须显式包含：`viewer × PATCH/DELETE/invitations → 403`。
2. **删号完整性测试**：建立含共享、好友、邀请、设备的完整用户，执行硬删，逐表断言无残留；**并断言 viewer 侧收到卡墓碑**（§8.4）。
3. **同步收敛测试**：模拟同一 owner 的两台设备离线并发修改，回放 outbox，断言最终两端与服务端一致（属性测试更佳）。**另需一条单向性测试**：viewer 端构造一次非法上行 `PATCH`，断言服务端 403 且 viewer 本地不产生分叉。
4. **change_log audience 测试**：成员加入/移除后，断言正确的用户收到 upsert / tombstone；**新增**：owner 删卡/删号时 audience 取自删除前快照（§5.2）。
5. **【v1.1 新增】好友解除级联测试**：Anna↔Bob 互相共享若干卡，且各自与 Carol 有共享；解除 Anna↔Bob 后断言 ① 两人之间的共享全部 `left_at` 非空；② 与 Carol 的共享**不受影响**；③ 双方均收到成员墓碑；④ 待处理邀请被置为 `revoked`；⑤ 全部发生在一个事务内（中途注入异常 → 全部回滚，不出现"好友已解除但共享还在"）。
6. **【v1.1 新增】username 不变性测试**：设定后 `POST /v1/me/username` 与 `PATCH /v1/me`（含 `username` 字段）均返回 409；大小写/首尾空格的归一化等价（`Anna_B ` 与 `anna_b` 视为同一个，且第二次注册返回 `username_taken`）；保留词被拒；`onboarding_incomplete` 用户访问任意其他端点返回 403。
7. **【v1.1 新增】成员可见性测试**（C11，两层都要测）：一张卡有 owner + 3 个互不为好友的 viewer；断言 ① `GET /members` 对 viewer 只返回 2 行（owner + 自己）；② **新增/移除第 4 个 viewer 时，`change_log` 的 audience 只含 [owner, 该成员]**——前 3 个 viewer 的 `/v1/sync` 结果中**不出现**任何与他人相关的 `card_members` 行；③ viewer 拿到的 `Card` 无 `member_count` 字段。第 ② 条是真正的防线，只测 ① 等于没测。
8. **【v1.1 新增】图片解码测试**（Android 单元/仪器）：单码图、多码图、无码图、超大图（4000×3000 不 OOM）、EXIF 旋转 90° 的图；断言与相机路径产出**相同**的 `BarcodeFormat` 与 `rawValue`。

### 13.5 数据库迁移规范（MUST）

由于离线优先 + 旧客户端长期存在：

- **只允许 expand–contract**：
  1. 发布 N：加**可空**新列 / 新表；双写。
  2. 发布 N+1：回填历史数据；读切到新列。
  3. 发布 N+2（≥ 2 周后）：删除旧列 / 加 NOT NULL 约束。
- **禁止**：重命名列/表、直接删列、在一次迁移中 `ALTER ... SET NOT NULL` 无默认值、加会锁表的索引（用 `CREATE INDEX CONCURRENTLY`）。
- 每个迁移文件顶部注释必须写明：影响的表、预估执行时长、是否锁表、如何回滚。
- 迁移在部署流程中自动执行前，CI 会在 staging 的生产数据副本结构上 dry-run。

### 13.6 API 演进规范（MUST）

**允许（向后兼容）**：新增端点、新增可选请求字段、新增响应字段、新增枚举值（**前提**：客户端对未知枚举值有 `UNKNOWN` 兜底分支——这必须在生成器配置中保证）、放宽校验。

**禁止（在 `/v1` 内）**：删除/重命名字段、改字段类型、把可选变必填、收紧校验、改变错误 `code` 的含义、改变默认值语义。

破坏性变更 → 新增 `/v2`，`/v1` 至少并行 **6 个月**，期间通过 `/v1/config` 的 `min_supported_client` 推动升级。

### 13.7 ADR 流程

任何符合以下条件的决策**必须**写 ADR 到 `docs/adr/NNNN-title.md`：
- 改变 §2 中已有决策
- 引入新的基础设施组件或第三方服务（涉及 GDPR 子处理者，还需更新 §8.3）
- 改变数据模型的核心语义（共享、同步、加密）
- 引入新的跨模块依赖

ADR 模板：`Context / Decision / Consequences / Alternatives considered / Status`。由技术负责人 + 至少一名工程师批准。

### 13.8 Definition of Done

一个任务只有满足**全部**以下条件才算完成：

- [ ] 代码合入 `main` 且 CI 全绿
- [ ] 契约（若涉及）已更新，双端一致
- [ ] 单元测试覆盖新增逻辑分支；集成测试覆盖新增端点
- [ ] 德语 + 英语文案齐全（无 `MissingTranslation`）
- [ ] 无障碍检查通过（触摸目标、contentDescription、对比度）
- [ ] 若引入个人数据处理 → §8 ROPA 已更新
- [ ] 若引入新限额/端点 → §7.5 限流已配置
- [ ] 若需要运维介入 → runbook 已写
- [ ] 在真机（低端设备：Android 8 + 2 GB RAM）上验证过

---

## 14. 环境、CI/CD 与发布

### 14.1 环境

| 环境 | 域名 | 数据 | 部署 |
|---|---|---|---|
| local | `localhost` | 种子数据（`bin/console app:seed`） | `docker compose up` |
| staging | `api.staging.n-cards.de` | **合成数据**（禁止生产数据副本） | `main` 合入自动部署 |
| production | `api.n-cards.de` | 真实 | 手动 approve 后部署 |

**严禁**将生产数据（哪怕脱敏）复制到 staging 或开发机。需要真实规模测试时用生成器造数。

### 14.2 容器编排

`infra/compose/docker-compose.prod.yml` 服务清单：

| 服务 | 镜像 | 备注 |
|---|---|---|
| `caddy` | caddy:2 | TLS、反代、安全头、静态法律页 |
| `app` | 自建（FrankenPHP + Symfony） | 非 root，只读 rootfs |
| `worker` | 同上，入口 `messenger:consume` | 2 副本；`async` + `email` 两个 transport |
| `scheduler` | 同上，入口 `messenger:consume scheduler_default` | 每日清理（T-113 已交付）、删号执行（T-403）、导出过期清理（T-402）、key rewrap（T-404）。⚠️ **单副本**，且改成多副本前必须先加 `Schedule::lock()` —— 见 [ADR-0022](adr/0022-daily-cleanup-via-symfony-scheduler-single-replica-no-lock.md) 决定 4 |
| `postgres` | postgres:16-alpine | 独立卷；仅内网 |
| `redis` | redis:7-alpine | 限流、幂等键、缓存；`appendonly yes` |
| `vault` | hashicorp/vault:1.x | 独立卷；**人工 unseal**；仅内网 |
| `prometheus` / `grafana` / `loki` / `promtail` / `alertmanager` | | 内网 + Caddy basic auth 暴露 Grafana |
| `sentry`（可选自托管）或 Sentry EU SaaS | | 若自托管资源不足，用 Sentry 的 EU region（需 DPA） |
| `backup` | 自建（wal-g / pgBackRest + age） | cron 容器 |

### 14.3 CI/CD（GitHub Actions）

**PR 流水线**（≤ 12 分钟目标）
```
[并行]
  backend:  cs-fixer → phpstan → deptrac → unit → integration(services: pg/redis/vault) → api-contract
  android:  ktlint → detekt → lint → unit → assembleDebug
  shared:   spectral(openapi) → gitleaks → openapi-codegen-diff → commitlint
```

**main 合入流水线**（两条独立的链，并行；不是一条串起来的流水线）
```
全量 PR 检查（四条，无 paths 过滤）
  ├─► 构建后端镜像 → 推 GHCR（tag: sha + main）
  │     → 部署 staging（Ansible over SSH）→ 迁移 → 冒烟测试     ≈ 4 min
  ├─► android instrumentation (GMD, api 26 + api 34)             ≈ 25 min
  │     仅当本次合入触及 android/** 或 docs/api/**；
  │     另有每周一 03:00 UTC 的全量兜底
  └─► 构建 Android AAB → 上传 Play Internal Testing
```

⚠️ **instrumentation 不在部署链上**，这是 M1 显式决定的（原为串行）。串行时「合入 →
staging 可用」实测 **29m35s**，其中 25 分钟是一组跑在模拟器上、与后端能不能部署没有
因果关系的 Android 测试（测的是 `core:crypto` / `core:database`，纯本地）。拆开后
端到端约 4 分钟，覆盖不变 —— 仪器测试仍在同一次 run 里跑，红了照样可见。

**生产发布**（手动触发 + approval）
```
选择 sha → 备份数据库（前置快照）→ 滚动重启 app/worker
  → 执行迁移（doctrine:migrations:migrate --no-interaction --allow-no-migration）
  → 健康检查 → 若失败自动回滚到上一镜像 tag
  → Play：Internal → Closed Beta（~100 人，≥ 5 天）→ 分阶段生产（10% → 50% → 100%，每档观察 ≥ 24h）
```

**密钥管理**：GitHub Actions 使用 repository secrets（部署 SSH key、GHCR token、Play service account）。生产环境变量由 `sops`（age 密钥）加密后提交入库，部署时在目标主机解密。**Vault unseal key 绝不进入 CI**。

### 14.4 可观测性

**指标（Prometheus）**

| 指标 | 类型 | 标签 |
|---|---|---|
| `http_server_request_duration_seconds` | histogram | `route`, `method`, `status` |
| `sync_changes_returned` | histogram | — |
| `vault_operation_duration_seconds` | histogram | `op`(encrypt/decrypt/rewrap), `batch_size_bucket` |
| `otp_requests_total` / `otp_verifications_total` | counter | `result` |
| `login_total` | counter | `result`(registered/success/rejected)。⚠️ [ADR-0016](adr/0016-magic-link-delivery-and-landing-page.md) 之后它**聚合两条登录入口**（`otp/verify` 与 `magic/consume`）。语义仍是「一次登录尝试的结果」，但分不出用户走的哪条 —— 要分的话加一个 `method` 标签，那会改变既有序列的形状，归 T-405 决定 |
| `email_send_total` | counter | `provider`, `template`, `result`。⚠️ `template` 的取值域是 `MailTemplate` 的 case 名，[ADR-0016](adr/0016-magic-link-delivery-and-landing-page.md) 之后**没有** `magic_link`（码与链接同一封信）——「OTP 邮件量骤降」那条告警看的是总量趋势，不受影响 |
| `fcm_send_total` | counter | `result` |
| `outbox_messages_pending` | gauge | `transport` |
| `cleanup_runs_total` | counter | `task`。T-113 的每日清理，**每趟恒 +1（含 0 行的那些）**。⚠️ 它与下面那条必须分开看：合成一个的话「跑了但没东西可删」与「scheduler 挂了根本没跑」在指标上完全一样，而后者是这条链路唯一需要告警的故障 —— T-405 应据此配一条「24 小时内无增量」的告警 |
| `cleanup_rows_total` | counter | `task`。真删/改了行时才加，增量是行数。取值域闭合（就是已注册的任务名） |
| `cleanup_errors_total` | counter | `task`。单个任务抛异常即 +1，其余任务照跑（[ADR-0022](adr/0022-daily-cleanup-via-symfony-scheduler-single-replica-no-lock.md) 决定 6） |
| `card_count_total` / `user_count_total` | gauge | 业务健康度 |

**日志（Loki）**：JSON 结构化，字段固定 `ts, level, msg, request_id, user_id, route, duration_ms`。
**必须**配置 Monolog processor 脱敏：`barcode_value`、`note`、`email`、`code`、`refresh_token` 一律替换为 `[REDACTED]`。

**告警（Alertmanager → Email + Telegram）**

| 告警 | 条件 | 严重度 |
|---|---|---|
| API 5xx 率高 | > 1% 持续 5 min | P1 |
| OTP 转化率骤降 | 1 小时窗口 < 80% 且样本 > 20 | **P1**（邮件送达出问题 = 全站无法登录）。**v1.1：这是邮件侧唯一的缓解措施，因为已无双活可切**——告警触发时的处置是人工切换发信通道配置（`MAILER_DSN` 一行 + 重启 worker），必须写入 runbook |
| 邮件发送失败率 | > 5% 持续 10 min | P1（单通道，无自动 failover） |
| Vault sealed / 不可达 | 任意 | **P0**（全站读卡失败） |
| 数据库连接池耗尽 | > 90% 持续 2 min | P1 |
| 磁盘使用 | > 80% | P2 |
| 备份任务失败 | 任意一次 | P1 |
| 证书 30 天内过期 | — | P2 |
| Messenger 队列积压 | pending > 1000 持续 10 min | P2 |
| 崩溃率上升 | Sentry 新版本崩溃率 > 1% | P1 |

**隐私友好的产品分析**：一期**不接**任何第三方分析 SDK。产品指标从后端派生（DAU 由 `devices.last_seen_at` 推算、卡数分布、共享率），不做用户级行为埋点。

---

## 15. 里程碑计划（12 周，4 人：2 后端 / 2 Android；设计资源按需）

> 已包含 §3.14 的 23 人日复审成本。

### M0 — 地基（第 1 周）

| 交付 | 负责 |
|---|---|
| 仓库骨架、Convention plugins、CI 流水线全绿（跑空测试） | 全员 |
| `docker-compose` 本地栈（PG/Redis/Vault/Caddy/App）可一键起 | 后端 |
| Vault Transit 初始化 + `CryptoServiceInterface` + batch 加解密 + 单测 | 后端 |
| `openapi.yaml` v0：Auth + Cards 端点契约 + Spectral 通过 + 双端代码生成打通 | 全员 |
| Android 模块骨架、Theme、Room+SQLCipher 打通 | Android |
| Hetzner staging 主机 + Ansible + 自动部署跑通（部署 hello world） | 后端 |

**出口条件**：一次 PR 能自动跑完 CI 并部署到 staging。

### M1 — 认证与单人钱包（第 2–4 周）

| 交付 |
|---|
| 后端：OTP 请求/验证（恒发码、限流、常量时间）、Magic Link（POST 消费）、JWT + refresh 轮换与重放检测、设备管理 |
| 后端：**username**（`Username` 值对象、保留词、一次性写入端点、`username_required` 全局拦截器、僵尸行清理任务） |
| 后端：**单一**商业邮件通道集成（`MailSenderInterface` 抽象后置，DSN 可切换）+ 双语邮件模板 + 发送指标（§3.2：不做双活） |
| 后端：Cards CRUD + Vault 加密 + revision 乐观锁 + 限额校验 |
| Android：Onboarding（邮箱 → OTP → **username 设定（不可跳过 + 二次确认）** → 进入）、令牌存储与自动刷新拦截器 |
| Android：钱包列表、卡详情（全屏条码页含亮度/常亮/横屏）、新增/编辑（手动输入） |
| Android：CameraX + ML Kit 扫描（三帧确认、手电筒、手动回退） |
| Android：**从图片录入**（Photo Picker、降采样、多码选择、失败回落手动输入） |

**出口条件（Demo）**：真机上完成 登录 → **设定 username** → 扫码加卡 → **从相册图片加卡** → 全屏调出，全部走 staging 后端。

### M2 — 同步引擎与快速入口（第 5–7 周）

| 交付 |
|---|
| 后端：`change_log` + `ChangeLogSubscriber`（事务内写入）+ `/v1/sync` + `/v1/sync/bootstrap` + 稳定窗口 + full_resync |
| 后端：清理任务（change_log 90 天、otp、ip_hash 30 天）、Scheduler |
| Android：SyncEngine（Mutex、分页、游标持久化）、Outbox（合并、退避、失败态）、ConflictResolver（三路合并 + 码值冲突副本） |
| Android：四层触发（FCM 占位、前台、WorkManager、下拉） |
| Android：Glance Widget、QS Tile、App Shortcuts |
| 测试：同步收敛测试、离线场景端到端 |

**出口条件**：两台真机登录同一账号，离线各改一张卡，恢复网络后收敛一致；Widget 可用。

### M3 — 好友与共享（第 8–10 周）

| 交付 |
|---|
| 后端：Friendship（**username 精确查找** → 请求/接受/拒绝/删除/拉黑）+ lookup 双维度限速 |
| 后端：**解除好友/拉黑 → 级联撤销共享**（同事务 + 双向墓碑 + `shared-summary` 预览端点） |
| 后端：ShareInvitation（无 role）、CardMember（owner/viewer）、权限矩阵、接受时四项重校验、成员私有的 placement |
| 后端：FCM 集成（data-only）+ 推送指标 |
| Android：好友列表/请求/**username 搜索（显式提交，无 typeahead）**、共享面板（无角色选择）、成员管理、退出共享、**只读徽章 + "由 {username} 共享"** |
| Android：解除好友的影响预览与二次确认；viewer 侧编辑入口的 UI 级禁用 |
| Android：FirebaseMessagingService → 唤醒 SyncEngine → 本地通知（三渠道 + 权限时机） |
| 测试：权限矩阵参数化测试、**好友解除级联测试**、共享端到端 |

**出口条件**：两个真实账号完成 **username 搜索加好友** → 共享（对方接受）→ **owner 单向编辑同步、viewer 无编辑入口** → 移除成员（对方本地清理）→ **解除好友后共享自动消失** 全流程。

### M4 — 合规、加固、上架（第 11–12 周）

| 交付 |
|---|
| 后端：数据导出（异步 + 一次性下载令牌）、删号（**影响预览 / 宽限 / 级联硬删（不转让）** + 完整性测试）、audit_log |
| 后端：告警规则、Grafana 核心看板、备份 + **实际恢复演练**、runbooks 全套 |
| Android：设置页（设备管理、语言、生物识别锁、诊断开关、法律页面、删号入口） |
| 双语文案终审（母语者校对）、无障碍清扫、Macrobenchmark 达标 |
| Play 上架：商店素材、Data Safety 表单、隐私政策 URL、站外删号页 |
| DPIA 简化评估归档、律师复核法律文本 |
| Closed Beta（≥ 100 人，≥ 5 天）→ 分阶段生产发布 |

### 15.1 上线必需 vs 上线后 30 天（§3.11 的裁剪）

| 上线必需 | 上线后 30 天内 |
|---|---|
| GDPR 导出 / 删除 / 法律页面 | 完整 Grafana 看板体系 |
| 威胁模型中标 ✅ 的全部缓解 | 证书固定（若一期记为已接受风险） |
| 四家主流德国邮箱的 OTP 送达手工验证（§3.2） | 专业 ESP + 双活 failover（§3.2 已接受风险的偿还项） |
| 好友解除级联测试 + 权限矩阵测试全绿（T20/T13 的唯一保障） | username 滥用观察（冒名、枚举速率）后再决定是否加冷却期 |
| 关键路径无障碍 + 双语 | 非核心路径无障碍打磨 |
| 备份 + 一次成功的恢复演练 | 自动化恢复演练 |
| 核心 SLI 埋点 + P0/P1 告警 | P2 告警与容量看板 |
| 三条核心旅程的 E2E 测试 | E2E 矩阵扩充、属性测试 |
| Vault 独立部署 + unseal runbook | Vault HA / auto-unseal 方案评估 |

### 15.2 二期（iOS）的前置准备

一期**必须**做到（否则二期返工）：
- 全部业务逻辑在服务端，客户端不承载规则（角色权限、限额均服务端强制）。
- 契约完备且有示例，iOS 可直接生成客户端。
- 冲突解决协议已文档化（§5.4.3），iOS 需实现相同语义。
- 深链接域名与 `assetlinks.json` 已就位，二期加 `apple-app-site-association` 即可。
- `encryption_scheme` 字段已存在，为可能的 E2EE 演进留路。

---

## 16. 风险登记册

| # | 风险 | 概率 | 影响 | 缓解 | 负责人 |
|---|---|---|---|---|---|
| R1 | 邮件通道故障 / 发信域名被列入黑名单 → 全站无法登录 | 中 | **致命** | **v1.1 缓解已降级为：** DMARC/SPF/DKIM + 外发限额 + 90 天滑动 refresh（存量用户不受影响）+ OTP 转化率 P1 告警 + 人工切换 DSN 的 runbook。**ESP 双活已撤销，记为已接受风险**（§3.2），告警触发即为重启该决策的信号。**⚠️ Q3 定案（域名邮箱，[ADR-0013](adr/0013-mail-via-domain-mailbox.md)）使本风险的概率上调**：共享托管的出口 IP 声誉不由我们控制（同池租户会连累送达率），且**超发信配额会导致发信账号被托管商停用**——这是一条 v1.1 原本没有的新触发路径。对应地，「外发限额」那条缓解从告警手段升格为**保护手段**，熔断阈值必须压在实测配额之下 | 后端负责人 |
| R2 | Vault 数据丢失 → 全部卡不可解密 | 低 | **致命** | 独立卷 + 每日快照 + unseal key 离线三份分存 + 季度恢复演练 | 技术负责人 |
| R3 | 冲突解决实现有 bug → 用户数据丢失 | **低**（v1.1 单写者模型使冲突面大幅收窄，§3.5） | 高 | 属性测试 + 码值冲突绝不静默丢弃（§3.5）+ Beta 期重点观察 | Android 负责人 |
| R4 | FCM 在国产 ROM 上不送达 → "共享不实时" 差评 | **高** | 中 | 四层触发（§3.6）+ 电池优化引导 + 前台立即同步 + 文案预期管理 | Android 负责人 |
| R5 | 12 周工期不足 | **中**（v1.1 净减约 7 人日，§3.14） | 中 | §15.1 已明确裁剪档；M2 结束时做一次强制范围复盘，必要时把"拉黑"（保留解除好友即可）与"从其他 App 分享图片进来"砍到上线后 | 技术负责人 |
| R6 | ML Kit bundled 模型使 APK 超预算 | 低 | 低 | 已计入 20 MB 预算；必要时用 ABI splits / App Bundle 按需分发 | Android |
| R7 | 单主机故障导致长时间不可用 | 中 | 中 | 离线优先使读场景不受影响；RTO 4h；成本可控时升级为双机 + 托管 PG | 技术负责人 |
| R8 | 法律文本未及时通过律师复核阻塞上架 | 中 | 中 | M1 即启动律师沟通，不要留到 M4 | 技术负责人 |
| R9 | 用户不理解"共享同一张卡"与"发副本"的差别，误删共享卡 | 中 | 中 | owner 删卡/删号时明确告知会从 N 位成员钱包移除；viewer 只能"退出"不能"删除"；共享卡持久显示"由 {username} 共享 · 只读" | 产品/设计 |
| R12 | **用户失去共享卡的四条路径**（owner 删卡 / owner 删号 / 被移除 / **解除好友级联**）都表现为"卡突然消失"，用户误以为是 Bug | **高** | 中 | 卡消失时**必须**给出本地通知说明原因（四种文案分开写）；共享卡常驻来源标识；FAQ 页专门解释。**这是 v1.1 三项语义收紧（C6/C8）共同带来的新体验风险，不要漏做文案** | 产品/设计 |
| R13 | username 不可变**且是唯一的名字**（C10 移除 display_name 后无退路）→ 用户设错名字后要求改名，形成客服负担 | **中高**（v1.1 移除 display_name 后上升） | 低 | 设定页**二次确认对话框** + 明确文案 `Dieser Name kann später nicht geändert werden`；输入时实时显示字符集规则；改名诉求统一回复"注销后重新注册"（一期数据量小，且注销即彻底删除，成本可接受）。**若 Beta 期改名诉求显著（> 5% 用户），优先选项是二期加"本地备注名"而非放开 username 可变**——后者会破坏社交锚点 | 产品 |
| R14 | 好友添加门槛变高（必须知道完整 username）→ 共享功能使用率低于预期 | 中 | 中 | 这是安全性换来的**已知代价**（§3.8）。缓解：设置页把自己的 username 做成一键复制的醒目卡片，便于用户通过任意 IM 发给对方；Beta 期观测"发出好友请求数/DAU"，若显著偏低再评估二期是否加"本机二维码展示 username"（**不是**邀请链接，无服务端状态） | 产品 |
| R10 | 证书固定配置错误导致全量客户端断连 | 低 | **致命** | 双 pin + staging 演练 + 日历提醒；无把握则一期不做（§7.2 T15） | 后端负责人 |
| R11 | 德语文案由非母语者撰写，观感不专业 | 中 | 中 | M4 安排母语者通读校对，预算 1–2 人日 | 产品 |

---

## 17. 附录

### 17.1 关键 DDL 片段

```sql
-- users（v1.1：新增 username）
CREATE TABLE users (
  id                    UUID PRIMARY KEY,
  email_hash            BYTEA NOT NULL UNIQUE,
  email_encrypted       TEXT  NOT NULL,
  username              TEXT  UNIQUE                      -- 可空：注册中间态，见 §5.2
                          CHECK (username ~ '^[a-z0-9_]{3,20}$'),
  -- T-107：§7.5 的「POST /v1/me/username 按 user 10 次总计」。**生命周期计数**，
  -- 不是滑动窗口，所以它在这张表上而不在 Redis —— §8.2 的 ROPA 规定限流计数
  -- 只保留 24 小时，而这个计数要跨越整个账号生命周期。见 ADR-0017。
  -- ⚠️ 刻意不加 CHECK：上限 10 是策略，真相在
  --    %ncards.limits.username_attempts_per_user%；写进库层会让改数字要发两次。
  username_attempts     SMALLINT NOT NULL DEFAULT 0,
  -- v1.1：无 display_name 列（C10），username 即唯一展示名
  locale                TEXT  NOT NULL DEFAULT 'de' CHECK (locale IN ('de','en')),
  status                TEXT  NOT NULL DEFAULT 'active'
                          CHECK (status IN ('active','pending_deletion')),
  deletion_requested_at TIMESTAMPTZ,
  created_at            TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at            TIMESTAMPTZ NOT NULL DEFAULT now()
);
-- username 已在应用层归一化为小写，故普通 UNIQUE 即等价于大小写不敏感唯一。
-- CHECK 约束是第二道防线：即使应用层有 bug，也绝不会写入大写或非法字符。
-- 精确查找走该唯一索引，无需额外索引。
-- ⚠️ 不可变性由"没有 UPDATE 端点"保证，DB 层不加触发器——
--    触发器会挡住合法的运维修复，且与 Doctrine 的批量更新交互不佳。

-- otp_challenges
-- ⚠️ **刻意没有到 users 的外键**，存的是 email_hash 而不是 user_id。
--    ADR-0014 之后理由更硬：otp/request **不查 users**，所以挑战可能
--    先于用户存在（首次验证成功时才建 users 行）。有外键就插不进去。
CREATE TABLE otp_challenges (
  id               UUID        PRIMARY KEY,
  email_hash       BYTEA       NOT NULL,
  email_encrypted  TEXT,                          -- T-104：收件人密文，也是注册时建 users 行的输入
  locale           TEXT        CHECK (locale IN ('de','en')),  -- T-104：注册时进 users.locale
  code_hash        BYTEA       NOT NULL,          -- HMAC-SHA256(code, pepper)，不存明文
  magic_token_hash BYTEA,                         -- Magic Link 令牌哈希（本地 SHA-256，不是 Vault HMAC —— ADR-0016）
  purpose          TEXT        NOT NULL,          -- 一期恒为 'login'
  attempts         SMALLINT    NOT NULL DEFAULT 0,  -- 到 §7.1 的上限（5）为止饱和，不无限累加
  expires_at       TIMESTAMPTZ NOT NULL,
  consumed_at      TIMESTAMPTZ,
  is_decoy         BOOLEAN     NOT NULL DEFAULT false,  -- ⚠️ ADR-0014 起无写入方，恒 false；T-113 已切读，删列归后续卡（ADR-0022 决定 8）
  request_ip_hash  BYTEA,                         -- 30 天上限，由 T-113 的兜底任务置空（§8.2）
  created_at       TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_otp_challenges_email_hash ON otp_challenges (email_hash);
-- T-106：Magic Link 的查找键。UNIQUE 是安全不变量的库层兜底（一个令牌只能
-- 对应一条挑战）；碰撞概率是 2^-256，所以它真正防的是代码 bug，而那种 bug
-- 没有约束时在库里看起来完全正常。
-- ⚠️ 刻意**不是**部分索引（`WHERE magic_token_hash IS NOT NULL` 更省，但 DBAL
--    读不回 WHERE 子句，schema:validate 会永久报「不同步」——实测）。
CREATE UNIQUE INDEX uq_otp_challenges_magic_token_hash ON otp_challenges (magic_token_hash);

-- devices（id 由客户端生成：安装级唯一，重装即新设备）
CREATE TABLE devices (
  id                    UUID        PRIMARY KEY,
  user_id               UUID        NOT NULL,
  platform              TEXT        NOT NULL,     -- 一期恒为 'android'
  model                 TEXT,
  os_version            TEXT,
  app_version           TEXT,
  push_token            TEXT,                     -- 设备撤销后即清空（§8.2）
  push_token_updated_at TIMESTAMPTZ,
  last_seen_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
  revoked_at            TIMESTAMPTZ,
  created_at            TIMESTAMPTZ NOT NULL DEFAULT now(),
  CONSTRAINT fk_devices_user_id FOREIGN KEY (user_id)
    REFERENCES users (id) ON DELETE CASCADE
);
CREATE INDEX idx_devices_user_id ON devices (user_id);

-- sessions（id 即 JWT 的 sid claim）
CREATE TABLE sessions (
  id                  UUID        PRIMARY KEY,
  user_id             UUID        NOT NULL,
  device_id           UUID        NOT NULL,
  refresh_token_hash  BYTEA       NOT NULL,       -- SHA-256（令牌是 32 字节随机）
  previous_token_hash BYTEA,                      -- 轮换重放检测，只留一代
  expires_at          TIMESTAMPTZ NOT NULL,       -- now() + 90d，每次轮换顺延
  revoked_at          TIMESTAMPTZ,
  revoked_reason      TEXT,                       -- logout / reuse_detected / user_revoked / account_deleted
  created_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
  CONSTRAINT fk_sessions_user_id FOREIGN KEY (user_id)
    REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_sessions_device_id FOREIGN KEY (device_id)
    REFERENCES devices (id) ON DELETE CASCADE
);
CREATE UNIQUE INDEX uq_sessions_refresh_token_hash ON sessions (refresh_token_hash);
CREATE INDEX idx_sessions_user_id ON sessions (user_id);
CREATE INDEX idx_sessions_device_id ON sessions (device_id);
-- ⚠️ platform / purpose / revoked_reason **不加 CHECK 约束**（与 users 的三列相反）。
--    §13.6 把"新增枚举值"列为向后兼容变更；加了 CHECK 之后每加一个取值都要先发
--    一次迁移改约束、再发一次代码放开取值域，而这三列的取值域本来就还会长。
--    取值域由 PHP enum 表达，"enum 与库不同步"由集成测试兜（每个 case 真写一次）。

-- cards
CREATE TABLE cards (
  id                        UUID PRIMARY KEY,
  owner_id                  UUID NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
  title                     TEXT NOT NULL CHECK (char_length(title) <= 100),
  merchant_label            TEXT CHECK (char_length(merchant_label) <= 100),
  color                     TEXT NOT NULL,
  barcode_format            TEXT NOT NULL,
  barcode_value_encrypted   TEXT NOT NULL,
  barcode_value_fingerprint BYTEA,
  note_encrypted            TEXT,
  expires_on                DATE,
  encryption_scheme         TEXT NOT NULL DEFAULT 'server_v1',
  revision                  BIGINT NOT NULL DEFAULT 1,
  deleted_at                TIMESTAMPTZ,
  created_at                TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at                TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_cards_owner ON cards(owner_id) WHERE deleted_at IS NULL;

-- card_members
CREATE TABLE card_members (
  card_id    UUID NOT NULL REFERENCES cards(id) ON DELETE CASCADE,
  user_id    UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  role       TEXT NOT NULL CHECK (role IN ('owner','viewer')),   -- v1.1：editor 已移除
  sort_order INTEGER NOT NULL DEFAULT 0,
  is_pinned  BOOLEAN NOT NULL DEFAULT false,
  added_by   UUID REFERENCES users(id) ON DELETE SET NULL,
  joined_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
  left_at    TIMESTAMPTZ,
  PRIMARY KEY (card_id, user_id)
);
CREATE UNIQUE INDEX uq_card_single_owner
  ON card_members (card_id) WHERE role = 'owner' AND left_at IS NULL;
CREATE INDEX idx_card_members_user
  ON card_members (user_id) WHERE left_at IS NULL;

-- friendships（规范化顺序）
CREATE TABLE friendships (
  id            UUID PRIMARY KEY,
  user_low_id   UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  user_high_id  UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  status        TEXT NOT NULL CHECK (status IN ('pending','accepted','blocked')),
  requested_by  UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  blocked_by    UUID REFERENCES users(id) ON DELETE CASCADE,
  created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
  responded_at  TIMESTAMPTZ,
  CONSTRAINT chk_friendship_order CHECK (user_low_id < user_high_id),
  CONSTRAINT uq_friendship UNIQUE (user_low_id, user_high_id)
);

-- share_invitations（v1.1：无 role 列，被分享者恒为 viewer）
CREATE TABLE share_invitations (
  id           UUID PRIMARY KEY,
  card_id      UUID NOT NULL REFERENCES cards(id) ON DELETE CASCADE,
  inviter_id   UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  invitee_id   UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  status       TEXT NOT NULL CHECK (status IN ('pending','accepted','declined','revoked','expired')),
  expires_at   TIMESTAMPTZ NOT NULL,
  created_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
  responded_at TIMESTAMPTZ
);
-- 同一张卡对同一被邀请人只允许一条 pending
CREATE UNIQUE INDEX uq_pending_invitation
  ON share_invitations (card_id, invitee_id) WHERE status = 'pending';

-- v1.1 已删除的表：friend_invite_links（取消邀请链接，C2）
```

### 17.2 OpenAPI 片段示例

```yaml
openapi: 3.1.0
info: { title: N-Cards API, version: "1.0.0" }
servers: [ { url: https://api.n-cards.de/v1 } ]

paths:
  /cards/{cardId}:
    patch:
      operationId: updateCard
      summary: Update a card (optimistic locking)
      parameters:
        - name: cardId
          in: path
          required: true
          schema: { type: string, format: uuid }
        - name: If-Match
          in: header
          required: true
          description: Current revision of the card, e.g. `"7"`
          schema: { type: string }
      requestBody:
        required: true
        content:
          application/json:
            schema: { $ref: '#/components/schemas/CardUpdate' }
      responses:
        '200':
          description: Updated
          content:
            application/json:
              schema: { $ref: '#/components/schemas/Card' }
        '409':
          description: Revision conflict
          content:
            application/problem+json:
              schema: { $ref: '#/components/schemas/RevisionConflictProblem' }

components:
  schemas:
    Card:
      type: object
      additionalProperties: true
      required: [id, title, color, barcode_format, barcode_value, revision, updated_at]
      properties:
        id:              { type: string, format: uuid }
        title:           { type: string, maxLength: 100 }
        merchant_label:  { type: [string, "null"], maxLength: 100 }
        color:           { type: string, example: blue_600 }
        barcode_format:  { $ref: '#/components/schemas/BarcodeFormat' }
        barcode_value:   { type: string, maxLength: 1024, description: Plaintext over TLS; encrypted at rest }
        note:            { type: [string, "null"], maxLength: 2000 }
        expires_on:      { type: [string, "null"], format: date }
        owner_id:        { type: string, format: uuid }
        owner_username:  { $ref: '#/components/schemas/Username' }   # for the "shared by …" label
        my_role:         { type: string, enum: [owner, viewer] }
        can_edit:        { type: boolean, description: Convenience mirror of my_role == owner; clients MUST gate edit UI on this }
        member_count:
          type: integer
          minimum: 1
          description: >
            OWNER ONLY. Omitted entirely when my_role == viewer — the number of
            co-viewers is itself information a viewer must not have (§5.2 member
            visibility). Clients MUST NOT infer a default when absent.
        sort_order:      { type: integer }
        is_pinned:       { type: boolean }
        revision:        { type: integer, format: int64 }
        updated_at:      { type: string, format: date-time }
    BarcodeFormat:
      type: string
      enum: [EAN_13, EAN_8, UPC_A, UPC_E, CODE_128, CODE_39, CODE_93, ITF,
             CODABAR, QR_CODE, AZTEC, PDF_417, DATA_MATRIX]
      description: Clients MUST treat unknown values as UNKNOWN and render a fallback.
    Username:
      type: string
      pattern: '^[a-z0-9_]{3,20}$'
      example: anna_b
      description: >
        Globally unique, immutable, lowercase. The only lookup key for adding friends.
        Clients MUST normalise input with trim + lowercase(Locale.ROOT) before sending.
    PublicUser:
      type: object
      additionalProperties: true
      required: [user_id, username]
      description: >
        The ONLY shape returned by /users/lookup. Never add fields here — email,
        registration date, friend/card counts would turn enumeration into intelligence
        gathering (§3.8 T18). There is no display_name in v1.1 (C10).
      properties:
        user_id:  { type: string, format: uuid }
        username: { $ref: '#/components/schemas/Username' }
```

**v1.1 从契约中移除的定义**（若旧 `openapi.yaml` 中存在，须一并删除，Spectral 会因悬空 `$ref` 报错）：
`FriendInviteLink`、`RedeemInviteLinkRequest`、`TransferOwnershipRequest`、`UpdateMemberRoleRequest`、`FriendRequestByEmail`，以及删号响应中的 `ownership_transfers` 字段。角色枚举收缩为 `[owner, viewer]`。

> ⚠️ **契约收缩是破坏性变更**（§13.6 禁止在 `/v1` 内删字段/删端点/收紧枚举）。这里之所以允许，是因为 **v1.1 发生在一期上线之前，`/v1` 尚未有任何线上客户端**。一旦上线，同样的改动就必须走 `/v2` + 6 个月并行。**这是最后一次可以自由收缩契约的机会**——所有语义收敛必须在 M3 结束前定稿。

### 17.3 FCM 载荷（固定，不得扩展）

```json
{
  "message": {
    "token": "<device_push_token>",
    "data": { "t": "sync", "v": "1" },
    "android": { "priority": "high", "ttl": "3600s" }
  }
}
```

**禁止**添加 `notification` 对象；**禁止**在 `data` 中加入任何业务字段（§3.12）。

### 17.4 Vault 策略示例

```hcl
# ncards-app policy
path "transit/encrypt/ncards-card"   { capabilities = ["update"] }
path "transit/decrypt/ncards-card"   { capabilities = ["update"] }
path "transit/encrypt/ncards-pii"    { capabilities = ["update"] }
path "transit/decrypt/ncards-pii"    { capabilities = ["update"] }
path "transit/hmac/ncards-hmac"      { capabilities = ["update"] }
path "transit/verify/ncards-hmac"    { capabilities = ["update"] }
path "auth/token/renew-self"         { capabilities = ["update"] }
# 显式不授予：keys/*（不可读密钥）、rotate、rewrap（由独立的 ncards-ops policy 持有）
```

### 17.5 开放问题（需在 M1 前决策，记录负责人与截止日）

| # | 问题 | 建议默认值 | 决策人 | 截止 |
|---|---|---|---|---|
| ~~Q1~~ | ~~最终品牌名与域名~~ | **已决（2026-08-30）：品牌显示名 `N-Cards`，域名 `n-cards.de`。命名按「谁在读」分两层——人读写 `N-Cards`，机器读写 `ncards`（包名 `de.ncards`、插件 id `ncards.*`、Vault key `ncards-*`、资源名 `Theme.NCards`）；判据不是语法能否带连字符，插件 id 与 Vault key 允许带仍不带。见 [ADR-0002](adr/0002-brand-name-and-domain.md)** | 创始人 | ✅ M0 |
| Q2 | 运营主体（GmbH / UG / 个人）与 Impressum 内容 | — | 创始人 | M1 结束 |
| ~~Q3~~ | ~~邮件服务商最终选型~~ | **已决（2026-09-05）：发信走 `n-cards.de` 的域名邮箱（托管方 dogado GmbH，德国多特蒙德），标准 SMTP submission，不采购专业 ESP。** 三条硬要求逐条满足：EU/EEA 处理 ✅、可签 AVV/DPA ✅（**仍须实际签署**）、自定义域 SPF ✅ / DKIM ✅（2026-09-06 确认：dogado 已自动写入，selector `cloudpit`；待实发验证 `d=` 对齐）。**不做双活**（§3.2）。代价是发信配额有上限、共享 IP 声誉、无 bounce/投诉回路 —— **§9.3 的 5 万注册目标会越过这个上限**，届时按 §3.2 原方案补做专业 ESP（2 人日，抽象层已就位）。见 [ADR-0013](adr/0013-mail-via-domain-mailbox.md) | 创始人 + 后端负责人 | ✅ M1 |
| Q4 | 一期是否启用证书固定 | **不启用**，记为已接受风险，上线后 30 天内加 | 技术负责人 | M3 |
| Q5 | Sentry 自托管 vs EU SaaS | EU SaaS（省运维，需 DPA） | 技术负责人 | M1 |
| ~~Q6~~ | ~~Vault unseal 方案（人工 vs 外部 KMS auto-unseal）~~ | **已决（2026-08-28）：人工 Shamir 3-of-5 + runbook，auto-unseal 关闭。见 [ADR-0004](adr/0004-manual-vault-unseal.md) 与 [`docs/runbooks/vault-unseal.md`](runbooks/vault-unseal.md)** | 技术负责人 | ✅ M0 |
| ~~Q7~~ | ~~卡片调色板的具体色值（需满足 4.5:1 对比度）~~ | 🟡 工程侧已闭环（T-153，2026-09-12），待设计复核。色值在 `core:designsystem`，键在 `core:model` 的 `CardColor`；「逐个校验」由 `CardColorContrastTest` 自动断言。改色值不改枚举 | 设计 | ~~M1~~ |
| Q8 | 是否上架 F-Droid（会与 ML Kit/FCM 冲突） | 一期不上 | 创始人 | M4 |
| Q9 | username 保留词黑名单的最终清单（德语场景需补 `impressum`、`hilfe`、`konto` 等） | **草案已交付（2026-09-07，T-107），待产品确认**：12 个词，见 `backend/config/packages/ncards_username.yaml` —— §3.8 的九个通用词 + 德语场景的 `impressum` / `hilfe` / `konto`。**精确匹配**，不做前缀/子串/变体（论证见 [ADR-0017](adr/0017-username-assignment-and-lifetime-attempt-counter.md) 决定五）。确认后改配置里的一行即可，代码不用动。⚠️ 加词要写**机器读形态**：`n-cards` 这种带连字符的写法过不了字符集，会成为一条永远匹配不到任何输入的死规则（`UsernameRulesTest` 会拦下来） | 产品 | M1 结束 |
| Q10 | 用户强烈要求改 username 时的人工处理口径（拒绝 / DBA 手工改 / 引导注销重注册） | **一期一律拒绝并引导注销重注册**，不开人工通道（开了就会有第二个）。注意 C10 后已无 `display_name` 作为退路，此口径需在 FAQ 写清 | 产品 | M3 |
| Q11 | 共享卡在 viewer 侧是否计入其"每用户卡数 500"限额 | **不计入**（限额针对自有卡；否则 owner 可通过共享消耗他人配额，是一种滥用面） | 后端负责人 | M3 |

---

**文档结束。**

> 变更本文档需提 PR，标题以 `docs(spec):` 开头，并在 §2 或 §3 留下可追溯的记录。
