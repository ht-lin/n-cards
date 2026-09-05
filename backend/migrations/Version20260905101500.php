<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * T-101：Identity 的四张表（§5.2 / §17.1）—— 本仓库的第一个迁移。
 *
 * ============================================================================
 * 迁移元信息（CONTRIBUTING §5 / §13.5 要求每个迁移文件头都写这四项）
 * ============================================================================
 * **影响的表**：新建 `users` / `otp_challenges` / `devices` / `sessions`。
 *               不改动任何既有表 —— 库里此刻没有业务表。
 *
 * **预估执行时长**：< 100 ms。四条 `CREATE TABLE` 加六条索引，全部作用在空表上。
 *
 * **是否锁表**：否。只创建新对象，不触碰任何既有对象，因此不与任何在线查询争锁。
 *
 * **如何回滚**：`doctrine:migrations:migrate prev`，或直接跑 {@see down()} ——
 *               四条 `DROP TABLE`，按外键反序。⚠️ 回滚会**删掉全部账号数据**。
 *               这在 T-101 落地当天是安全的（表里没有数据），
 *               此后就不是了：ADR-0010 写明生产的自动回滚**只回镜像 tag，
 *               不回迁移**，所以真出事时的正确动作是往前修，不是往回滚。
 *               `down()` 的存在是为了让 CI 的 up/down 往返能跑
 *               （`tools/migration-check.sh` 步骤 ②），不是生产预案。
 *
 * ============================================================================
 * 为什么没有 CREATE INDEX CONCURRENTLY
 * ============================================================================
 * §13.5 / CONTRIBUTING §5 要求「加会锁表的索引要用 `CREATE INDEX CONCURRENTLY`」。
 * 那条规范针对的是**给存量表加索引**——那会拿住 SHARE 锁，把写请求全挡住。
 *
 * 本迁移的每条索引都随 `CREATE TABLE` 建在一张刚出生的空表上，没有任何东西能被它挡住。
 * 而且 `CONCURRENTLY` **不能在事务里执行**，Doctrine 迁移默认整个包在一个事务里 ——
 * 硬用的话得关掉事务，反而让「四张表要么全建成要么全不建」这条保证没了。
 *
 * ============================================================================
 * CHECK 约束只加三条，这个不对称是有意的
 * ============================================================================
 * `users.username` / `users.locale` / `users.status` 三列带 CHECK —— §17.1 逐字写明。
 *
 * 其余闭合词表（`devices.platform`、`otp_challenges.purpose`、
 * `sessions.revoked_reason`）**只做 PHP enum，不加 CHECK**。理由是 §13.6 把
 *「新增枚举值」列为向后兼容变更：加了 CHECK 之后，每加一个值都要先发一次迁移改约束，
 * 再发一次代码放开 enum，而这三列的取值域本来就还会长（iOS、二次确认用途……）。
 *
 * `username` 的 CHECK 是**第二道防线** —— 归一化与字符集校验归 T-107 的
 * `Username` 值对象。即使应用层有 bug，也绝不会写入大写或非法字符。
 *
 * ============================================================================
 * expand–contract（§13.5）
 * ============================================================================
 * 本迁移只**新建表**，是 expand–contract 的第一步（「加可空新列 / 新表」），
 * 不含任何重命名、删列或 `SET NOT NULL`。新表上的 `NOT NULL` 不受那条禁令约束 ——
 * 它禁的是给**存量**列加约束。
 *
 * `users.username` 以可空加入即满足 §13.5，**无需**分三次发布（§5.2 有明确说明：
 * 一期尚无存量数据）。
 */
final class Version20260905101500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'T-101: Identity tables (users, otp_challenges, devices, sessions)';
    }

    public function up(Schema $schema): void
    {
        // ------------------------------------------------------------- users
        //
        // ⚠️ 没有明文邮箱列（§3.8）。`email_hash` 是查找键（HMAC-SHA256 + Vault
        // pepper），`email_encrypted` 是发信用的 Vault Transit 密文。
        //
        // `username` 可空是**注册中间态**（§5.2）：首次 OTP 验证即建行，那一刻还没设
        // username。不可变性由「没有 UPDATE 端点」保证，DB 层**不加触发器** ——
        // §17.1：触发器会挡住合法的运维修复，且与 Doctrine 的批量更新交互不佳。
        //
        // username 已在应用层归一化为小写，故普通 UNIQUE 即等价于大小写不敏感唯一，
        // 精确查找也走这个索引，不需要额外的函数索引。
        $this->addSql(<<<'SQL'
            CREATE TABLE users (
                id                    UUID        NOT NULL,
                email_hash            BYTEA       NOT NULL,
                email_encrypted       TEXT        NOT NULL,
                username              TEXT,
                locale                TEXT        NOT NULL DEFAULT 'de',
                status                TEXT        NOT NULL DEFAULT 'active',
                deletion_requested_at TIMESTAMPTZ,
                created_at            TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at            TIMESTAMPTZ NOT NULL DEFAULT now(),
                CONSTRAINT pk_users PRIMARY KEY (id),
                CONSTRAINT chk_users_username_format CHECK (username ~ '^[a-z0-9_]{3,20}$'),
                CONSTRAINT chk_users_locale CHECK (locale IN ('de','en')),
                CONSTRAINT chk_users_status CHECK (status IN ('active','pending_deletion'))
            )
            SQL);

        $this->addSql('CREATE UNIQUE INDEX uq_users_email_hash ON users (email_hash)');
        $this->addSql('CREATE UNIQUE INDEX uq_users_username ON users (username)');

        // --------------------------------------------------- otp_challenges
        //
        // ⚠️ **没有到 users 的外键**，存的是 email_hash 而不是 user_id。
        // 这是 §3.8 防枚举的承重墙：邮箱不存在时同样要建一条挑战
        // （`is_decoy = true`）并返回哑 challenge_id，好让响应体与耗时都与真实路径
        // 不可区分。有外键的话那条哑挑战根本插不进去。
        $this->addSql(<<<'SQL'
            CREATE TABLE otp_challenges (
                id               UUID        NOT NULL,
                email_hash       BYTEA       NOT NULL,
                code_hash        BYTEA       NOT NULL,
                magic_token_hash BYTEA,
                purpose          TEXT        NOT NULL,
                attempts         SMALLINT    NOT NULL DEFAULT 0,
                expires_at       TIMESTAMPTZ NOT NULL,
                consumed_at      TIMESTAMPTZ,
                is_decoy         BOOLEAN     NOT NULL DEFAULT false,
                request_ip_hash  BYTEA,
                created_at       TIMESTAMPTZ NOT NULL DEFAULT now(),
                CONSTRAINT pk_otp_challenges PRIMARY KEY (id)
            )
            SQL);

        // T-103 按邮箱作废旧挑战与限流都走这条索引。
        $this->addSql('CREATE INDEX idx_otp_challenges_email_hash ON otp_challenges (email_hash)');

        // ----------------------------------------------------------- devices
        //
        // id 由**客户端**生成（§5.2：安装级唯一，重装即新设备），所以它不是凭据 ——
        // 设备管理页的每个操作都必须再校验归属。
        //
        // 外键显式命名 `fk_<table>_<column>`（§5.2 / T-101 的实现要点）。
        // PG 不会为外键自动建索引，所以 idx_devices_user_id 要自己建。
        $this->addSql(<<<'SQL'
            CREATE TABLE devices (
                id                    UUID        NOT NULL,
                user_id               UUID        NOT NULL,
                platform              TEXT        NOT NULL,
                model                 TEXT,
                os_version            TEXT,
                app_version           TEXT,
                push_token            TEXT,
                push_token_updated_at TIMESTAMPTZ,
                last_seen_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
                revoked_at            TIMESTAMPTZ,
                created_at            TIMESTAMPTZ NOT NULL DEFAULT now(),
                CONSTRAINT pk_devices PRIMARY KEY (id),
                CONSTRAINT fk_devices_user_id FOREIGN KEY (user_id)
                    REFERENCES users (id) ON DELETE CASCADE
            )
            SQL);

        $this->addSql('CREATE INDEX idx_devices_user_id ON devices (user_id)');

        // ---------------------------------------------------------- sessions
        //
        // id 即 JWT 的 `sid` claim（§7.1）。`previous_token_hash` 是轮换重放检测的
        // 全部机制：收到等于这一列的令牌 = 判定令牌被窃（T-105）。
        //
        // 两条外键都是 CASCADE：账号删除与设备删除都应该带走会话行 ——
        // 会话是纯粹的派生状态，留着一条指向不存在用户的会话没有任何意义，
        // 而 §8.4 的删除要求「不留可关联到人的残余」。
        $this->addSql(<<<'SQL'
            CREATE TABLE sessions (
                id                  UUID        NOT NULL,
                user_id             UUID        NOT NULL,
                device_id           UUID        NOT NULL,
                refresh_token_hash  BYTEA       NOT NULL,
                previous_token_hash BYTEA,
                expires_at          TIMESTAMPTZ NOT NULL,
                revoked_at          TIMESTAMPTZ,
                revoked_reason      TEXT,
                created_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
                CONSTRAINT pk_sessions PRIMARY KEY (id),
                CONSTRAINT fk_sessions_user_id FOREIGN KEY (user_id)
                    REFERENCES users (id) ON DELETE CASCADE,
                CONSTRAINT fk_sessions_device_id FOREIGN KEY (device_id)
                    REFERENCES devices (id) ON DELETE CASCADE
            )
            SQL);

        $this->addSql('CREATE UNIQUE INDEX uq_sessions_refresh_token_hash ON sessions (refresh_token_hash)');
        $this->addSql('CREATE INDEX idx_sessions_user_id ON sessions (user_id)');
        $this->addSql('CREATE INDEX idx_sessions_device_id ON sessions (device_id)');
    }

    public function down(Schema $schema): void
    {
        // 外键反序。索引与约束随表一起消失，不需要单独 DROP。
        $this->addSql('DROP TABLE sessions');
        $this->addSql('DROP TABLE devices');
        $this->addSql('DROP TABLE otp_challenges');
        $this->addSql('DROP TABLE users');
    }
}
