<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * T-104：`otp_challenges` 增 `email_encrypted` 与 `locale` 两列 —— 注册路径所需。
 *
 * ============================================================================
 * 迁移元信息（CONTRIBUTING §5 / §13.5 要求每个迁移文件头都写这四项）
 * ============================================================================
 * **影响的表**：`otp_challenges`（加两列）。不动 `users` / `devices` / `sessions`。
 *
 * **预估执行时长**：< 50 ms。两条 `ADD COLUMN`，都可空、都无默认值，
 *                   PG 11+ 下不重写表；且这张表本身是短命数据（挑战 10 分钟过期）。
 *
 * **是否锁表**：取 `ACCESS EXCLUSIVE`，但只在目录更新的那一瞬。
 *               可空且无 DEFAULT 的 `ADD COLUMN` 不扫表、不重写行。
 *               CHECK 约束**带 `NOT VALID`**，因此也不扫既有行 —— 见下。
 *
 * **如何回滚**：`doctrine:migrations:migrate prev`，或直接跑 {@see down()}。
 *               回滚会丢掉这两列里的值，也就是让部署窗口内建的挑战失去注册所需的
 *               邮箱密文；后果与「挑战过期」相同（用户重新点一次「发送验证码」），
 *               10 分钟内自愈。ADR-0010 写明生产的自动回滚只回镜像 tag、不回迁移。
 *
 * ============================================================================
 * 为什么 `otp_challenges` 需要知道邮箱密文
 * ============================================================================
 * §5.2 / §6.3.1：`POST /auth/otp/verify` **成功即注册**（首次验证创建 `users` 行）。
 * 建那一行需要 `email_encrypted`（发信用）与 `locale`（界面与信件语言），
 * 而此刻明文邮箱早已不在系统里 —— 它只在 `POST /auth/otp/request` 的请求体里活过一次。
 *
 * T-101 建表时没有这两列，是因为当时 T-103 的设计是「邮箱不存在 → 哑挑战 + 不发信」，
 * 于是**没有任何注册路径**（详见 ADR-0014）。T-104 把那条改成「恒发码」，
 * 注册路径随之成立，这两列就是它的输入。
 *
 * ⚠️ 存的是**密文**（`vault:v1:…`），与 `users.email_encrypted` 同一把 Transit 密钥
 * （`CryptoKey::Pii`）。这张表因此不再是「只有哈希」的表，ROPA §8.2 里它的分类
 * 从「哈希后的个人数据」变成「加密的个人数据」—— 保留期不变（挑战本就短命，
 * T-113 的清理任务照删），但 §8.4 的数据导出要把它算进去。
 *
 * ============================================================================
 * 两列都可空，且这不是过渡态
 * ============================================================================
 * 可空是 §13.5 expand–contract 的 expand 阶段：迁移先上、代码后上，
 * 中间那段时间旧代码仍在写不带这两列的行。
 *
 * 但**收窄成 NOT NULL 的那一步不会来**：`OtpChallenge::decoy()` 建的哑挑战
 * 天然没有收件人（T-104 之后已无生产调用方，但工厂与既有的行还在），
 * 而 T-106 将来的 Magic Link 挑战也未必需要 locale。
 * 取值域由 PHP 侧的 `?Ciphertext` / `?Locale` 表达，读侧（`VerifyOtpService`）
 * 显式处理 null → 401。
 *
 * ============================================================================
 * locale 的 CHECK 带 `NOT VALID`
 * ============================================================================
 * 与 `chk_users_locale` 同形（取值域是契约固定的 `de` / `en`，不会长），
 * 但加上 `NOT VALID`：那让 PG 跳过对既有行的全表校验，只对**新写入**生效。
 * 既有行的这一列全是 NULL，而 CHECK 对 NULL 恒为「未违反」，所以校验本来也查不出东西 ——
 * `NOT VALID` 只是把那次没有意义的全表扫描省掉。
 *
 * ⚠️ 与 `platform` / `purpose` / `revoked_reason` 不加 CHECK 的取舍相反，理由见
 * `Version20260905101500` 结尾那段注释：那三列的取值域**还会长**，
 * 而 `locale` 的不会（§11.1 一期德英双语，加第三种语言是产品决策不是兼容性变更）。
 */
final class Version20260906120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'T-104: otp_challenges.email_encrypted + locale, so that a first successful verify can register the user';
    }

    public function up(Schema $schema): void
    {
        // Vault Transit 密文（`vault:v1:…`），与 users.email_encrypted 同一把密钥。
        // 首次验证成功时用它建 users 行 —— 那时明文邮箱早已不在系统里。
        $this->addSql('ALTER TABLE otp_challenges ADD COLUMN email_encrypted TEXT');

        // 请求验证码时客户端选的语言。注册时进 users.locale ——
        // 收到英文验证码信却拿到一个 locale=de 的账号，是用户能看见的 bug。
        $this->addSql('ALTER TABLE otp_challenges ADD COLUMN locale TEXT');

        $this->addSql(<<<'SQL'
            ALTER TABLE otp_challenges
                ADD CONSTRAINT chk_otp_challenges_locale CHECK (locale IN ('de','en')) NOT VALID
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE otp_challenges DROP CONSTRAINT chk_otp_challenges_locale');
        $this->addSql('ALTER TABLE otp_challenges DROP COLUMN locale');
        $this->addSql('ALTER TABLE otp_challenges DROP COLUMN email_encrypted');
    }
}
