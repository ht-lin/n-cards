<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * T-102：Messenger 的 `messenger_messages` 表 —— 外发邮件队列的落地处。
 *
 * ============================================================================
 * 迁移元信息（CONTRIBUTING §5 / §13.5 要求每个迁移文件头都写这四项）
 * ============================================================================
 * **影响的表**：新建 `messenger_messages`。不改动任何既有表。
 *
 * **预估执行时长**：< 50 ms。一条 `CREATE TABLE` 加一条索引，作用在空表上。
 *
 * **是否锁表**：否。只创建新对象。
 *
 * **如何回滚**：`doctrine:migrations:migrate prev`，或直接跑 {@see down()}。
 *               ⚠️ 回滚会丢掉**队列里尚未投递的信**。实践中的影响很小 ——
 *               队列的正常深度是 0，且里面躺的东西有效期只有 10 分钟（§7.1 的
 *               OTP）。但 ADR-0010 写明生产的自动回滚**只回镜像 tag、不回迁移**，
 *               所以这条路径在生产不会被走到；`down()` 的存在是为了让
 *               `tools/migration-check.sh` 步骤 ② 的 up/down 往返能跑。
 *
 * ============================================================================
 * 为什么这张表由迁移建，而不是让 transport 自己建
 * ============================================================================
 * Doctrine transport 默认带 `auto_setup=1`，第一次收发消息时自己 `CREATE TABLE`。
 * `MESSENGER_TRANSPORT_DSN` 里显式关掉了它（`?auto_setup=0`），因为：
 *
 *   1. §13.5 与 CONTRIBUTING §5 要求**所有** DDL 走迁移。auto_setup 会绕过
 *      那道门，于是这张表的结构不在版本控制里，评审时也看不见。
 *   2. auto_setup 在每次收发前都要查一次 schema，那是每条消息一次多余往返。
 *   3. 本地、staging、prod 的表可能在不同时间被不同版本的 symfony/messenger
 *      建出来，结构悄悄分叉，而分叉的症状要到某条 SQL 报错时才显形。
 *
 * ============================================================================
 * ⚠️ DDL 逐字照抄 symfony/doctrine-messenger 的 `Connection::buildSchemaTable()`
 * ============================================================================
 * 包括 `TIMESTAMP(0) WITHOUT TIME ZONE`——**这是本仓库唯一不用 TIMESTAMPTZ 的地方**，
 * 与 §17.1 给业务表定的「一律 TIMESTAMPTZ」相反。
 *
 * 理由是这张表不是我们的：读写它的 SQL 全部由 transport 自己拼
 * （`Connection::get()` / `send()` 里那些 `available_at <= ?`），
 * 绑值走 DBAL 的 `datetime_immutable` 类型。我们把列换成 TIMESTAMPTZ 的话，
 * 同样的字面量会被 PG 按会话时区解释一次 —— 只要容器的 PHP 时区与 PG 的
 * `TimeZone` 有任何差异，「这条消息什么时候可投递」就会整体偏移几小时，
 * 而症状是「邮件延迟发出」这种最难归因的现象。
 *
 * 换句话说：业务表的时间列由我们定义语义，所以用 TIMESTAMPTZ；
 * 这张表的时间列由 transport 定义语义，我们只负责让它与 upstream 一致。
 * 一致性由 `doctrine:schema:validate` 持续校验 —— symfony/doctrine-messenger 的
 * schema listener 会把这张表加进 ORM 的期望 schema，两边对不上就红
 * （`composer migration:check` 步骤 ③）。
 *
 * ============================================================================
 * 为什么只有一条索引
 * ============================================================================
 * upstream 就是一条四列复合索引 `(queue_name, available_at, delivered_at, id)`，
 * 精确覆盖 `Connection::get()` 那条「取下一条可投递消息」的查询。
 * 别按直觉给 `delivered_at` 之类单独补索引 —— 复合索引已经覆盖，
 * 多出来的只会拖慢每一次 INSERT，而这张表是写多读少的。
 */
final class Version20260905183000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'T-102: messenger_messages table for the outbound email transport';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE messenger_messages (
                id           BIGSERIAL    NOT NULL,
                body         TEXT         NOT NULL,
                headers      TEXT         NOT NULL,
                queue_name   VARCHAR(190) NOT NULL,
                created_at   TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                available_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                delivered_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                PRIMARY KEY (id)
            )
            SQL);

        $this->addSql('CREATE INDEX idx_messenger_messages_queue ON messenger_messages (queue_name, available_at, delivered_at, id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE messenger_messages');
    }
}
