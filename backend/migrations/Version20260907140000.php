<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * T-106：`otp_challenges.magic_token_hash` 上的唯一索引 —— Magic Link 的查询入口。
 *
 * ============================================================================
 * 迁移元信息（CONTRIBUTING §5 / §13.5 要求每个迁移文件头都写这四项）
 * ============================================================================
 * **影响的表**：`otp_challenges`（加一个索引）。**不加列** —— `magic_token_hash`
 *               从 T-101 建表时就在了（`Version20260905101500`），一直没有写入方。
 *
 * **预估执行时长**：< 50 ms。这张表是短命数据（挑战 10 分钟过期，T-113 每日清理），
 *                   任何时刻的行数都是分钟级的登录量，不是历史累积量。
 *
 * **是否锁表**：`CREATE INDEX` 默认取 `SHARE`，会阻塞对这张表的**写**（不阻塞读）。
 *               在这个表上是可以接受的：持续时间是毫秒级，而被阻塞的写是
 *               `POST /auth/otp/request` 的一条 INSERT，客户端侧看不到差别。
 *               ⚠️ 刻意**没有**用 `CONCURRENTLY`：它不能在事务里跑，而
 *               Doctrine Migrations 默认把每个迁移包在一个事务里；为这张表
 *               破坏那个保证不划算。表大到需要 CONCURRENTLY 的那天，
 *               先问的应该是「T-113 的清理任务是不是没跑」。
 *
 * **如何回滚**：`doctrine:migrations:migrate prev`，或直接跑 {@see down()}。
 *               `DROP INDEX` 不丢数据。回滚之后 `POST /auth/magic/consume`
 *               会退化成全表扫描（功能仍正确），而唯一性不再由库层兜底 ——
 *               这两条都只在「代码已上、迁移被回滚」这个窗口内成立。
 *
 * ============================================================================
 * ⚠️ 为什么**不是**部分索引，尽管那样更好
 * ============================================================================
 * 这一列在 T-106 之前建的行上全是 NULL，而 PG 的普通 UNIQUE 索引会把每个 NULL
 * 当作互不相等的值**逐个存进索引**。`WHERE magic_token_hash IS NOT NULL`
 * 能让它们整体缺席 —— 功能上确实更好。
 *
 * 但 DBAL **读不回**索引定义里的 WHERE 子句：加了它之后
 * `doctrine:schema:validate` 会永远报「不同步」，`schema:update --dump-sql`
 * 每次都吐同一对 `DROP INDEX` / `CREATE UNIQUE INDEX … WHERE …`。
 * 也就是说 `composer migration:check` 的第 ③ 步会**永久变红**，
 * 而那一步是这个仓库唯一能发现「映射与迁移漂了」的地方 —— 用它换一点索引空间
 * 是亏的。⚠️ 这是**实测**结论（本机 compose，2026-09-07），不是推测。
 *
 * ⚠️⚠️ **上面这段的归因是错的**（T-109 于 2026-09-08 重测，见
 * {@see Version20260908182500} 的文件头与 ADR-0019 末尾）。
 * 观察到的现象是真的，但 DBAL 4 **读得回**那个 WHERE 子句 ——
 * `PostgreSQLSchemaManager::selectIndexColumns()` 用
 * `pg_get_expr(indpred, indrelid)` 把它读进索引的 `where` 选项。
 * 真正的原因是 `Index::samePartialIndex()` 拿两侧做 `===` 字符串比较，
 * 而 PG 存回来的是规范化结果、**带一对外层括号**：XML 里写
 * `(magic_token_hash IS NOT NULL)` 就能对上，写不带括号的版本对不上。
 *
 * **本迁移不改。** 现在这条普通唯一索引功能正确，只是索引里多了一堆 NULL 行，
 * 而改它需要一次新迁移，收益是几十 KB。留给真正需要动 `otp_challenges` 的那张卡。
 * 这段话留在这里是为了下一个读到上面那段的人**不要**照着它下结论。
 *
 * 代价可以忽略：挑战 10 分钟过期、T-113 每日清理，任何时刻的行数都是
 * 分钟级的登录量，不是历史累积量。
 *
 * ============================================================================
 * 为什么是 UNIQUE 而不是普通索引
 * ============================================================================
 * 唯一性在这里不是「顺便」——它是一条安全不变量的库层兜底：
 * **一个令牌只能对应一条挑战**。碰撞的概率是 2^-256（不会发生），
 * 但真发生的话后果是「一个人的链接把另一个人登进去」，而普通索引下
 * `getOneOrNullResult()` 会抛 NonUniqueResult → 500，唯一索引下 INSERT 当场失败。
 * 两者都比「随手挑一行」强，而后者是没有唯一约束时最容易被写出来的实现。
 *
 * 与 `uq_sessions_refresh_token_hash`（T-101）同一条论证、同一个命名法。
 *
 * ============================================================================
 * ⚠️ 这一列存的是**本地 SHA-256**，不是 Vault HMAC
 * ============================================================================
 * 与同一行上的 `code_hash` 口径不同，这是刻意的：6 位码只有 10^6 种，
 * 一份不带 pepper 的摘要在拿到库备份后几秒钟就能全枚举；magic token 是
 * 32 字节 CSPRNG，没有可枚举的字典，pepper 买不到任何东西，而代价是每次消费
 * 在一条登录关键路径上多一次 Vault 往返。
 * 完整论证见 `ConsumeMagicLinkService::consume()`，与 `sessions.refresh_token_hash`
 * 是同一条。**不要顺手「统一」成一种。**
 */
final class Version20260907140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'T-106: unique index on otp_challenges.magic_token_hash for magic-link lookup';
    }

    public function up(Schema $schema): void
    {
        // ⚠️ 没有 `WHERE magic_token_hash IS NOT NULL` —— 见文件头那一节。
        // PG 允许唯一索引里有任意多个 NULL（NULLS DISTINCT 是默认行为），
        // 所以既有的 NULL 行不会互相冲突。
        $this->addSql('CREATE UNIQUE INDEX uq_otp_challenges_magic_token_hash ON otp_challenges (magic_token_hash)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uq_otp_challenges_magic_token_hash');
    }
}
