# 0019. 跨模块外键建在库里、经 `postGenerateSchema` 补进 ORM schema，实体只持有 uuid 列

- **Status**: Accepted
- **Date**: 2026-09-08
- **Deciders**: 后端负责人
- **规格引用**: §3.7（账号删除）、§4.2（模块依赖规则）、§5.2、§12.2、§13.3、§17.1
- **修订**: [ADR-0011](0011-doctrine-orm-xml-mapping-and-module-owned-foreign-keys.md) 的「遗留问题：T-109 的跨模块外键」——本 ADR 结掉它；
  ADR-0011 决定 3 的适用范围**收窄为模块内部**，这一点它自己已经写明，这里只是把另一半补上
- **影响**：T-109（本 ADR 随其落地）、T-110（`card_members` 的**三条**外键，见下方补记）、
  M3 的 `friendships` / `share_invitations`（同一个形状）、
  以及**每一个**将来引用他模块表的迁移
- **修订记录**：2026-09-09（T-110）——「影响」行原写作「T-110 的**两条**外键指向 `users`」，
  实测是**三条**。见末尾「T-110 补记」。

## Context

§17.1 要求 `cards.owner_id UUID NOT NULL REFERENCES users(id) ON DELETE RESTRICT`。
三条既有约束把这条外键夹住了，任意两条都好办，三条一起就没有现成的出路：

1. **§4.2 规则 1/2**：跨模块只能看见对方的 `Application\Port\*` 与 `Dto`。
   `deptrac.yaml` 里 `Wallet.Domain: [Shared.Domain]` —— 没有 `Identity.Domain`。
2. **ADR-0011 决定 3**：库里的每条外键都必须建成 ORM 的 `<many-to-one>`，
   否则 `doctrine:schema:validate` 会报「多出一条待删外键」，而那条命令是
   `composer migration:check` 的第 ③ 步、也是本仓库唯一能发现「映射与迁移漂了」的门禁。
3. **§3.7**：账号删除流程要靠 `ON DELETE RESTRICT` 挡住「还持有卡的用户被删掉」。
   那是一个要人来处理的冲突，不是可以静默级联的事。

ADR-0011 刻意没有替 T-109 决定，列了三条出路并写明「哪一条都不是顺手能定的」。
`card_members`、`friendships`、`share_invitations` 全是同一个形状，所以这不是一次性的例外，
而是一条要用很多次的规则。

## Decision

**跨模块外键建在库里，但不进 ORM 元数据；ORM 侧由一个 `postGenerateSchema` 监听器补上。**

四条具体规则：

### 1. 引用侧实体持有一个普通的 uuid 列

`Card` 有 `private Uuid $ownerId`，映射成
`<field name="ownerId" type="uuid" column="owner_id"/>`。**没有** `<many-to-one>`，
也就没有 `$card->getOwner()`。

### 2. 外键由 `Shared\Infrastructure\Doctrine\CrossModuleForeignKeys` 统一登记

```php
#[AsDoctrineListener(event: ToolEvents::postGenerateSchema)]
final readonly class CrossModuleForeignKeys
{
    private const FOREIGN_KEYS = [
        'fk_cards_owner_id' => ['cards', 'owner_id', 'users', 'id', 'RESTRICT'],
    ];
    // …往 $schema->getTable($table) 上 addForeignKeyConstraint(…)
}
```

这样能成立，是因为 `SchemaValidator::schemaInSyncWithMetadata()` →
`getUpdateSchemaList()` → `SchemaTool::getSchemaFromMetadata()`，而后者在生成完毕后
派发 `ToolEvents::postGenerateSchema` 并**回读监听器改过的 schema**
（`SchemaTool.php:504`，那段代码自己的注释是 "Always retrieve the schema
(listener may have mutated it)"）。`doctrine:migrations:diff` 走同一条路径，
所以生成的 diff 也不会想删掉这些外键。

Symfony 自己的 `MessengerTransportDoctrineSchemaListener` 用的就是这个事件 ——
这不是一个偏门用法。

### 3. 引用侧的 `.orm.xml` 必须显式声明一条同名同列的索引

迁移建了 `idx_cards_owner`，ORM 侧不声明的话它就是一条「库里有、映射里没有」的索引，
`schema:update` 的 dump-sql 会吐 `DROP INDEX idx_cards_owner`。

### 4. `on-delete` 必须与迁移逐字一致

`Comparator::diffForeignKey()` **不比约束名**，但**比 `onDelete`**。写错就是一条永久 diff。

## 为什么不是 ADR-0011 列的那三条

| 方案 | 输在哪 |
|---|---|
| `deptrac.yaml` 的 `skip_violations` 开例外 | 那份名单目前是空的，注释写明「只允许缩短」。开了第一个口子，§4.2 规则 5 就从「结构性成立」退回「靠自觉」。**而且它换来的是一个负资产**：`$card->getOwner()` 一旦存在，`$card->getOwner()->getUsername()` 就写得出来 —— 那正是「禁止跨模块 JOIN」要拦的东西 |
| 不建库层外键，只存 uuid 列 | 与 §17.1 的 DDL 直接冲突，且丢掉引用完整性 —— 而 §3.7 的账号删除恰恰需要 `RESTRICT` |
| `Shared\Domain` 里的 `UserRef` 值对象当关联目标 | Doctrine 的关联目标必须是实体，值对象做不到 |

本方案同时避开了三条的代价：库层外键在、deptrac 零 violation、`skip_violations` 保持为空。

## Consequences

**变好了**

- **§4.2 规则 5 从「靠自觉」升级成「结构性成立」。** 拿不到 `$card->getOwner()`，
  就写不出跨模块 JOIN。要 owner 的用户名只能走 Identity 的 Port ——
  这比 skip_violations 方案**更**硬，而不是打了个折。
- **`skip_violations` 保持为空**，那句「只允许缩短」还是真的。
- **一处登记、到处适用。** T-110 的三条、M3 的四条都只是往 `FOREIGN_KEYS` 里加行，
  不需要再决定一次。
- **零 deptrac 配置改动。** 监听器只用表名/列名字符串，deptrac 按 FQCN 收依赖，
  字符串不是依赖；用到的 `Doctrine\*` 落在 `Framework.Persistence`，
  而 `Shared.Infrastructure` 本来就允许它。

**变难了**

- **这些外键没有编译期检查。** `owner_id` 打成 `owner`，PHP 层面一切正常，
  只有 `schema:validate` 会红 —— 而它的失败信息是一句「不同步」，不指向原因。
  缓解：`CrossModuleForeignKeysTest` 直接断言每条外键的形状（列、目标表、`onDelete`），
  失败信息是人话；`WalletSchemaTest` 另外对着 `pg_constraint` 断言库里那条真的在。
- **外键的定义写在三个地方**（迁移、监听器、引用侧的索引声明），
  且必须逐字一致。这是 ADR-0011「映射写两遍」那笔税的延续，不是新税种。
- **ORM 不知道这条关系**，所以没有级联、没有懒加载、`cascade={"remove"}` 之类
  一概不可用。跨模块本来也不该有那些东西（§4.2 规则 5），但踩到时的报错
  会是「字段不存在」而不是「你不该这么做」。

## 附带结论：部分索引在 DBAL 4 上是能用的（推翻 T-106 的记录）

落地时顺手重测了一件事，结论与既有记录相反，写在这里免得 T-110 再撞一次。

`Version20260907140000`（T-106）的文件头记着一条实测结论：
「DBAL **读不回**索引定义里的 WHERE 子句，加了它 `schema:validate` 会永远报不同步」，
因此那条唯一索引放弃了 `WHERE magic_token_hash IS NOT NULL`。

**在 DBAL 4.4.4 / PG 16 上读得回**（本机 compose，2026-09-08 实测）：
`PostgreSQLSchemaManager::selectIndexColumns()` 用 `pg_get_expr(indpred, indrelid)`
把谓词读进索引的 `where` 选项。之前撞墙的真正原因是
`Index::samePartialIndex()` 拿两侧的 `where` 做 **`===` 字符串比较**，
而 PG 存回来的是**规范化**结果，带一对外层括号：

| `.orm.xml` 里写的 | `schema:validate` |
|---|---|
| `deleted_at IS NULL` | ✗ 不同步，dump-sql 反复吐 DROP + CREATE |
| `(deleted_at IS NULL)` | ✓ 同步 |

所以 §17.1 的 `idx_cards_owner ON cards(owner_id) WHERE deleted_at IS NULL`
**原样落地了**，没有偏离规格。

⚠️ **T-110 需要这条结论**：`uq_card_single_owner`
（`… WHERE role = 'owner' AND left_at IS NULL`）的谓词**是不变量本身**、去不掉，
按 T-106 的旧结论它根本落不了地。照 `Card.orm.xml` 的形状写即可，
注意把 PG 规范化后的样子抄准（`pg_indexes.indexdef` 里看到的那串）。

代价：谓词字符串依赖 PG 的格式化规则。它变了 `migration:check` 就红 ——
那是可接受的失败模式（红在 CI，不是红在生产），而且
`WalletSchemaTest::testTheOwnerIndexIsPartialOnLiveRows()` 直接对着
`pg_indexes.indexdef` 断言了一次，好让失败信息指向真正的原因。

**本 ADR 不去改 T-106 的那条索引。** 它现在是普通唯一索引，功能正确，
只是索引里多了一堆 NULL 行；改它需要一次新迁移，而收益是几十 KB 的索引空间。
留给真正需要动那张表的那张卡。

## Alternatives considered

**在 `Kernel` 或一个 compiler pass 里改 ORM 元数据。**
技术上做得到（`loadClassMetadata` 事件里给 `ClassMetadata` 塞一个 association mapping），
输在它会把关联**真的**造出来 —— `$card->getOwner()` 又能用了，
于是本 ADR 最大的收益（规则 5 结构性成立）当场消失。而且元数据是 Doctrine 的内部结构，
在这一层伪造一个关联，报错会出现在 UnitOfWork 深处。

**把 `users` 拆成一张 `Shared` 拥有的表。**
ADR-0011 已经点过：那是改 §4.2 的模块划分。而且它只解决「指向 users」这一类，
`card_members → cards` 这种「Sharing 指向 Wallet」的外键还是同样的问题。

**每个模块自己写一个监听器。**
`Wallet.Infrastructure` 允许 `Framework.Persistence`，所以技术上可行，
而且看起来更符合「表由模块拥有」。输在跨模块外键的两端**属于两个模块** ——
放在哪一侧都是任意的，而放错的后果是两个模块各写一半、谁都不知道另一半在哪。
收在 `Shared` 里一份清单，读的人一眼能看到全仓库的跨模块引用有几条。
这也正是这份清单的第二个用途：它是模块耦合的**度量**，长了就该问为什么。

## T-110 补记（2026-09-09）：那是**三条**，不是两条

本 ADR 落地时把 T-110 记成「`card_members` 的**两条**外键指向 `users`」。
数漏了一条，原因是当时默认 `card_members` 会落在 **Wallet**（那样
`card_id → cards` 就是模块内部的外键，按 ADR-0011 决定 3 写成 `<many-to-one>`）。

T-110 落地时按 §4.2 的模块图把这张表定在了 **Sharing**（「卡成员（owner/viewer）、
共享邀请、退出共享、好友解除时的级联撤销」逐字划给 Sharing，而 M3 的 T-303 /
T-304 / T-305 全都在它上面写）。于是三条外键**全都跨模块**：

| 约束 | 方向 | `on delete` | 为什么 |
|---|---|---|---|
| `fk_card_members_card_id → cards(id)` | Sharing → **Wallet** | `CASCADE` | 卡硬删（90 天后的清理）时成员行没有独立存在的意义 |
| `fk_card_members_user_id → users(id)` | Sharing → Identity | `CASCADE` | 一条授权记录，随人消失是对的 |
| `fk_card_members_added_by → users(id)` | Sharing → Identity | `SET NULL` | 审计线索。邀请人删号不该连累**被邀请人**的成员关系 |

⚠️ 第一条是本清单里**第一条指向另一个模块业务表**（而不是 `users`）的外键。
本 ADR 的「Alternatives considered」已经点过这个形状
（「`card_members → cards` 这种「Sharing 指向 Wallet」的外键还是同样的问题」），
**决策本身覆盖得到，只有计数写岔了** —— 所以这里是修订计数，不是推翻决策。

⚠️ **`ON DELETE` 的不对称是有意的，别「顺手改成一致」**：
`fk_cards_owner_id` 是 `RESTRICT`，而 `fk_card_members_user_id` 是 `CASCADE`。
读作一句话：**删一个用户会被他自己的卡挡住（那是要人处理的冲突），
但他作为 viewer 的成员行可以随他一起消失。** §17.4 的删号脚本因此是
「先删卡 → 成员行自动没 → 再删用户」。这一条也抄在
`CrossModuleForeignKeys::FOREIGN_KEYS` 的注释里。

**部分索引那条结论成立。** 本 ADR 末尾为 `uq_card_single_owner` 留的判断是对的：
`WHERE role = 'owner' AND left_at IS NULL` 原样落地，`schema:validate` 第一次就同步。
PG 规范化之后的谓词是

```
((role = 'owner'::text) AND (left_at IS NULL))
```

⚠️ 注意是 `role` 而不是 `(role)::text` —— 那一列是 **TEXT** 不是 VARCHAR，
两者的规范化形式不同。抄错的症状是 dump-sql 反复吐 DROP + CREATE INDEX。
真值由 `SharingSchemaTest::testTheOwnerIndexIsUniquePartialOnLiveOwnerRows()`
对着 `pg_indexes.indexdef` 钉住。
