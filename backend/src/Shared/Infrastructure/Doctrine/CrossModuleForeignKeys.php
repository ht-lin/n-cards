<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\DBAL\Schema\Table;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Doctrine\ORM\Tools\ToolEvents;

/**
 * 跨模块外键的**唯一**登记处（ADR-0019，T-109 落地）。
 *
 * ============================================================================
 * 它解决的是什么
 * ============================================================================
 * ADR-0011 第 3 条要求「库里的每条外键都要在 ORM 侧有对应的关联」，否则
 * `doctrine:schema:validate` 会报「多出一条待删外键」。那一条对**模块内部**的
 * 外键是对的（`devices.user_id`、`sessions.user_id` 都是 `<many-to-one>`）。
 *
 * 跨模块的外键做不到：`cards.owner_id → users(id)` 要写成
 * `Card.owner → User` 的关联，就得让 `Wallet.Domain` import
 * `Identity\Domain\Entity\User`，而 deptrac 里 `Wallet.Domain: [Shared.Domain]`。
 * `card_members`、`friendships`、`share_invitations` 全都是同一个形状。
 * ADR-0011 把这件事挂起，写明「留给 T-109 单独决策并写一篇新 ADR」。
 *
 * 出路是把这条外键从 **ORM 元数据**里搬出来，放进**生成 schema 的那一步**：
 * 实体只持有一个 uuid 列，外键在这里补。
 *
 * ============================================================================
 * 为什么这样 schema:validate 还能绿
 * ============================================================================
 * `SchemaValidator::schemaInSyncWithMetadata()` → `getUpdateSchemaList()` →
 * `SchemaTool::getSchemaFromMetadata()`，而后者在生成完毕后派发
 * {@see ToolEvents::postGenerateSchema}，并**回读监听器改过的 schema**
 * （`SchemaTool.php` 里那句 "Always retrieve the schema (listener may have mutated it)"）。
 * `doctrine:migrations:diff` 走同一条路径，所以生成的 diff 也不会想删掉这些外键。
 *
 * ============================================================================
 * 为什么它不违反「Shared 不得依赖任何模块」（§4.2 规则 4）
 * ============================================================================
 * 本类**不 import 任何模块的类型**。它只认表名与列名的**字符串** ——
 * deptrac 按 FQCN 收集依赖，字符串不是依赖。用到的
 * `Doctrine\*` 全落在 `Framework.Persistence`，而
 * `Shared.Infrastructure` 的允许列表里本来就有它。零配置改动、零 violation，
 * `deptrac.yaml` 的 `skip_violations` 保持为空。
 *
 * 代价是这些外键**没有编译期检查**：表名或列名写错了，
 * schema:validate 会在下一次 CI 上红（多一条 ORM 侧的外键、库里没有）。
 * `CrossModuleForeignKeysTest` 直接对着真库断言每一条都存在。
 *
 * ============================================================================
 * ⚠️ 顺带的好处：§4.2 规则 5 从此是结构性成立的
 * ============================================================================
 * 拿不到 `$card->getOwner()`，就写不出 `$card->getOwner()->getUsername()` ——
 * 也就写不出跨模块 JOIN。走 ORM 关联的方案反而会把那扇门打开。
 * 要 owner 的用户名请走 Identity 的 `Application\Port`。
 *
 * ============================================================================
 * ⚠️ 加一条外键时要同时做三件事
 * ============================================================================
 * 1. 在下面的 {@see FOREIGN_KEYS} 里加一行；
 * 2. 在迁移里用**逐字相同**的约束名建它；
 * 3. 在引用侧实体的 `.orm.xml` 里显式声明一条**同列索引**，名字与迁移一致。
 *
 * 第 3 条最容易漏，症状也最费解：DBAL 的
 * `Table::_addForeignKeyConstraint()` 会给外键列自动补一条 `IDX_<hash>` 索引，
 * 除非已有索引 `isFulfilledBy` 它 —— 漏了就是 ORM 侧凭空多一条索引、库里没有。
 * 这与 `Device.orm.xml` 里记过的是同一个坑。
 */
#[AsDoctrineListener(event: ToolEvents::postGenerateSchema)]
final readonly class CrossModuleForeignKeys
{
    /**
     * 全仓库的跨模块外键，逐条对应 §17.1 的 DDL。
     *
     * 形状：`约束名 => [引用侧表, 引用侧列, 被引用表, 被引用列, on-delete]`。
     *
     * ⚠️ `on-delete` 必须与迁移逐字一致：`Comparator::diffForeignKey()`
     * 不比约束名，但**比 onDelete** —— 写错就是一条永久 diff。
     *
     * @var non-empty-array<string, array{string, string, string, string, string}>
     */
    private const FOREIGN_KEYS = [
        // T-109。RESTRICT 而不是 CASCADE：§3.7 的账号删除流程靠它挡住
        // 「还持有卡的用户被删掉」，那是一个要人来处理的冲突，不是可以静默级联的事。
        'fk_cards_owner_id' => ['cards', 'owner_id', 'users', 'id', 'RESTRICT'],

        // ------------------------------------------------------------- T-110
        // `card_members` 属 **Sharing**（§4.2 的模块图），而 `cards` 属 Wallet、
        // `users` 属 Identity —— 所以这张表的**三条**外键全都跨模块。
        //
        // ⚠️ ADR-0019 的「影响」行原先写的是「T-110 的**两条**外键指向 users」。
        // 那是按 card_members 归 Wallet 算的（那样 card_id 会是模块内的
        // <many-to-one>）。归属定在 Sharing 之后是三条，该 ADR 已随 T-110 修订。
        //
        // `fk_card_members_card_id` 是本清单里第一条指向**另一个模块业务表**
        // （而不是 users）的外键 —— ADR-0019 的 Alternatives 一节点过这个形状
        // 「card_members → cards 这种『Sharing 指向 Wallet』的外键还是同样的问题」，
        // 决策覆盖得到，只是那个计数写岔了。
        'fk_card_members_card_id' => ['card_members', 'card_id', 'cards', 'id', 'CASCADE'],

        // ⚠️ 这里是 CASCADE，而上面 `fk_cards_owner_id` 是 RESTRICT ——
        // 看起来不一致，其实是 §3.7 删号编排要的顺序：删一个用户会被他
        // **自己的卡**挡住（RESTRICT，要人来处理），但他作为 viewer 的成员行
        // 可以随他一起消失（CASCADE，那只是一条授权记录）。
        // 于是 §17.4 的删号脚本是「先删卡 → 成员行自动没 → 再删用户」。
        'fk_card_members_user_id' => ['card_members', 'user_id', 'users', 'id', 'CASCADE'],

        // SET NULL：`added_by` 只是「谁把他加进来的」这条审计线索。
        // 邀请人删号不该把**被邀请人**的成员关系一起删掉 —— 那张卡的共享还在。
        'fk_card_members_added_by' => ['card_members', 'added_by', 'users', 'id', 'SET NULL'],
    ];

    public function postGenerateSchema(GenerateSchemaEventArgs $args): void
    {
        $schema = $args->getSchema();

        foreach (self::FOREIGN_KEYS as $name => [$table, $column, $foreignTable, $foreignColumn, $onDelete]) {
            // ⚠️ 存在性检查不是防御性编程。`schema_filter`（doctrine.yaml）会把
            // 某些表挡在生成结果之外，而单元测试里也可能只装一个模块的元数据 ——
            // 两种情况下 getTable() 都会抛。跳过比炸掉合理：这一步只是在
            // 补充已经存在的表的约束。
            if (!$schema->hasTable($table) || !$schema->hasTable($foreignTable)) {
                continue;
            }

            $this->addForeignKey($schema->getTable($table), $name, $column, $foreignTable, $foreignColumn, $onDelete);
        }
    }

    private function addForeignKey(
        Table $table,
        string $name,
        string $column,
        string $foreignTable,
        string $foreignColumn,
        string $onDelete,
    ): void {
        // 幂等：同一个 EntityManager 上跑两次 getSchemaFromMetadata()（测试里很常见）
        // 会让本监听器被调用两次。DBAL 4 对重复添加同名外键发 deprecation，
        // 而 phpunit.xml.dist 开了 failOnWarning —— 那会变成一条测试失败。
        if ($table->hasForeignKey($name)) {
            return;
        }

        $table->addForeignKeyConstraint(
            $foreignTable,
            [$column],
            [$foreignColumn],
            ['onDelete' => $onDelete],
            $name,
        );
    }
}
