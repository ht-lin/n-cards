<?php

declare(strict_types=1);

namespace App\Tests\Integration\Module\Wallet\Doctrine;

use App\Tests\Integration\Support\RequiresWalletSchema;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * 迁移**真的**建出了 §17.1 要求的那张表。
 *
 * ============================================================================
 * 为什么这个文件存在，尽管 schema:validate 已经在 CI 上跑了
 * ============================================================================
 * `schema:validate` 回答的是「映射与库一致吗」。它对**两边同时写错**
 * 是盲的 —— 而 `Card.orm.xml` 与迁移是同一个人在同一个小时里写的，
 * 一起写错的概率不低。
 *
 * 这里的断言取自 **§17.1 的 DDL**，是第三个独立来源。
 * 口径同 `IdentitySchemaTest`。
 */
#[CoversNothing]
final class WalletSchemaTest extends KernelTestCase
{
    use RequiresWalletSchema;

    protected function setUp(): void
    {
        $this->bootWalletSchema();
    }

    protected function tearDown(): void
    {
        $this->rollbackWalletSchema();

        parent::tearDown();
    }

    /**
     * §17.1 的列表，逐字。
     *
     * @return iterable<string, array{string, string, bool, string|null}>
     */
    public static function columns(): iterable
    {
        yield 'id' => ['id', 'uuid', false, null];
        yield 'owner_id' => ['owner_id', 'uuid', false, null];
        yield 'title' => ['title', 'text', false, null];
        yield 'merchant_label' => ['merchant_label', 'text', true, null];
        yield 'color' => ['color', 'text', false, null];
        yield 'barcode_format' => ['barcode_format', 'text', false, null];
        yield 'barcode_value_encrypted' => ['barcode_value_encrypted', 'text', false, null];
        yield 'barcode_value_fingerprint' => ['barcode_value_fingerprint', 'bytea', true, null];
        yield 'note_encrypted' => ['note_encrypted', 'text', true, null];
        yield 'expires_on' => ['expires_on', 'date', true, null];
        // 二期 E2EE 的预留（§5.2）。DEFAULT 必须在，Card.orm.xml 里也声明了同一个值。
        yield 'encryption_scheme' => ['encryption_scheme', 'text', false, "'server_v1'::text"];
        // ⚠️ DEFAULT 1 不是装饰：Doctrine 把 version 字段从 INSERT 里跳过，
        // 没有它第一次建卡就撞 NOT NULL。
        yield 'revision' => ['revision', 'bigint', false, '1'];
        yield 'deleted_at' => ['deleted_at', 'timestamp with time zone', true, null];
        yield 'created_at' => ['created_at', 'timestamp with time zone', false, 'now()'];
        yield 'updated_at' => ['updated_at', 'timestamp with time zone', false, 'now()'];
    }

    #[DataProvider('columns')]
    public function testTheColumnMatchesTheSpec(string $name, string $type, bool $nullable, ?string $default): void
    {
        $row = $this->connection->fetchAssociative(
            'SELECT data_type, is_nullable, column_default
               FROM information_schema.columns
              WHERE table_name = :t AND column_name = :c',
            ['t' => 'cards', 'c' => $name],
        );

        self::assertIsArray($row, \sprintf('§17.1 要求 cards.%s 存在。', $name));
        self::assertSame($type, $row['data_type']);
        self::assertSame($nullable ? 'YES' : 'NO', $row['is_nullable']);

        if (null !== $default) {
            self::assertSame($default, $row['column_default']);
        }
    }

    public function testThereAreNoExtraColumns(): void
    {
        $actual = $this->connection->fetchFirstColumn(
            "SELECT column_name FROM information_schema.columns WHERE table_name = 'cards' ORDER BY column_name",
        );

        $expected = array_map(static fn (array $case): string => $case[0], iterator_to_array(self::columns()));
        sort($expected);

        // ⚠️ 这条会在有人往 cards 上加 `sort_order` / `is_pinned` 时红 ——
        // 那两个字段属于 card_members（§5.2 的共享模型），加错地方
        // 会让「同一张共享卡，Anna 置顶、Bob 不置顶」变得不可能。
        self::assertSame($expected, $actual);
    }

    public function testTheOwnerForeignKeyPointsAtUsersAndRestricts(): void
    {
        $definition = $this->connection->fetchOne(
            "SELECT pg_get_constraintdef(oid) FROM pg_constraint WHERE conname = 'fk_cards_owner_id'",
        );

        // 这条外键是**跨模块**的，ORM 侧由 CrossModuleForeignKeys 补进 schema
        // 而不是走 <many-to-one>（ADR-0019）。库层它必须真的在。
        self::assertIsString($definition, 'fk_cards_owner_id 不见了 —— §3.7 的账号删除流程靠它兜底。');
        self::assertStringContainsString('REFERENCES users(id)', $definition);
        // ⚠️ RESTRICT 不能改成 CASCADE：删号时「还持有卡」是一个要人来处理的
        // 冲突，不是可以静默级联删掉的东西。
        self::assertStringContainsString('ON DELETE RESTRICT', $definition);
    }

    /**
     * §17.1 的部分索引。
     *
     * ⚠️ 谓词这一半是**实测**逼出来的：DBAL 4 会把 `pg_get_expr(indpred, …)`
     * 读进索引的 `where` 选项并与 ORM 侧做 `===` 比较，而 PG 存回来的是
     * 规范化结果（带一对外层括号）。`Card.orm.xml` 里那个
     * `<option name="where">(deleted_at IS NULL)</option>` 抄的就是下面这串。
     *
     * 这条用例在 PG 改了格式化规则时会**先于** schema:validate 红，
     * 而且失败信息直接指向真正的原因，不是一句「不同步」。
     */
    public function testTheOwnerIndexIsPartialOnLiveRows(): void
    {
        $definition = $this->connection->fetchOne(
            "SELECT indexdef FROM pg_indexes WHERE tablename = 'cards' AND indexname = 'idx_cards_owner'",
        );

        self::assertIsString($definition);
        self::assertStringContainsString('(owner_id)', $definition);
        self::assertStringContainsString('WHERE (deleted_at IS NULL)', $definition);
    }

    /**
     * ⚠️ 只有两条索引。
     *
     * DBAL 给外键列自动补的那条 `IDX_<hash>` **不该**落到库里 ——
     * `Card.orm.xml` 里显式声明的 `idx_cards_owner` 就是为了顶掉它。
     */
    public function testThereAreExactlyTwoIndexes(): void
    {
        $names = $this->connection->fetchFirstColumn(
            "SELECT indexname FROM pg_indexes WHERE tablename = 'cards' ORDER BY indexname",
        );

        self::assertSame(['cards_pkey', 'idx_cards_owner'], $names);
    }

    /**
     * §17.1 只给 title / merchant_label 写了 CHECK。
     *
     * `color` 与 `barcode_format` **刻意没有** —— §13.6 允许新增枚举值，
     * 写进 CHECK 之后加一个新条码格式就成了一次要停机对齐的迁移。
     * 口径同 `users` 上的取舍。
     */
    public function testOnlyTheLengthConstraintsAreCheckedInTheDatabase(): void
    {
        $definitions = $this->connection->fetchFirstColumn(
            "SELECT pg_get_constraintdef(c.oid)
               FROM pg_constraint c
               JOIN pg_class t ON t.oid = c.conrelid
              WHERE t.relname = 'cards' AND c.contype = 'c'
              ORDER BY 1",
        );

        self::assertCount(2, $definitions);
        self::assertStringContainsString('char_length(merchant_label)', $definitions[0]);
        self::assertStringContainsString('char_length(title)', $definitions[1]);
    }
}
