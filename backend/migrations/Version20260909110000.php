<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * T-110：`card_members` —— Sharing 模块的第一张表（§5.2 / §17.1）。
 *
 * ============================================================================
 * 迁移元信息（CONTRIBUTING §5 / §13.5 要求每个迁移文件头都写这四项）
 * ============================================================================
 * **影响的表**：新建 `card_members`；在 `cards` 与 `users` 上**各加被引用的外键**
 *               （`cards` 一条、`users` 两条）。两张表的结构都不改。
 *               另外**读一遍 `cards` 全表**做回填（见下）。
 *
 * **预估执行时长**：建表 + 三条外键 + 两条索引 < 50 ms。回填是
 *                   `INSERT … SELECT FROM cards`，与 `cards` 的行数成正比；
 *                   §9.3 的容量假设是 75 万行 → 秒级（单次顺序扫 + 批量插入）。
 *                   M1 上线前 `cards` 基本是空的，实测在本机 compose 上 < 10 ms。
 *
 * **是否锁表**：`CREATE TABLE` 只锁新表（没人能看见它）。
 *               ⚠️ 这是本仓库**第一个同时锁两张既有表**的迁移：
 *               `REFERENCES cards(id)` 与 `REFERENCES users(id)` 会分别在
 *               `cards` 与 `users` 上取 `SHARE ROW EXCLUSIVE`，**阻塞对这两张表的写**
 *               （`users` 在登录路径上，`cards` 在钱包写路径上），不阻塞读。
 *               持有时间是毫秒级 —— 建外键时 PG 只校验**引用侧**的既有行，
 *               而 `card_members` 此刻是空的，所以不会去扫那两张表。
 *               回填的 `INSERT … SELECT` 只在 `cards` 上取 `ACCESS SHARE`（读锁），
 *               与并发的读写都不冲突。
 *
 * **如何回滚**：`doctrine:migrations:migrate prev`，或直接跑 {@see down()}。
 *               ⚠️ 与 T-109 不同，**这一条是可逆的**：`card_members` 的每一行
 *               在 M1 阶段都能从 `cards.owner_id` 重新推导出来（每张卡恰好一行
 *               owner，`sort_order` / `is_pinned` 是默认值）。
 *               所以别照抄 {@see Version20260908182500} 那段「第一个不可逆回滚」的
 *               措辞。M3 之后就不成立了 —— 那时表里会有推导不出来的 viewer 行，
 *               动这张表的那张卡要重新写这一段。
 *
 * ============================================================================
 * 三条外键**全都是跨模块的**，一条都不在 ORM 映射里
 * ============================================================================
 * §4.2 把「卡成员（owner/viewer）」划给 **Sharing** 模块，而 `cards` 属 Wallet、
 * `users` 属 Identity —— 于是这张表的三条外键指向两个别的模块：
 *
 *   fk_card_members_card_id  → cards(id)  ON DELETE CASCADE   （Sharing → Wallet）
 *   fk_card_members_user_id  → users(id)  ON DELETE CASCADE   （Sharing → Identity）
 *   fk_card_members_added_by → users(id)  ON DELETE SET NULL  （Sharing → Identity）
 *
 * 三条都由 {@see \App\Shared\Infrastructure\Doctrine\CrossModuleForeignKeys} 在
 * `postGenerateSchema` 上补进 ORM schema，实体只持有普通 uuid 列（ADR-0019）。
 *
 * ⚠️ `fk_card_members_card_id` 是本仓库**第一条指向另一个模块业务表**
 * （而不是 `users`）的跨模块外键。ADR-0019 的「影响」行原先写的是
 * 「T-110 的**两条**外键指向 `users`」—— 那是按 `card_members` 归 Wallet 算的
 * （那样 `card_id` 会是模块内的 `<many-to-one>`）。归属定在 Sharing 之后是三条，
 * 该 ADR 的计数已随本卡修订。
 *
 * ============================================================================
 * ⚠️ 部分索引的谓词：抄 PG 规范化之后的样子
 * ============================================================================
 * `uq_card_single_owner` 的谓词**是不变量本身**（「每张卡有且仅有一行
 * `role='owner'` 且 `left_at IS NULL`」），去不掉。ADR-0019 末尾专门为这张卡
 * 留了结论：部分索引在 DBAL 4 上能用，但 `CardMember.orm.xml` 里声明的谓词要与
 * PG 存回来的**逐字**相同（`Index::samePartialIndex()` 做 `===` 字符串比较）。
 *
 * 本机 compose 实测（2026-09-09，PG 16）：
 *
 *   写进去的                              pg_indexes.indexdef 里读回来的
 *   role = 'owner' AND left_at IS NULL →  ((role = 'owner'::text) AND (left_at IS NULL))
 *   left_at IS NULL                    →  (left_at IS NULL)
 *
 * 注意 `role` 是 **TEXT** 不是 VARCHAR，所以左边是 `role` 而不是 `(role)::text`。
 * `SharingSchemaTest::testTheOwnerIndexIsPartialOnLiveOwnerRows()` 直接对着
 * `pg_indexes.indexdef` 断言了一次，好让失败信息指向真正的原因。
 *
 * ============================================================================
 * `role` 有 CHECK，而 `cards.color` / `barcode_format` 没有
 * ============================================================================
 * 不是不一致：那两个是**开放词汇**（§13.6 允许新增枚举值，写进 CHECK 之后加一个
 * 新条码格式就成了一次要停机对齐的迁移）。`role` 反过来 —— v1.1 把取值域
 * **收缩**成 `owner|viewer` 并删掉了 `editor`，C7 写明「只有一种可授予的角色」。
 * 它是封闭的，§17.1 也原样带着这条 CHECK。
 *
 * ============================================================================
 * 回填：为什么必须有，为什么**不带** `WHERE deleted_at IS NULL`
 * ============================================================================
 * T-109 期间建的每一张卡都还没有成员行。不回填的话它们在本卡上线后全变成
 * 「没有成员行的孤儿卡」—— `GET /v1/cards/{id}` 拿不到角色（assembler 直接抛）、
 * placement 端点 403。
 *
 * 软删的卡**也要回填**：`DELETE /v1/cards/{id}` 只写 `deleted_at`，那张卡的 id
 * 仍然被占用，而建卡的幂等重放会把它原样返回（见 `CreateCardService` 的类注释），
 * 那条路径同样要能查出角色。目标是让 `cards` → `card_members` 是一个**全函数**，
 * 于是「卡有、owner 行没有」从一种状态降级成一个 bug。
 *
 * ⚠️ 回填**不加** `ON CONFLICT DO NOTHING`：`down()` 是 `DROP TABLE`，
 * 所以 `migration:check` 的 down/up 往返里重新 `up()` 时表是空的，不会冲突。
 * 加了它只会把真正的问题（比如有人手工插过行）盖掉。
 */
final class Version20260909110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'T-110: card_members table (Sharing), three cross-module FKs, backfill of owner rows';
    }

    public function up(Schema $schema): void
    {
        // DDL 逐字取自 §17.1，只把三条外键提出去单独 ALTER（见下）。
        $this->addSql(<<<'SQL'
            CREATE TABLE card_members (
              card_id    UUID NOT NULL,
              user_id    UUID NOT NULL,
              role       TEXT NOT NULL CHECK (role IN ('owner','viewer')),
              sort_order INTEGER NOT NULL DEFAULT 0,
              is_pinned  BOOLEAN NOT NULL DEFAULT false,
              added_by   UUID,
              joined_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
              left_at    TIMESTAMPTZ,
              PRIMARY KEY (card_id, user_id)
            )
            SQL);

        // ⚠️ 三条外键各一条 ALTER，而不是写在 CREATE TABLE 的列定义里 ——
        // 约束名必须与 CrossModuleForeignKeys 里那三个键**逐字相同**，
        // 而内联写法只能拿到 PG 自动生成的 `card_members_card_id_fkey`。
        //
        // on-delete 也必须逐字一致：Comparator::diffForeignKey() 不比约束名，
        // 但**比 onDelete** —— 写错就是一条永久 diff。
        //
        // ⚠️ 这里的 CASCADE 与 `fk_cards_owner_id` 的 RESTRICT 看起来不一致，
        // 其实是 §3.7 删号编排要的顺序：删一个用户会被他**自己的卡**挡住
        // （RESTRICT，那是要人来处理的冲突），但他作为 viewer 的成员行可以
        // 随他一起消失（CASCADE，那只是一条授权记录）。§17.4 的删号脚本
        // 因此是「先删卡 → 成员行自动没 → 再删用户」。
        $this->addSql(<<<'SQL'
            ALTER TABLE card_members
              ADD CONSTRAINT fk_card_members_card_id
              FOREIGN KEY (card_id) REFERENCES cards (id) ON DELETE CASCADE
            SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE card_members
              ADD CONSTRAINT fk_card_members_user_id
              FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
            SQL);

        // SET NULL 而不是 CASCADE：`added_by` 只是「谁把他加进来的」这条审计线索，
        // 邀请人删号不该把**被邀请人**的成员关系一起删掉 —— 那张卡的共享还在。
        $this->addSql(<<<'SQL'
            ALTER TABLE card_members
              ADD CONSTRAINT fk_card_members_added_by
              FOREIGN KEY (added_by) REFERENCES users (id) ON DELETE SET NULL
            SQL);

        // §17.1 原样的部分唯一索引 —— 「一卡一 owner」这条不变量的**唯一**强制点。
        // 谓词与 CardMember.orm.xml 里 <option name="where"> 的内容**语义相同**
        // （那边是 PG 规范化之后的样子，带类型标注与括号，见文件头）。
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uq_card_single_owner
              ON card_members (card_id) WHERE role = 'owner' AND left_at IS NULL
            SQL);

        // 复合主键是 (card_id, user_id)，前导列是 card_id —— 它**服务不了**
        // 「某个用户的全部成员关系」这种 user_id 打头的谓词，而那正是
        // CardMembershipReaderInterface 每次读卡都要跑的查询。
        // 部分索引：left_at 非空的行是墓碑，永远不出现在那条查询里。
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_card_members_user
              ON card_members (user_id) WHERE left_at IS NULL
            SQL);

        // 回填。`joined_at` 取 `cards.created_at` 而不是 now()：这一行代表的是
        // 「这个人从建卡那一刻起就是 owner」，用迁移的执行时间会让 M2 的
        // change_log 排序看到一批时间戳全都挤在同一毫秒。
        // sort_order / is_pinned 用列默认值（0 / false），added_by 为 NULL
        // （owner 不是被谁加进来的）。
        $this->addSql(<<<'SQL'
            INSERT INTO card_members (card_id, user_id, role, sort_order, is_pinned, added_by, joined_at, left_at)
            SELECT id, owner_id, 'owner', 0, false, NULL, created_at, NULL
              FROM cards
            SQL);
    }

    public function down(Schema $schema): void
    {
        // 索引与三条外键随表一起消失，不需要单独 DROP。
        // 数据可从 cards.owner_id 重新推导（见文件头「如何回滚」）。
        $this->addSql('DROP TABLE card_members');
    }
}
