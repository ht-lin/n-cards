<?php

declare(strict_types=1);

namespace App\Tests\Integration\Module\Identity\Doctrine;

use App\Module\Identity\Domain\ValueObject\Locale;
use App\Module\Identity\Domain\ValueObject\UserStatus;
use App\Tests\Integration\Support\RequiresIdentitySchema;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\ParameterType;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * 迁移建出来的库层约束（§5.2 / §17.1）—— T-101 验收标准里「集成测试断言
 * `email_hash` 唯一约束与 `username` 唯一」的那一半。
 *
 * 这里全部用**裸 SQL**，刻意绕开 ORM：验的是数据库自己拦不拦得住，
 * 而不是应用层拦不拦得住。应用层那一半在 `DoctrineUserRepositoryTest`。
 */
final class IdentitySchemaTest extends KernelTestCase
{
    use RequiresIdentitySchema;

    protected function setUp(): void
    {
        $this->bootIdentitySchema();
    }

    protected function tearDown(): void
    {
        $this->rollbackIdentitySchema();

        parent::tearDown();
    }

    /**
     * §5.2 / T-101 实现要点：外键**显式命名** `fk_<table>_<column>`。
     *
     * 这条看着像洁癖，其实是运维接口：生产上 `ALTER TABLE ... DROP CONSTRAINT`
     * 要按名字来，而 Doctrine 自动生成的 `FK_<hash>` 在每次列变更后都可能变。
     *
     * @return iterable<string, array{string}>
     */
    public static function foreignKeyNames(): iterable
    {
        yield 'fk_devices_user_id' => ['fk_devices_user_id'];
        yield 'fk_sessions_user_id' => ['fk_sessions_user_id'];
        yield 'fk_sessions_device_id' => ['fk_sessions_device_id'];
    }

    #[DataProvider('foreignKeyNames')]
    public function testForeignKeysUseTheSpecifiedNames(string $name): void
    {
        $exists = $this->connection->fetchOne(
            "SELECT count(*) FROM pg_constraint WHERE conname = ? AND contype = 'f'",
            [$name],
        );

        self::assertSame(1, (int) $exists, $name.' 不存在或不是外键。');
    }

    public function testEmailHashIsUnique(): void
    {
        $hash = $this->insertUser('anna');

        $this->expectException(UniqueConstraintViolationException::class);

        $this->insertUser('bea', emailHash: $hash);
    }

    /**
     * §17.1：username 已在应用层归一化为小写，故普通 UNIQUE 即等价于
     * 大小写不敏感唯一。这条用例证明「同一个名字的两种写法」在库层是同一行 ——
     * 因为大写形态根本进不来（见下一条）。
     */
    public function testUsernameIsUnique(): void
    {
        $this->insertUser('anna', username: 'anna_b');

        $this->expectException(UniqueConstraintViolationException::class);

        $this->insertUser('bea', username: 'anna_b');
    }

    /**
     * 多行 `username IS NULL` 必须能共存 —— §5.2 的注册中间态。
     * Postgres 的 UNIQUE 不约束 NULL，这条把那个前提钉住：
     * 哪天有人给这一列加了 NOT NULL 或换成 `NULLS NOT DISTINCT`，注册就全挂了。
     */
    public function testManyUsersMayHaveNoUsername(): void
    {
        $this->insertUser('anna');
        $this->insertUser('bea');

        $count = $this->connection->fetchOne('SELECT count(*) FROM users WHERE username IS NULL');

        self::assertSame(2, (int) $count);
    }

    /**
     * §17.1 的 CHECK 是**第二道防线**：即使应用层（T-107 的 Username 值对象）有 bug，
     * 也绝不会写入大写或非法字符。
     *
     * @return iterable<string, array{string}>
     */
    public static function invalidUsernames(): iterable
    {
        yield '大写' => ['Anna_B'];
        yield '太短（2 字符）' => ['ab'];
        yield '太长（21 字符）' => [str_repeat('a', 21)];
        yield '连字符不在字符集里' => ['anna-b'];
        yield '点号不在字符集里' => ['anna.b'];
        yield '变音符号' => ['anna_müller'];
        yield '空格' => ['anna b'];
        yield '空串' => [''];
    }

    #[DataProvider('invalidUsernames')]
    public function testCheckConstraintRejectsInvalidUsernames(string $username): void
    {
        $this->expectExceptionMessageMatches('/chk_users_username_format/');

        $this->insertUser('anna', username: $username);
    }

    public function testCheckConstraintAcceptsTheBoundaryLengths(): void
    {
        $this->insertUser('anna', username: 'abc');
        $this->insertUser('bea', username: str_repeat('a', 20));
        $this->insertUser('cara', username: 'a_9');

        $count = $this->connection->fetchOne('SELECT count(*) FROM users WHERE username IS NOT NULL');

        self::assertSame(3, (int) $count);
    }

    public function testCheckConstraintRejectsAnUnknownLocale(): void
    {
        $this->expectExceptionMessageMatches('/chk_users_locale/');

        $this->insertUser('anna', locale: 'fr');
    }

    public function testCheckConstraintRejectsAnUnknownStatus(): void
    {
        $this->expectExceptionMessageMatches('/chk_users_status/');

        $this->insertUser('anna', status: 'deleted');
    }

    /**
     * ⚠️ 真正会漂的是「PHP enum 的取值」与「迁移里 CHECK 约束的取值」之间的关系 ——
     * 加一个 enum case 而忘了发迁移改约束，代码里一切正常，直到生产上第一次
     * 写入那个值才炸。所以每个 case 都真的往库里写一次。
     *
     * 单测里断言「enum 等于某个字面量数组」挡不住这件事（而且是同义反复，
     * PHPStan level 8 会直接报 `alreadyNarrowedType`）。
     *
     * @return iterable<string, array{Locale}>
     */
    public static function locales(): iterable
    {
        foreach (Locale::cases() as $locale) {
            yield $locale->value => [$locale];
        }
    }

    #[DataProvider('locales')]
    public function testEveryLocaleCaseIsAcceptedByTheCheckConstraint(Locale $locale): void
    {
        $this->insertUser('anna', locale: $locale->value);

        $stored = $this->connection->fetchOne('SELECT locale FROM users WHERE id = ?', [self::userId('anna')]);

        self::assertSame($locale->value, $stored);
    }

    /**
     * @return iterable<string, array{UserStatus}>
     */
    public static function userStatuses(): iterable
    {
        foreach (UserStatus::cases() as $status) {
            yield $status->value => [$status];
        }
    }

    #[DataProvider('userStatuses')]
    public function testEveryUserStatusCaseIsAcceptedByTheCheckConstraint(UserStatus $status): void
    {
        $this->insertUser('anna', status: $status->value);

        $stored = $this->connection->fetchOne('SELECT status FROM users WHERE id = ?', [self::userId('anna')]);

        self::assertSame($status->value, $stored);
    }

    /**
     * 这三列**刻意没有** CHECK 约束（§13.6：新增枚举值是向后兼容变更，
     * 加了 CHECK 就得为每个新值欠一次迁移）。这条用例把那个决定钉住 ——
     * 有人「顺手补全」约束的话，下一次加 enum case 就会在生产上炸。
     *
     * @return iterable<string, array{string, string}>
     */
    public static function columnsWithoutCheckConstraints(): iterable
    {
        yield 'devices.platform' => ['devices', 'platform'];
        yield 'otp_challenges.purpose' => ['otp_challenges', 'purpose'];
        yield 'sessions.revoked_reason' => ['sessions', 'revoked_reason'];
    }

    #[DataProvider('columnsWithoutCheckConstraints')]
    public function testOpenVocabularyColumnsHaveNoCheckConstraint(string $table, string $column): void
    {
        $constraints = $this->connection->fetchFirstColumn(
            <<<'SQL'
                SELECT pg_get_constraintdef(c.oid)
                FROM pg_constraint c
                WHERE c.conrelid = ?::regclass AND c.contype = 'c'
                SQL,
            [$table],
        );

        $mentioning = array_values(array_filter(
            $constraints,
            static fn (mixed $definition): bool => str_contains((string) $definition, $column),
        ));

        self::assertSame(
            [],
            $mentioning,
            \sprintf('%s.%s 不该有 CHECK 约束 —— 见本用例的注释。', $table, $column),
        );
    }

    /**
     * §5.2：所有表带 `created_at TIMESTAMPTZ NOT NULL DEFAULT now()`。
     *
     * @return iterable<string, array{string}>
     */
    public static function tablesWithCreatedAt(): iterable
    {
        yield 'users' => ['users'];
        yield 'otp_challenges' => ['otp_challenges'];
        yield 'devices' => ['devices'];
        yield 'sessions' => ['sessions'];
    }

    #[DataProvider('tablesWithCreatedAt')]
    public function testEveryTableDefaultsCreatedAtToNow(string $table): void
    {
        $default = $this->connection->fetchOne(
            <<<'SQL'
                SELECT column_default
                FROM information_schema.columns
                WHERE table_name = ? AND column_name = 'created_at'
                SQL,
            [$table],
        );

        self::assertSame('now()', $default);
    }

    /**
     * ⚠️ `otp_challenges` **没有**到 users 的外键 —— §3.8 的哑挑战是给不存在的
     * 邮箱建的，有外键就插不进去。这条用例把那个「缺失」变成一条显式断言，
     * 免得将来有人「顺手补全」外键，把整套防枚举拆掉。
     */
    public function testOtpChallengesHasNoForeignKeyToUsers(): void
    {
        $count = $this->connection->fetchOne(
            <<<'SQL'
                SELECT count(*)
                FROM pg_constraint
                WHERE conrelid = 'otp_challenges'::regclass AND contype = 'f'
                SQL,
        );

        self::assertSame(0, (int) $count);
    }

    public function testDeletingAUserCascadesToDevicesAndSessions(): void
    {
        $this->insertUser('anna');
        $this->insertDevice();
        $this->insertSession();

        $this->connection->executeStatement('DELETE FROM users WHERE id = ?', [self::userId('anna')]);

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM devices'));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM sessions'));
    }

    public function testDeletingADeviceCascadesToItsSessions(): void
    {
        $this->insertUser('anna');
        $this->insertDevice();
        $this->insertSession();

        $this->connection->executeStatement('DELETE FROM devices WHERE id = ?', [self::deviceId()]);

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM sessions'));
        // 用户本身不受影响 —— 级联是单向的。
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM users'));
    }

    public function testSessionCannotReferenceAMissingUser(): void
    {
        $this->expectException(ForeignKeyConstraintViolationException::class);

        $this->insertSession();
    }

    public function testRefreshTokenHashIsUnique(): void
    {
        $this->insertUser('anna');
        $this->insertDevice();
        $this->insertSession();

        $this->expectException(UniqueConstraintViolationException::class);

        $this->insertSession(id: '01941f29-7c00-70ab-8000-000000000099');
    }

    // ========================================================================
    // 裸 SQL 的插入助手 —— 刻意不走仓储，见类注释。
    // ========================================================================

    /** 每个 seed 一个稳定的 id，好让同一条用例里插两个用户不撞主键。 */
    private static function userId(string $seed): string
    {
        return \sprintf('01941f29-7c00-70ab-8000-%012d', crc32($seed) % 1_000_000_000);
    }

    private static function deviceId(): string
    {
        return '01941f29-7c00-70ab-8000-000000000004';
    }

    /**
     * @param string|null $emailHash 32 字节裸摘要；默认由 $seed 派生
     */
    private function insertUser(
        string $seed,
        ?string $emailHash = null,
        ?string $username = null,
        string $locale = 'de',
        string $status = 'active',
    ): string {
        $emailHash ??= hash('sha256', $seed, true);

        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO users (id, email_hash, email_encrypted, username, locale, status)
                VALUES (?, ?, ?, ?, ?, ?)
                SQL,
            [
                self::userId($seed),
                $emailHash,
                'vault:v1:'.base64_encode($seed),
                $username,
                $locale,
                $status,
            ],
            [
                ParameterType::STRING,
                ParameterType::BINARY,
                ParameterType::STRING,
                ParameterType::STRING,
                ParameterType::STRING,
                ParameterType::STRING,
            ],
        );

        return $emailHash;
    }

    private function insertDevice(): void
    {
        $this->connection->executeStatement(
            'INSERT INTO devices (id, user_id, platform) VALUES (?, ?, ?)',
            [self::deviceId(), self::userId('anna'), 'android'],
        );
    }

    private function insertSession(string $id = '01941f29-7c00-70ab-8000-000000000005'): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO sessions (id, user_id, device_id, refresh_token_hash, expires_at)
                VALUES (?, ?, ?, ?, now() + interval '90 days')
                SQL,
            [
                $id,
                self::userId('anna'),
                self::deviceId(),
                hash('sha256', 'refresh-1', true),
            ],
            [
                ParameterType::STRING,
                ParameterType::STRING,
                ParameterType::STRING,
                ParameterType::BINARY,
            ],
        );
    }
}
