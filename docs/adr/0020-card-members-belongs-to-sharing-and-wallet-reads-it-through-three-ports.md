# 0020. `card_members` 归 Sharing，Wallet 经三个窄端口读写它；placement 端点留在 `Wallet.Http`

- **Status**: Accepted
- **Date**: 2026-09-09
- **Deciders**: 后端负责人
- **规格引用**: §4.2（模块划分与依赖规则）、§5.2（`card_members` / 角色权限矩阵 / 成员可见性）、§6.2、§17.1
- **修订**: [ADR-0019](0019-cross-module-foreign-keys-via-post-generate-schema.md) 的「影响」行
  （T-110 是**三条**跨模块外键，不是两条）——见该 ADR 末尾的「T-110 补记」
- **影响**：T-110（本 ADR 随其落地）、T-201（`ChangeLogSubscriber` 要挂建卡那个事务边界）、
  T-303 / T-304 / T-305（M3 的三张卡都在 `card_members` 上写，且都在 Sharing 侧）

## Context

T-110 要建 `card_members` 并交付 `PUT /v1/cards/{id}/placement`。这张表有一个
在 M1 阶段看不出来、但决定 M3 形状的问题：**它归哪个模块。**

§4.2 的模块图逐字把「**卡成员（owner/viewer）**、共享邀请、退出共享、
好友解除时的级联撤销」划给 **Sharing**，把「卡实体、加密码值、**排序**、搜索」
划给 **Wallet**。而 `sort_order` / `is_pinned` 恰恰**存在 `card_members` 上**
（§5.2：每成员私有 —— 同一张共享卡，Anna 置顶、Bob 不置顶）。
所以那张表同时被两条职责描述碰到，模块图本身没有直接给出答案。

三条既有事实把这个决定夹住了：

1. **§4.2 规则 5**：一张表由且仅由一个模块拥有；跨模块查询必须走接口，
   **不得写跨模块 JOIN**。
2. **M3 的三张卡全在 Sharing**：T-303 的 `ShareRevokerInterface::revokeAllBetween()`
   要 `UPDATE card_members SET left_at`（那是 §7.2 T20「权限残留」的唯一防线）、
   T-304 的邀请接受要插 viewer 行、T-305 的成员管理要按角色裁剪成员列表。
3. **Wallet 每次读一张卡都需要它**：契约的 `Card` 有 `my_role` / `can_edit` /
   `sort_order` / `is_pinned` 四个字段来自这张表，而 `GET /v1/cards` 一页能到
   200 张（§9.1 的 bootstrap 预算 P95 ≤ 700 ms）。

归 Wallet 的话第 3 条免费，但第 2 条要反过来办：M3 的 Sharing 会变成一个空壳，
邀请接受、退出共享、级联撤销全都得经 Wallet 的 Port 去写「自己的」表。
归 Sharing 的话第 2 条自然，但 Wallet 的读路径要跨模块。

ADR-0019 落地时**默认了前者**（它把 T-110 记成「两条外键指向 `users`」——
那个计数只有在 `card_id → cards` 是模块内部外键时才成立）。本 ADR 推翻那个默认。

## Decision

### 一、`card_members` 归 **Sharing**

实体、值对象、仓储、映射全在 `src/Module/Sharing/`。T-110 因此是 Sharing 模块的
第一张表，也是它的第一批代码。

理由是**第 2 条比第 3 条重**：M3 有三张卡在这张表上写，而 Wallet 只是读它的
四个字段。让三个写入者去经一个外部 Port 写别人的表，比让一个读取者经 Port
读别人的表，代价高得多 —— 而且前者会让 §4.2 的模块图变成一句空话
（「Sharing 负责卡成员」而 Sharing 里没有卡成员）。

**代价（要知情）**：`GET /v1/cards` 的列表**仍然按 `owner_id` 过滤**，
没有改成按成员。改它需要跨模块的分页查询（不能 JOIN），那是 T-305 的交付物。
T-110 因此留下一处**两个真相来源**：角色来自 `card_members`，
可见性仍来自 `Card::isOwnedBy()`，两者只因为 M1 没有 viewer 才恰好一致。
这一条写在 `CardQueryService` 的类注释里，并由
`CardPlacementEndpointTest::testAViewerStillCannotGetTheCardUntilT305()`
**断言成「已知的错」** —— T-305 落地时那条用例会红，那时该改的是它。

### 二、Wallet 经**三个**窄端口，不是一个胖端口

```
Sharing\Application\Port\CardOwnershipRegistrarInterface   建卡时写 owner 行
Sharing\Application\Port\CardMembershipReaderInterface     批量读角色与 placement
Sharing\Application\Port\CardPlacementWriterInterface      写调用者自己的 placement
```

三个接口由**一个类**实现（`Sharing\Application\Membership\CardMembershipService`）——
接口是契约，类是 Sharing 内部的事。拆三个类只会得到三份「注入同一个仓储」的样板。

**接口不能合并**，三条理由：

1. **前置条件互相矛盾，而前置条件就是契约本身。** registrar **必须**在
   `TransactionRunnerInterface::run()` 内调用（实现用 `isInTransaction()` 断言并抛
   `\LogicException`）；reader 绝不该在；writer 两者都不要求。
   合成一个类注释就要写三段互相打架的「你必须这样调我」。
2. **reader 要注进 `CardViewAssembler`，而那个类不该拿到任何写能力。**
   它存在的全部理由是「组装 `CardView` 只有一条路」。给它一个身上挂着
   `registerOwner()` 的接口，等于把一个它永远不该用的能力交到手上 ——
   而本仓库一贯的做法是让越界**编译不过**，不是靠注释。
3. **端口是可数的耦合度量。** T-303 还要往这里加 `ShareRevokerInterface`。
   三个具名端口让 Wallet→Sharing 的耦合数得清；一个胖端口会把它藏起来。

口径与 Notification 一致（`MailSenderInterface` / `MailTransportInterface` /
`MailVolumeCounterInterface` 三个窄端口，一个模块）。

**实现放 `Sharing.Application` 而不是 `Infrastructure`**：ADR-0018 给
`UserOnboardingStatus` 选 Infrastructure 的理由逐字是「它不是编排，是一次持久层
查询的适配器，**没有业务分支**」。本类反过来 —— `registerOwner()` 要构造实体并
施加「一卡一 owner」，`updatePlacement()` 要先判成员资格再决定是写还是抛。

**DTO 里的 `role` 是 string，`canEdit` 是算好的 bool。**
deptrac 里 `Sharing.Dto: [Shared.Domain]` —— 它**看不见** `Sharing.Domain`，
所以 `CardRole` 枚举放不进 DTO，而在 DTO 里再定义一个同名枚举就是两份要同步的
取值域。选择是枚举只有一份（在 Domain），DTO 带它算好的结果。
`canEdit` 在 Sharing 侧算完才出去 —— §5.2 的权限矩阵是 Sharing 的业务，
放在这里，M3 加角色时「什么角色能编辑」只有一处要改，
而 `CardBody` 那句「本类不做任何判断」继续为真。

### 三、`PUT /v1/cards/{id}/placement` 落在 **`Wallet.Http`**（`CardController` 的第六条路由）

直觉是「写的是 Sharing 的表，端点也该归 Sharing」。那个直觉由端口满足即可 ——
Wallet 从头到尾不知道 `card_members` 存在。而真把控制器放 Sharing，
要付**两次复制**的代价，两次都是本仓库明文在拦的：

- **克隆一个装明文码值的 DTO。** `CardView` 在 `Wallet.Application`，
  `Sharing.Http` 的允许列表只有 `+_Ports`，看不见它。于是要在 `Wallet.Dto` 里
  再造一个 —— 而 `CardView` 的第二条不变量（「让它只能由 `CardViewAssembler`
  构造，就保证了『谁解的密』只有一个答案」）当场作废。
- **克隆契约 `Card` 的序列化。** `CardBody` 在 `Wallet.Http`，同样看不见。
  而那个类的注释写着它存在的全部理由：「组装代码有第二份的话，加字段时必然
  漏一处 —— 而漏掉的那个端点的契约测试照样是绿的」。
  **T-110 正是给 `Card` 加两个字段的那次提交。**

模块级还有一个真环（Wallet 要 Sharing 的角色，Sharing 要 Wallet 的渲染），
而 deptrac 两条边都放行（都走 `+_Ports`）且**不做环检测** —— CI 抓不到它。

放 `Wallet.Http` 则只有两条边、单向：`Wallet.Http → Wallet.Application`、
`Wallet.Application → Sharing.Port`。

### 四、建卡与 owner 行在**同一个事务**里；幂等重放**不补**成员行

```php
$this->transactions->run(function () use ($card, $auth, $now): void {
    $this->cards->save($card);                                    // 先建卡（外键顺序）
    $this->members->registerOwner($card->id(), $auth->userId, $now);
});
```

事务里**只有那两次写**：`enforceLimits()`（一次 `COUNT(*)`）、`secrets->barcode()`
与 `view()`（各一次 Vault 往返）都留在外面 —— 把 PG 事务开着跨越一次 Vault 往返，
等于把 Vault 的每一次延迟毛刺变成持有中的行锁与连接。

`$joinedAt` 由 Wallet 传进来（与 `Card::create()` 用同一个 `now()`），
Sharing 侧不读时钟：差几微秒会让 M2 的 change_log 把同一个逻辑事件排成两件事。

**幂等重放那一支（`id` 已存在且属调用者）什么都不补**，尽管它可能碰到一张
没有成员行的卡。三条理由：

1. 它一旦写就不再是重放 —— 那条不变量存在的理由（迟到的离线 outbox 条目
   不得静默改动服务端状态）对成员行同样成立。
2. 「卡有、owner 行没有」这个状态**不该存在**，消除它的地方是迁移的回填。
   在这里补等于把破损盖掉，而且盖在生产里最不会被走到的那条分支上。
3. 那一支会返回**软删**的卡。给它补一行 `left_at IS NULL` 的 owner 记录，
   等于恢复了成员关系却没恢复卡本身 —— 毒化 M3 的 audience 快照。

破损因此在**读**路径上炸：`CardViewAssembler::assertComplete()` 抛
`\LogicException`（→ 500 + 日志），而不是回退到某个默认角色。
三条备选都更糟，写在那个方法的注释里。

### 五、迁移的回填**包含软删的卡**

```sql
INSERT INTO card_members (card_id, user_id, role, sort_order, is_pinned, added_by, joined_at, left_at)
SELECT id, owner_id, 'owner', 0, false, NULL, created_at, NULL FROM cards;
```

不带 `WHERE deleted_at IS NULL`。目标是让 `cards → card_members` 是一个**全函数**，
于是「卡有、owner 行没有」从一种状态降级成一个 bug（第四条依赖这一点）。

相应地，**T-110 不给软删的卡写 `left_at`**：§5.2 要求卡墓碑的 audience 取
**删除前**的成员快照（「若先删 `card_members` 再算 audience，viewer 将永远收不到
墓碑」）。这一条写在迁移文件头与 `DeleteCardService` 的类注释里，
免得 T-201 有人来「修」它。

## Consequences

**变好了**

- **§4.2 的模块图对 Sharing 成立。** M3 的三张卡都在自己的模块里写自己的表，
  不需要一个反向的 Wallet 端口。
- **placement 端点对 viewer 天然正确。** 鉴权判据是「有没有活跃成员行」而不是
  `isOwnedBy()`，所以 T-304 插进第一行 viewer 的那天它不用改一行。
- **`CardBody` / `CardViewAssembler` 的单一入口都保住了**，`Card` 加两个字段
  五个端点一行没改 —— 那两个类的注释第一次被兑现。
- **零 deptrac 配置改动**，`skip_violations` 保持为空，0 violation。

**变难了（必须知道）**

- **Wallet 每次读卡多一次跨模块查询。** 它是**批量**的（与卡数无关），
  由 `CardListPerformanceTest` 的 700 ms 上限同时护着解密与成员两条批量路径。
  但它确实是一次新的 DB 往返，且 `CardViewAssembler` 现在同时握着两份按
  cardId 键控的批量结果 —— 后者用 `CardMembershipMap` 这个只能用 `Uuid` 索引的
  类型挡住错位（`BatchDecryptorInterface` 的注释警告过同一类 bug）。
- **`GET` 的可见性与角色暂时是两个真相来源**（见决定一的「代价」）。
  这是本 ADR 最大的一笔欠账，收口在 T-305。
- **仓库多了一种依赖方向**：Wallet 第一次同步调用另一个模块。
  §4.2 规则 2 允许，但在此之前没有先例。
- **`registerOwner()` 的事务前置条件靠运行时断言，不靠类型。** 非事务地调用它
  没有任何症状，直到某天一次部分失败留下一张孤儿卡。护栏本身也有单测
  （`testRegisteringAnOwnerOutsideATransactionThrows`），否则删掉那个 `if`
  同样什么都不会红。

## Alternatives considered

**`card_members` 归 Wallet。** ADR-0019 隐含的默认。零跨模块读、
`card_id` 可以写成模块内的 `<many-to-one>`、`GET /v1/cards` 将来可以直接 JOIN
出共享卡。输在 M3：邀请接受、退出共享、好友解除的级联撤销全都要反过来经
Wallet 的 Port 写「别人的」表，而 §4.2 把这三件事逐字划给了 Sharing ——
模块图会变成一句空话。**如果将来 T-305 的跨模块分页查询代价太高，
这是应该回退到的形态**，代价是一次表所有权迁移（不动 DDL，只动代码归属）。

**一个胖端口 `CardMembershipInterface`。** 少两个文件。输在三个方法的前置条件
互相矛盾，以及 `CardViewAssembler` 会拿到写能力（见决定二）。

**placement 控制器放 `Sharing.Http`，经一个新的 `Wallet.Port` 渲染卡。**
deptrac 两条边都放行。输在两次复制（`CardView` 与 `CardBody`）与一个 CI 抓不到
的模块环，详见决定三。

**`GET /v1/cards` 这次就改成按成员判定。** 会让「两个真相来源」当场收口。
输在它需要跨模块的分页查询（§4.2 规则 5 不许 JOIN），而那是 T-305 的交付物 ——
在 M1 做等于提前把 T-305 的一半搬进一张 1 人日的卡，且没有任何 viewer 能验证它。
