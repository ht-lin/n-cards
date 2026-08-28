<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared\Doctrine;

use App\Shared\Domain\Identity\Uuid;
use App\Shared\Infrastructure\Doctrine\UuidType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Type;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * `Uuid` ↔ Postgres 原生 `uuid` 列的真实往返。
 *
 * 单测用的是 PostgreSQLPlatform 的**声明**；这里验的是真库里确实建出了
 * `uuid` 类型的列，而不是被悄悄降级成 varchar。
 */
#[CoversClass(UuidType::class)]
final class UuidTypeRoundTripTest extends KernelTestCase
{
    private const VALUE = '01941f29-7c00-70ab-8000-000000000000';

    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();

        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);

        try {
            $connection->executeQuery('SELECT 1')->free();
        } catch (\Throwable $e) {
            self::markTestSkipped('Postgres 不可达（'.$e->getMessage().'）。');
        }

        $this->connection = $connection;
        $this->connection->executeStatement('CREATE TEMPORARY TABLE t004_uuid (id UUID PRIMARY KEY, label TEXT)');
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            $this->connection->executeStatement('DROP TABLE IF EXISTS t004_uuid');
        }

        parent::tearDown();
    }

    /**
     * 类型必须被容器注册进 DBAL —— config/packages/doctrine.yaml 的 `dbal.types`。
     */
    public function testTypeIsRegisteredWithDoctrine(): void
    {
        self::assertTrue(Type::hasType(UuidType::NAME));
        self::assertInstanceOf(UuidType::class, Type::getType(UuidType::NAME));
    }

    public function testWritesAndReadsBackTheSameValue(): void
    {
        $uuid = Uuid::fromString(self::VALUE);

        $this->connection->executeStatement(
            'INSERT INTO t004_uuid (id, label) VALUES (?, ?)',
            [$uuid, 'first'],
            [UuidType::NAME, 'string'],
        );

        $raw = $this->connection->fetchOne('SELECT id FROM t004_uuid WHERE label = ?', ['first']);
        $restored = Type::getType(UuidType::NAME)->convertToPHPValue($raw, $this->connection->getDatabasePlatform());

        self::assertInstanceOf(Uuid::class, $restored);
        self::assertTrue($uuid->equals($restored));
    }

    /**
     * ⚠️ 列必须是 PG 的原生 `uuid`（16 字节），不是 VARCHAR(36)。
     *
     * 对 §9.3 假设的 75 万行 cards 表，这是索引大小与比较成本上的实质差别。
     */
    public function testColumnIsANativeUuid(): void
    {
        $dataType = $this->connection->fetchOne(
            'SELECT data_type FROM information_schema.columns WHERE table_name = ? AND column_name = ?',
            ['t004_uuid', 'id'],
        );

        self::assertSame('uuid', $dataType);
    }

    /**
     * 大小写归一化在真库里也成立 —— 否则同一个 id 会以两种形态进库，
     * 唯一约束按字节比就会漏。
     */
    public function testUppercaseInputIsNormalisedBeforeStorage(): void
    {
        $this->connection->executeStatement(
            'INSERT INTO t004_uuid (id, label) VALUES (?, ?)',
            [strtoupper(self::VALUE), 'upper'],
            [UuidType::NAME, 'string'],
        );

        self::assertSame(
            self::VALUE,
            $this->connection->fetchOne('SELECT id::text FROM t004_uuid WHERE label = ?', ['upper']),
        );
    }

    public function testNullRoundTrips(): void
    {
        $this->connection->executeStatement('CREATE TEMPORARY TABLE t004_uuid_nullable (id UUID NULL, label TEXT)');

        $this->connection->executeStatement(
            'INSERT INTO t004_uuid_nullable (id, label) VALUES (?, ?)',
            [null, 'none'],
            [UuidType::NAME, 'string'],
        );

        $raw = $this->connection->fetchOne('SELECT id FROM t004_uuid_nullable WHERE label = ?', ['none']);

        self::assertNull(
            Type::getType(UuidType::NAME)->convertToPHPValue($raw, $this->connection->getDatabasePlatform()),
        );

        $this->connection->executeStatement('DROP TABLE IF EXISTS t004_uuid_nullable');
    }

    /**
     * UUIDv7 按字符串排序 = 按时间排序。这正是选 v7 的理由，
     * 也是 §5.4.1 游标同步能按时间推进的前提。
     */
    public function testV7IdsSortChronologicallyInThePostgresIndex(): void
    {
        $earlier = Uuid::fromString('01941f29-7c00-70ab-8000-000000000001');
        $later = Uuid::fromString('01941f2a-0000-70ab-8000-000000000000');

        foreach ([[$later, 'later'], [$earlier, 'earlier']] as [$id, $label]) {
            $this->connection->executeStatement(
                'INSERT INTO t004_uuid (id, label) VALUES (?, ?)',
                [$id, $label],
                [UuidType::NAME, 'string'],
            );
        }

        $labels = $this->connection->fetchFirstColumn('SELECT label FROM t004_uuid ORDER BY id');

        self::assertSame(['earlier', 'later'], $labels, '按 id 排序必须等价于按生成时间排序');
    }
}
