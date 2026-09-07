<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * T-107：`users.username_attempts` —— §7.5「`POST /v1/me/username` 按 user 10 次总计」
 * 的落地载体。
 *
 * ============================================================================
 * 迁移元信息（CONTRIBUTING §5 / §13.5 要求每个迁移文件头都写这四项）
 * ============================================================================
 * **影响的表**：`users`（加一列，带 NOT NULL DEFAULT 0）。不动索引、不动约束。
 *
 * **预估执行时长**：< 50 ms。PG 11 起「加一个带常量 DEFAULT 的列」不重写表，
 *                   只改 catalog（`pg_attribute.atthasmissing`），与表的行数无关。
 *                   §9.3 的容量假设是 5 万注册，即使真要重写也是秒级。
 *
 * **是否锁表**：取 `ACCESS EXCLUSIVE`，但因为不重写表，持有时间是毫秒级。
 *               窗口内所有对 `users` 的读写都会排队 —— 那包括登录路径
 *               （`otp/verify` 要 upsert 一行）。可以接受，但**不要**把这个迁移
 *               与别的长事务放在同一次部署里：ACCESS EXCLUSIVE 会排在它后面等，
 *               而排队本身会把后续的读也堵住。
 *
 * **如何回滚**：`doctrine:migrations:migrate prev`，或直接跑 {@see down()}。
 *               `DROP COLUMN` 会丢掉全部计数 —— 后果是每个用户的 10 次预算被
 *               重置成满额。那是**放松**而不是收紧，所以回滚是安全的
 *               （不会有人因此被误拒），只是短期内枚举成本回到零。
 *
 * ============================================================================
 * 为什么这根列不在 §17.1 的 DDL 里
 * ============================================================================
 * §17.1 是 v1.1 定稿时的 `users` 定义，那时「10 次总计」还写在 §7.5 的**速率限制**
 * 表里 —— 看起来像是 Redis 的事。落地时才发现两条硬约束把它推到了库上：
 *
 *   1. §8.2 的 ROPA 规定限流计数**保留 24 小时**，而
 *      `RedisSlidingWindowRateLimiter` 的 TTL 正是照那条钉死在 86400 秒的；
 *      这个计数却要跨越整个账号生命周期（「成功一次后该端点永久 409」）。
 *   2. 它根本不是滑动窗口 —— 没有窗口长度，也没有恢复。
 *
 * `config/packages/rate_limiter.yaml` 的页脚与
 * `RateLimitPolicyCoverageTest::NOT_RATE_LIMITED` 从 T-006 起就登记了这条豁免，
 * 并且逐字写了「username 的 10 次挂在 users 行上（T-107 实现）」——
 * 也就是说这根列是**被预告过的**，不是本卡临时发明的。
 * 连带的取舍（为什么用尽时返回 422 而不是 429）记在 ADR-0017，§17.1 与 §7.5 已回改。
 *
 * ============================================================================
 * 为什么是 SMALLINT，为什么带 DEFAULT
 * ============================================================================
 * SMALLINT 与 `otp_challenges.attempts`（T-101）同型同因：上限是 10，
 * 而实体侧 `User::recordUsernameAttempt()` 到上限即饱和，涨不到 32767。
 *
 * `NOT NULL DEFAULT 0` 让这次变更满足 §13.5 的 expand–contract：既有行不需要
 * 回填，旧版本的代码（不知道这一列）照常 INSERT 也不会失败。因此**不需要**
 * 分三次发布，一次即可 —— 与 T-101 给 `users.username` 加列时同一条论证。
 *
 * ⚠️ 这个 DEFAULT 必须在 `User.orm.xml` 的 `<options>` 里逐字重复一遍，
 * 否则 `doctrine:schema:validate` 会报「不同步」：Comparator 比的是
 * `getColumnDeclarationSQL()` 的字符串相等，DEFAULT 也在那串里。
 *
 * ⚠️ 刻意**不加 CHECK (username_attempts BETWEEN 0 AND 10)**。§17.1 的口径是
 * 只有那三列（username / locale / status）带 CHECK —— 它们的值域是**契约的一部分**
 * （客户端按它们分支）。这一列是内部计数：把 10 写进库层意味着改 §7.5 的数字
 * 要发两次（先改 CHECK 再改配置），而反过来的顺序会让新值被库层拒掉。
 * 上限的真相在 `%ncards.limits.username_attempts_per_user%`，只在那一处。
 */
final class Version20260907170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'T-107: users.username_attempts, the lifetime counter behind the 10-attempt cap on POST /v1/me/username';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ADD COLUMN username_attempts SMALLINT NOT NULL DEFAULT 0');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users DROP COLUMN username_attempts');
    }
}
