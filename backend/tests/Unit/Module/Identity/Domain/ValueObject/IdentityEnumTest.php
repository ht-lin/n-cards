<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Identity\Domain\ValueObject;

use App\Module\Identity\Domain\ValueObject\Locale;
use App\Module\Identity\Domain\ValueObject\SessionRevokedReason;
use App\Module\Identity\Domain\ValueObject\UserStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Identity 闭合词表里**有行为**的那部分。
 *
 * ⚠️ 这里刻意**不**断言「enum 的取值域等于某个字面量数组」。那种断言是同义反复：
 * 两边都是源码里的字面量，PHPStan level 8 会直接判定 `will always evaluate to true`
 * 并报错（`staticMethod.alreadyNarrowedType`），而且它挡不住任何真实的漂移 ——
 * 真正会漂的是「enum 的值」与「迁移里 CHECK 约束的值」之间的关系。
 *
 * 那件事在 `tests/Integration/Module/Identity/Doctrine/IdentitySchemaTest` 里验：
 * 每个 case 都真的往库里写一次，写不进去就是 enum 与迁移不同步。
 */
#[CoversClass(Locale::class)]
#[CoversClass(UserStatus::class)]
#[CoversClass(SessionRevokedReason::class)]
final class IdentityEnumTest extends TestCase
{
    /**
     * §17.1：`locale TEXT NOT NULL DEFAULT 'de'`。
     * 默认值必须与迁移一致 —— 不一致的话新注册用户会拿到德语界面而库里写着英语。
     */
    public function testLocaleDefaultMatchesTheColumnDefault(): void
    {
        self::assertSame('de', Locale::default()->value);
    }

    /**
     * §17.1：`status TEXT NOT NULL DEFAULT 'active'`。
     */
    public function testUserStatusDefaultMatchesTheColumnDefault(): void
    {
        self::assertSame('active', UserStatus::default()->value);
    }

    /**
     * @return iterable<string, array{SessionRevokedReason, bool}>
     */
    public static function revocationReasons(): iterable
    {
        yield 'logout 不是安全事件' => [SessionRevokedReason::Logout, false];
        yield 'user_revoked 不是安全事件' => [SessionRevokedReason::UserRevoked, false];
        yield 'account_deleted 不是安全事件' => [SessionRevokedReason::AccountDeleted, false];
        // 四个里只有这一个要触发告警 + 安全提醒邮件（§7.1）。
        yield 'reuse_detected 是安全事件' => [SessionRevokedReason::ReuseDetected, true];
    }

    /**
     * ⚠️ 这条断言的价值不在「reuse_detected 是 true」，而在**覆盖全部四个 case**：
     * `isSecurityIncident()` 的 `match` 没有 `default` 分支，将来加第五个取值时，
     * 这个 provider 会漏掉它 —— 而漏掉的那个一被碰到就是 `\UnhandledMatchError`。
     */
    #[DataProvider('revocationReasons')]
    public function testOnlyTokenReuseCountsAsASecurityIncident(
        SessionRevokedReason $reason,
        bool $expected,
    ): void {
        self::assertSame($expected, $reason->isSecurityIncident());
    }

    /**
     * 上一条 provider 必须穷举 —— 少写一个 case 就等于少测一个分支。
     */
    public function testEveryRevocationReasonIsCoveredByTheProvider(): void
    {
        $covered = array_map(
            static fn (array $row): SessionRevokedReason => $row[0],
            iterator_to_array(self::revocationReasons()),
        );

        self::assertCount(\count(SessionRevokedReason::cases()), array_unique($covered, \SORT_REGULAR));
    }
}
