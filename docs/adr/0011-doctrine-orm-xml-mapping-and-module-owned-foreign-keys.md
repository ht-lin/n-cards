# 0011. Doctrine ORM 用 XML 映射、实体非 final、外键只在模块内部建成 ORM 关联

- **Status**: Accepted
- **Date**: 2026-09-05
- **Deciders**: 后端负责人
- **规格引用**: §4.2、§5.2、§12.2、§13.3、§13.5、§17.1
- **影响**：T-101（本 ADR 随其落地）、T-103 ~ T-113（全部 Identity 端点）、**T-109（cards.owner_id 跨模块外键，见「遗留问题」）**、T-201/T-202（Sync 的只读跨模块查询）

## Context

T-101 是本仓库第一次装 Doctrine **ORM**（T-003 只装了 DBAL，为了 `/health/ready` 探活）。
装的时候撞上三条互相牵扯的约束，任何一条单独看都有多个合理解法，合在一起只剩一条路。

**约束 1 —— Domain 层不得 import 任何框架类型（§12.2）。**
这不是一句倡议，`deptrac.yaml` 里每个模块的 Domain 图层允许列表逐字是 `[Shared.Domain]`，
一项 `Framework.*` 都没有，而 `Framework.Persistence` 收的正是 `^(Doctrine|Symfony\Bridge\Doctrine)\\`。
§13.3 要求 deptrac 0 violation。

**约束 2 —— `doctrine:schema:validate` 必须通过（T-101 验收标准）。**
这条命令拿 ORM 元数据生成的 schema 与真库内省结果做 diff。它比想象中严格：
DBAL 的 `Comparator` 按**名字**匹配索引与唯一约束，按**结构**（列 / 目标表 / onDelete）
匹配外键，按 `getColumnDeclarationSQL()` 的**字符串相等**比较列（DEFAULT 也在里面）。
迁移里存在而 ORM 元数据里没有的对象，一律被算成「待删」→ not in sync。

**约束 3 —— 一张表由且仅由一个模块拥有，跨模块只能走 `Application\Port\*`（§4.2 规则 1/2/5）。**
`Wallet.Domain` 的允许列表里没有 `Identity.Domain`，反之亦然。

同时，§5.2 与 §17.1 明确要求库层有外键，且**显式命名** `fk_<table>_<column>`。

## Decision

### 1. 实体是纯 PHP，映射是 XML，放在 Infrastructure

实体在 `Module/<M>/Domain/Entity/`，不带任何 `#[ORM\*]` 属性。
映射在 `Module/<M>/Infrastructure/Doctrine/Mapping/<Entity>.orm.xml`，
在 `config/packages/doctrine.yaml` 的 `orm.mappings` 里逐模块登记
（`type: xml` / `is_bundle: false` / `prefix` 指向 Domain 的实体命名空间）。

`auto_mapping: false` —— 每个模块都要显式登记，「加了实体但忘了登记」在
`doctrine:mapping:info` 里立刻现形，而不是安静地变成一个不受管的普通类。

同时关掉 `orm.controller_resolver.auto_mapping`：开着的话
`public function show(User $user)` 就能让控制器直接查库，把 Application 层绕过去，
而这是 deptrac 拦不住的（框架在背后做的）。

### 2. 实体属性一律不加 `readonly`，实体类不加 `final`

本仓库其余地方一律 `final readonly class`（`Uuid`、`Ciphertext`、`Cursor` 都是）。
**三个 Identity 实体是有据可查的例外**：Doctrine 的懒加载对象要继承实体类并在实例
已存在之后回填属性，而 `final` 挡住继承、`readonly` 挡住回填。

「事实上不可变」改由**没有 setter** 保证 —— 与 §17.1 里 username 的不可变性靠
「没有 UPDATE 端点」保证是同一个做法（那里也明确拒绝了用 DB 触发器强制）。

### 3. 外键必须建成 ORM 关联（`<many-to-one>`），且只在模块内部建

约束 2 的直接推论：把 `user_id` 映射成普通 `uuid` 列的话，库里那条
`fk_devices_user_id` 在 ORM 侧没有对应物，`schema:validate` 永远绿不了。
所以 `Device.user`、`Session.user`、`Session.device` 都是真关联。

三条 join-column 都要写 `on-delete="CASCADE"` —— Comparator 不比外键**名字**
（所以迁移可以自由地用 §5.2 要求的 `fk_<table>_<column>`），但**比 onDelete**。

**外键索引也要在映射里显式命名。** DBAL 会给每条外键自动补一条 `IDX_<hash>` 索引，
而索引是按名字比的 —— 不先声明同名索引，迁移里写什么名字都会 diff。

### 4. 唯一约束、索引与每一个 DEFAULT 都要在映射里重复声明一遍

名字与迁移逐字相同（`uq_users_email_hash`、`idx_otp_challenges_email_hash`……），
DEFAULT 用 `<options><option name="default">now()</option></options>`。

这是 T-101 唯一真正繁琐的地方，但它换来一条硬保证：
`doctrine:schema:validate` 从「大概对得上」变成「逐字对得上」，
而 CI 每次都跑（`tools/migration-check.sh` 步骤 ③）。

### 5. CHECK 约束只加 §17.1 明写的三条

`users.username` / `users.locale` / `users.status` 有 CHECK；
`devices.platform` / `otp_challenges.purpose` / `sessions.revoked_reason`
只做 PHP enum，**不加** CHECK。

理由是 §13.6 把「新增枚举值」列为向后兼容变更。加了 CHECK 之后，每加一个取值
都要先发一次迁移改约束、再发一次代码放开 enum —— 而后三列的取值域本来就还会长
（iOS、OTP 的二次确认用途、新的撤销原因）。

「enum 与 CHECK 不同步」这件事由集成测试兜：每个 case 都真的往库里写一次
（`IdentitySchemaTest`），写不进去就是漂了。

## Consequences

**变容易了**

- Domain 层真的是纯 PHP：单元测试不需要容器，`Module/Identity/Domain` 拿到 95.3% 行覆盖率而没有一个测试需要数据库。
- deptrac 的 0 violation 是**结构性**的，不是靠自觉：想在 Domain 里 import Doctrine 都过不了 CI。
- `schema:validate` 成了一道真门禁。迁移与映射任何一处漂移（少个索引、DEFAULT 写错、忘了 on-delete）当场红，不用等到 staging。
- 换 ORM 的成本被隔离在 `Infrastructure/Doctrine/` 一个目录里。

**变难了**

- **映射写两遍。** 每个索引、唯一约束与 DEFAULT 都要在迁移与 XML 里各写一次，且必须逐字一致。这是本决策最直接的税，缓解手段只有 `schema:validate` 会立刻抓到不一致。
- **XML 没有 IDE 补全，也不跟着重构走。** 改了实体属性名而忘了改 XML，报错发生在容器编译期而不是编辑器里。`validate_xml_mapping: true` 至少能挡住拼错的属性名。
- **三个实体破了 `final readonly` 的仓库惯例。** 每个类的注释都写明了理由并指向本 ADR，但这确实是一处需要靠文档维持的不一致。
- **`doctrine:schema:create --dump-sql` 生成的 SQL 不能直接用作迁移**：DBAL 会把 `now()` 当字符串字面量输出成 `DEFAULT 'now()'`。它只能当对账参考，迁移得手写。

## 遗留问题：T-109 的跨模块外键

**本 ADR 刻意不解决它。**

§17.1 要求 `cards.owner_id UUID NOT NULL REFERENCES users(id) ON DELETE RESTRICT`。
按上面第 3 条，这条外键必须建成 `Card.owner → User` 的 ORM 关联才能让
`schema:validate` 通过 —— 而 `Wallet.Domain` 的 deptrac 允许列表里没有 `Identity.Domain`。
`card_members`、`friendships`、`share_invitations` 全都有同样的问题。

三条可能的出路，各自的代价：

| 方案 | 代价 |
|---|---|
| `deptrac.yaml` 的 `skip_violations` 开例外 | 那份名单目前是空的，且注释写明「只允许缩短」。开了第一个口子，§4.2 规则 5 就从「结构性成立」退回「靠自觉」 |
| 跨模块引用不建库层外键，只存 uuid 列 | 与 §17.1 的 DDL 直接冲突，且失去引用完整性 —— 而 §3.7 的账号删除与共享卡所有权冲突恰恰需要它 |
| 引入一个 `Shared\Domain` 里的 `UserRef` 值对象作为关联目标 | Doctrine 的关联目标必须是实体，值对象做不到；真要做得把 `users` 拆成一张 Shared 拥有的表，那是改 §4.2 |

哪一条都不是顺手能定的，**留给 T-109 单独决策并写一篇新 ADR**。
在那之前不要把本 ADR 第 3 条当成「所有外键都这么办」——它的适用范围是**模块内部**。

## Alternatives considered

**属性映射 + 把实体挪进 Infrastructure。**
deptrac 会放行（`Identity.Infrastructure` 允许 `Framework.Persistence`），
省掉全部 XML。输在它把 §12.2 的分层职责表整个翻过来了：实体、值对象与不变量是
Domain 的定义性内容，挪进 Infrastructure 之后 Domain 层就只剩下几个 enum，
而 Application 层为了拿到实体必须依赖 Infrastructure —— 那条依赖是所有模块允许列表里都没有的。

**属性映射 + 给 deptrac 开 `Doctrine\ORM\Mapping\*` 的例外。**
最省事。输在那个例外无法收窄到「只允许属性」：deptrac 的图层是按命名空间收的，
放行 `Doctrine\ORM\Mapping\*` 的同时也就放行了 `Doctrine\ORM\Mapping\ClassMetadata`
这类真正的框架 API。而且它会让 §12.2 那句「Domain 不得 import 任何框架类型」
变成一句有星号的话 —— 下一个想加例外的人会引用这个先例。

**放弃 ORM，用 DBAL + 手写数据映射器。**
Domain 纯净度最高，也没有 XML。输在三处：`doctrine:schema:validate` 这道门禁直接消失
（没有元数据可对账），§5.3 的 rewrap 流程（T-404）要自己实现批量遍历与版本追踪，
以及 T-202 的 `/sync` 游标查询要手写全部 JOIN 与水化。§3.11 已经写明「3 个月 / 3–5 人」
与全套 NFR 存在冲突，这里不是该自建的地方。

**不建库层外键，靠应用层保证引用完整性。**
所有跨模块问题一次性消失。输在 §3.7 —— 账号删除与共享卡的所有权冲突是**写明的合规风险**，
而 `ON DELETE CASCADE` / `RESTRICT` 是那套语义唯一不依赖应用代码正确性的落点。
一次漏写的级联就是一批指向不存在用户的孤儿行，而 §8.4 要求删除后不留可关联到人的残余。
