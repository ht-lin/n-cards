<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * T-109：`cards` —— Wallet 模块的第一张表（§5.2 / §17.1）。
 *
 * ============================================================================
 * 迁移元信息（CONTRIBUTING §5 / §13.5 要求每个迁移文件头都写这四项）
 * ============================================================================
 * **影响的表**：新建 `cards`；在 `users` 上**加一条被引用的外键**
 *               （`fk_cards_owner_id … REFERENCES users(id)`）。不改 `users` 的结构。
 *
 * **预估执行时长**：< 50 ms。空表建表 + 建一条索引 + 建一条外键，与现有数据量无关
 *                   （`users` 侧只需要验证被引用列上有唯一索引，主键天然满足）。
 *
 * **是否锁表**：`CREATE TABLE` 只锁新表（没人能看见它）。
 *               `REFERENCES users(id)` 会在 `users` 上取 `SHARE ROW EXCLUSIVE`，
 *               它**阻塞对 `users` 的写**（含登录路径的 upsert），不阻塞读。
 *               持有时间是毫秒级 —— 建外键时 PG 只校验**引用侧**的既有行，
 *               而 `cards` 此刻是空的，所以不会去扫 `users`。
 *
 * **如何回滚**：`doctrine:migrations:migrate prev`，或直接跑 {@see down()}。
 *               `DROP TABLE cards` **会丢掉全部卡片数据**，而卡片是用户手动录入、
 *               服务端无法再生的内容 —— 这是本仓库到目前为止**第一个不可逆的
 *               回滚**。上线后要回滚这一条，先照 §10 做一次 `cards` 的导出。
 *               （ADR-0010：自动回滚只回镜像 tag，迁移不自动回滚。这条正是那条
 *               决策针对的情形。）
 *
 * ============================================================================
 * DDL 逐字取自 §17.1，但 `owner_id` 的外键**不在 ORM 映射里**
 * ============================================================================
 * 它由 {@see \App\Shared\Infrastructure\Doctrine\CrossModuleForeignKeys} 在
 * `postGenerateSchema` 上补进 ORM schema，所以 `schema:validate` 仍然对得上。
 * 为什么不写成 `Card.orm.xml` 里的 `<many-to-one>`（那是 ADR-0011 第 3 条的做法）：
 * 那要求 `Wallet.Domain` import `Identity\Domain\Entity\User`，而 deptrac 里
 * `Wallet.Domain: [Shared.Domain]`。完整论证见 **ADR-0019**。
 *
 * ============================================================================
 * ⚠️ 部分索引这次是**能用**的 —— T-106 的结论在 DBAL 4 上不成立
 * ============================================================================
 * {@see Version20260907140000} 的文件头记着一条实测结论：「DBAL 读不回索引定义里的
 * WHERE 子句，加了它 schema:validate 会永远报不同步」，所以那条唯一索引放弃了
 * `WHERE magic_token_hash IS NOT NULL`。
 *
 * 本卡重新测了一次（本机 compose，2026-09-08，DBAL 4.4.4 / PG 16），**读得回**：
 * `PostgreSQLSchemaManager::selectIndexColumns()` 用
 * `pg_get_expr(indpred, indrelid)` 把谓词读进索引的 `where` 选项。
 * 之前撞墙的原因是 `Index::samePartialIndex()` 拿两侧的 `where` 做 `===`
 * 字符串比较，而 PG 存回来的是**规范化**结果 —— 带一对外层括号：
 *
 *   XML 里写 `deleted_at IS NULL`   → 不同步，dump-sql 反复吐 DROP + CREATE
 *   XML 里写 `(deleted_at IS NULL)` → 同步 ✅
 *
 * 所以 §17.1 的部分索引原样落地，没有偏离。
 *
 * ⚠️ **T-110 需要这条结论**：`uq_card_single_owner`
 * （`… WHERE role = 'owner' AND left_at IS NULL`）的谓词**是不变量本身**、
 * 去不掉，按 T-106 的旧结论它根本落不了地。照 `Card.orm.xml` 的形状写即可，
 * 注意把 PG 规范化后的样子抄准（`psql \d+` 或 `pg_indexes.indexdef` 里看到的那串）。
 *
 * ⚠️ 代价：谓词字符串依赖 PG 的格式化规则，它变了 `migration:check` 就红。
 * 那是**可接受**的失败模式（红在 CI，不是红在生产），而且
 * `WalletSchemaTest` 另外直接对着 `pg_indexes.indexdef` 断言了一次，
 * 好让失败信息指向真正的原因而不是一句「不同步」。
 *
 * ============================================================================
 * 为什么 `revision` 必须有 `DEFAULT 1`
 * ============================================================================
 * 它映射成 Doctrine 的 `<version>` 字段，而 `BasicEntityPersister::prepareUpdateData()`
 * 把 version 字段从 INSERT 里**跳过**（改由 INSERT 之后 SELECT 回来）。
 * 也就是说建卡的 INSERT 语句里根本没有 `revision` 列 —— 没有这个 DEFAULT，
 * 第一次 `POST /v1/cards` 就撞 NOT NULL。
 *
 * ============================================================================
 * 哪些列有 CHECK，哪些没有
 * ============================================================================
 * 只有 `title` / `merchant_label` 的长度有 CHECK（§17.1 原样）。
 * `color` 与 `barcode_format` 是**开放词汇**：§13.6 允许新增枚举值，
 * 写进 CHECK 之后加一个新条码格式就成了一次要停机对齐的迁移。
 * 值域的强制点在 `CardCreatePayload` / `BarcodeFormat::tryFrom()`。
 * 口径同 `users` 上的取舍（`IdentitySchemaTest` 钉住的那条）。
 */
final class Version20260908182500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'T-109: cards table (Wallet), with the cross-module FK to users(id)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE cards (
              id                        UUID PRIMARY KEY,
              owner_id                  UUID NOT NULL,
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
            )
            SQL);

        // ⚠️ 外键单独一条 ALTER，而不是写在 CREATE TABLE 的列定义里 ——
        // 约束名必须是 `fk_cards_owner_id`，与 CrossModuleForeignKeys 里那个
        // 逐字相同。内联写法只能拿到 PG 自动生成的 `cards_owner_id_fkey`。
        //
        // ON DELETE RESTRICT 是 §17.1 原样，而且不能改成 CASCADE：
        // §3.7 的账号删除流程要靠它挡住「还持有卡的用户被删掉」。
        $this->addSql(<<<'SQL'
            ALTER TABLE cards
              ADD CONSTRAINT fk_cards_owner_id
              FOREIGN KEY (owner_id) REFERENCES users (id) ON DELETE RESTRICT
            SQL);

        // §17.1 原样的部分索引。名字与 Card.orm.xml 里的 <index> 逐字相同，
        // 谓词与那里 <option name="where"> 的内容**语义相同**（那边多一对括号，
        // 因为它抄的是 PG 规范化之后的样子 —— 见文件头）。
        //
        // 部分索引在这里是真有用的：软删的行占着主键但永远不出现在钱包列表里，
        // 而列表查询恒带 `deleted_at IS NULL`，所以它们进索引纯属浪费。
        $this->addSql('CREATE INDEX idx_cards_owner ON cards (owner_id) WHERE deleted_at IS NULL');
    }

    public function down(Schema $schema): void
    {
        // 索引与外键随表一起消失，不需要单独 DROP。
        $this->addSql('DROP TABLE cards');
    }
}
