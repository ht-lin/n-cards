<?php

declare(strict_types=1);

namespace App\Tests\Integration\Module\Sharing\Doctrine;

use App\Shared\Domain\Identity\Uuid;
use App\Tests\Integration\Support\RequiresSharingSchema;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * `card_members` 的**库层**形状与约束（§5.2 / §17.1，T-110）。
 *
 * 形状同 {@see \App\Tests\Integration\Module\Wallet\Doctrine\WalletSchemaTest}：
 * 这里全部用**裸 SQL**，刻意绕开 ORM —— 验的是数据库自己拦不拦得住。
 * ORM 那一侧由 `DoctrineCardMemberRepositoryTest` 与 `doctrine:schema:validate` 管。
 *
 * ============================================================================
 * 为什么 `uq_card_single_owner` 值得两条用例而不是一条
 * ============================================================================
 * 那是一条**部分**唯一索引，谓词是 `role = 'owner' AND left_at IS NULL`。
 * 只断言「插第二行 owner 被拒」证明不了它是部分的 —— 一条普通唯一索引
 * （`UNIQUE (card_id) WHERE role = 'owner'`，甚至 `UNIQUE (card_id)`）
 * 也能让那条用例绿。
 *
 * 真正要钉住的是**两半**：
 *   1. 活跃的第二个 owner 被拒（{@see testASecondActiveOwnerIsRejected()}）；
 *   2. 第一个 owner 立了墓碑之后，同一张卡**可以**再有一个 owner
 *      （{@see testAnOwnerMayBeReplacedAfterTheFirstOneLeft()}）。
 *
 * 第 2 条才是「`left_at IS NULL` 真的在谓词里」的证据。它同时是 M3 的前提：
 * §5.2 允许一张卡有历史 owner 行。
 */
#[CoversNothing]
final class SharingSchemaTest extends KernelTestCase
{
    use RequiresSharingSchema;

    private Uuid $ownerId;

    private Uuid $cardId;

    protected function setUp(): void
    {
        $this->bootSharingSchema();

        $this->ownerId = $this->seedOwner();
        $this->cardId = $this->seedCard($this->ownerId);
    }

    protected function tearDown(): void
    {
        $this->rollbackSharingSchema();

        parent::tearDown();
    }

    // ========================================================================
    // 列与默认值
    // ========================================================================

    public function testTheColumnsAreExactlyTheOnesInTheSpec(): void
    {
        $columns = $this->connection->fetchAllKeyValue(
            "SELECT column_name, data_type FROM information_schema.columns
              WHERE table_name = 'card_members' ORDER BY column_name",
        );

        self::assertSame([
            'added_by' => 'uuid',
            'card_id' => 'uuid',
            'is_pinned' => 'boolean',
            'joined_at' => 'timestamp with time zone',
            'left_at' => 'timestamp with time zone',
            'role' => 'text',
            'sort_order' => 'integer',
            'user_id' => 'uuid',
        ], $columns);
    }

    /**
     * `sort_order` 是 **INTEGER** 不是 BIGINT —— `CardPlacementPayload` 的
     * int32 范围校验就是照着这一列写的。这条用例是那个校验的依据，
     * 有人把列改宽了就该顺手把那个校验也放宽。
     */
    public function testSortOrderIsA32BitInteger(): void
    {
        $precision = $this->connection->fetchOne(
            "SELECT numeric_precision FROM information_schema.columns
              WHERE table_name = 'card_members' AND column_name = 'sort_order'",
        );

        self::assertSame(32, (int) $precision);
    }

    public function testTheDefaultsMatchTheSpec(): void
    {
        $defaults = $this->connection->fetchAllKeyValue(
            "SELECT column_name, column_default FROM information_schema.columns
              WHERE table_name = 'card_members' AND column_default IS NOT NULL
              ORDER BY column_name",
        );

        self::assertSame([
            'is_pinned' => 'false',
            'joined_at' => 'now()',
            'sort_order' => '0',
        ], $defaults);
    }

    // ========================================================================
    // 索引
    // ========================================================================

    public function testThereAreExactlyThreeIndexes(): void
    {
        $names = $this->connection->fetchFirstColumn(
            "SELECT indexname FROM pg_indexes WHERE tablename = 'card_members' ORDER BY indexname",
        );

        // ⚠️ 多出来的一条通常意味着有人在 CardMember.orm.xml 里加了索引却
        // 没加迁移（或反过来）。少一条则是 schema:validate 该抓但漏了的情况。
        self::assertSame(['card_members_pkey', 'idx_card_members_user', 'uq_card_single_owner'], $names);
    }

    /**
     * 谓词逐字 —— 这条用例是 `CardMember.orm.xml` 里
     * `<option name="where">` 那串的**真相来源**。
     *
     * ⚠️ `Index::samePartialIndex()` 拿两侧的 where 做 `===` 比较，而 PG 存回来的是
     * 规范化结果。这里抄错一个字符，`schema:validate` 就永久不同步，
     * 而它的失败信息只有一句「不同步」。本条用例存在的意义就是让那种失败
     * 指向真正的原因。
     */
    public function testTheOwnerIndexIsUniquePartialOnLiveOwnerRows(): void
    {
        $definition = $this->connection->fetchOne(
            "SELECT indexdef FROM pg_indexes
              WHERE tablename = 'card_members' AND indexname = 'uq_card_single_owner'",
        );

        self::assertIsString($definition);
        self::assertStringContainsString('CREATE UNIQUE INDEX', $definition);
        self::assertStringContainsString('(card_id)', $definition);
        // 注意是 `role` 而不是 `(role)::text` —— 那一列是 TEXT 不是 VARCHAR。
        self::assertStringContainsString("WHERE ((role = 'owner'::text) AND (left_at IS NULL))", $definition);
    }

    public function testTheUserIndexIsPartialOnLiveRows(): void
    {
        $definition = $this->connection->fetchOne(
            "SELECT indexdef FROM pg_indexes
              WHERE tablename = 'card_members' AND indexname = 'idx_card_members_user'",
        );

        self::assertIsString($definition);
        self::assertStringContainsString('(user_id)', $definition);
        self::assertStringContainsString('WHERE (left_at IS NULL)', $definition);
    }

    // ========================================================================
    // 「一卡一 owner」—— T-110 的验收标准第一条
    // ========================================================================

    public function testASecondActiveOwnerIsRejected(): void
    {
        $this->insertMember($this->ownerId, 'owner');

        $second = $this->seedOwner();

        $this->expectException(UniqueConstraintViolationException::class);

        $this->insertMember($second, 'owner');
    }

    /**
     * 谓词的**另一半**：墓碑行不占那个位置。
     *
     * 只测上面那条的话，一条不带 `left_at IS NULL` 的唯一索引也能全绿 ——
     * 而那会让 §5.2 允许的「历史 owner 行」永远插不进去，M3 的
     * 级联撤销（T-303）当场撞墙。
     */
    public function testAnOwnerMayBeReplacedAfterTheFirstOneLeft(): void
    {
        $first = $this->ownerId;
        $this->insertMember($first, 'owner');

        $this->connection->executeStatement(
            'UPDATE card_members SET left_at = now() WHERE card_id = ? AND user_id = ?',
            [$this->cardId->toString(), $first->toString()],
        );

        $second = $this->seedOwner();
        $this->insertMember($second, 'owner');

        self::assertSame(2, (int) $this->connection->fetchOne(
            'SELECT count(*) FROM card_members WHERE card_id = ?',
            [$this->cardId->toString()],
        ));
    }

    /** 多个 viewer 不受那条索引约束 —— 它的谓词里有 `role = 'owner'`。 */
    public function testManyViewersMayCoexist(): void
    {
        $this->insertMember($this->ownerId, 'owner');
        $this->insertMember($this->seedOwner(), 'viewer');
        $this->insertMember($this->seedOwner(), 'viewer');

        self::assertSame(2, (int) $this->connection->fetchOne(
            "SELECT count(*) FROM card_members WHERE card_id = ? AND role = 'viewer'",
            [$this->cardId->toString()],
        ));
    }

    /** 复合主键：同一个人在同一张卡上只能有一行。 */
    public function testTheSameUserCannotBeAddedTwiceToTheSameCard(): void
    {
        $this->insertMember($this->ownerId, 'owner');

        $this->expectException(UniqueConstraintViolationException::class);

        $this->insertMember($this->ownerId, 'viewer');
    }

    // ========================================================================
    // CHECK 与外键
    // ========================================================================

    /**
     * `role` 的取值域是**封闭**的（v1.1 删掉了 editor，C7）——
     * 这是本仓库唯一在库层带 CHECK 的枚举列，理由见 `CardRole` 的类注释。
     */
    public function testTheRoleCheckRejectsTheRemovedEditorRole(): void
    {
        $this->expectException(\Doctrine\DBAL\Exception::class);

        $this->insertMember($this->ownerId, 'editor');
    }

    public function testTheCardForeignKeyCascades(): void
    {
        $definition = $this->connection->fetchOne(
            "SELECT pg_get_constraintdef(oid) FROM pg_constraint WHERE conname = 'fk_card_members_card_id'",
        );

        self::assertIsString($definition);
        self::assertStringContainsString('REFERENCES cards(id)', $definition);
        self::assertStringContainsString('ON DELETE CASCADE', $definition);
    }

    public function testTheUserForeignKeyCascades(): void
    {
        $definition = $this->connection->fetchOne(
            "SELECT pg_get_constraintdef(oid) FROM pg_constraint WHERE conname = 'fk_card_members_user_id'",
        );

        self::assertIsString($definition);
        self::assertStringContainsString('REFERENCES users(id)', $definition);
        self::assertStringContainsString('ON DELETE CASCADE', $definition);
    }

    /**
     * `added_by` 是 SET NULL 而不是 CASCADE —— 邀请人删号不该把**被邀请人**
     * 的成员关系一起删掉，那张卡的共享还在。
     */
    public function testTheAddedByForeignKeySetsNull(): void
    {
        $definition = $this->connection->fetchOne(
            "SELECT pg_get_constraintdef(oid) FROM pg_constraint WHERE conname = 'fk_card_members_added_by'",
        );

        self::assertIsString($definition);
        self::assertStringContainsString('REFERENCES users(id)', $definition);
        self::assertStringContainsString('ON DELETE SET NULL', $definition);
    }

    public function testAMemberCannotReferenceAMissingCard(): void
    {
        $this->expectException(ForeignKeyConstraintViolationException::class);

        $this->connection->executeStatement(
            "INSERT INTO card_members (card_id, user_id, role, joined_at)
             VALUES (?, ?, 'owner', now())",
            ['0192f3a1-b2c3-7d4e-8f01-ffffffffffff', $this->ownerId->toString()],
        );
    }

    public function testDeletingACardCascadesToItsMembers(): void
    {
        $this->insertMember($this->ownerId, 'owner');

        // ⚠️ 硬删（`DELETE FROM cards`），不是 `DELETE /v1/cards/{id}` 的软删 ——
        // 后者只写 deleted_at，成员行**刻意**留着（§5.2 的墓碑 audience）。
        // 这条验的是 90 天后的硬删清理，以及 §17.4 删号脚本依赖的那条级联。
        $this->connection->executeStatement('DELETE FROM cards WHERE id = ?', [$this->cardId->toString()]);

        self::assertSame(0, (int) $this->connection->fetchOne(
            'SELECT count(*) FROM card_members WHERE card_id = ?',
            [$this->cardId->toString()],
        ));
    }

    private function insertMember(Uuid $userId, string $role): void
    {
        $this->connection->executeStatement(
            'INSERT INTO card_members (card_id, user_id, role, joined_at) VALUES (?, ?, ?, now())',
            [$this->cardId->toString(), $userId->toString(), $role],
        );
    }
}
