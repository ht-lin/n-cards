<?php

declare(strict_types=1);

namespace App\Tests\Integration\Module\Identity\Doctrine;

use App\Module\Identity\Domain\Entity\User;
use App\Module\Identity\Domain\Repository\UserRepositoryInterface;
use App\Module\Identity\Domain\ValueObject\Locale;
use App\Module\Identity\Domain\ValueObject\UserStatus;
use App\Module\Identity\Infrastructure\Doctrine\DoctrineUserRepository;
use App\Shared\Domain\Crypto\HashDigest;
use App\Shared\Domain\Error\DomainException;
use App\Shared\Domain\Error\ErrorCode;
use App\Tests\Double\Identity\IdentityEntities;
use App\Tests\Integration\Support\RequiresIdentitySchema;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * `users` 经 ORM 的真实往返 —— T-101 验收标准里「集成测试断言 `email_hash` 唯一约束
 * 与 `username` 唯一（大小写不敏感，存归一化小写值）」的应用层那一半。
 *
 * ⚠️ 这里验的不只是「能存能取」，更是**三个自定义 DBAL 类型在真库上的行为**：
 * `HashDigest` ↔ BYTEA（pdo_pgsql 读回来是 stream，单测喂字符串看不出来）、
 * `Ciphertext` ↔ TEXT、以及两个 PHP enum ↔ TEXT。
 */
#[CoversClass(DoctrineUserRepository::class)]
final class DoctrineUserRepositoryTest extends KernelTestCase
{
    use RequiresIdentitySchema;

    private UserRepositoryInterface $repository;

    /**
     * ⚠️ 直接 `new`，不从容器取。
     *
     * T-101 还没有任何消费者（第一个是 T-104 的 VerifyOtp handler），于是这几个
     * 仓储服务是**未被引用的私有服务**，Symfony 在 `RemoveUnusedDefinitionsPass`
     * 里正确地把它们删掉了 —— `getContainer()->get(...)` 会报
     * 「has been removed or inlined」。
     *
     * 为了能从容器取而造一个假消费者是本末倒置：接口 → 实现的自动别名会在
     * 第一个真实消费者出现时被真正验证（autowiring 找不到实现就编译失败）。
     * 这里要验的是**持久化行为**，`new` 一个就够了。
     */
    protected function setUp(): void
    {
        $this->bootIdentitySchema();

        $this->repository = new DoctrineUserRepository($this->entityManager);
    }

    protected function tearDown(): void
    {
        $this->rollbackIdentitySchema();

        parent::tearDown();
    }

    /**
     * §5.2 的注册中间态在**真库**上再确认一次：首次验证建出来的行 `username IS NULL`。
     * T-104 的验收标准会引用同一条事实。
     */
    public function testRoundTripsANewlyRegisteredUser(): void
    {
        $id = IdentityEntities::id(1);
        $emailHash = IdentityEntities::digest('anna@example.de');
        $emailEncrypted = IdentityEntities::ciphertext('YW5uYUBleGFtcGxlLmRl');
        $now = IdentityEntities::now();

        $this->repository->save(
            IdentityEntities::user($id, $emailHash, $emailEncrypted, Locale::English, $now),
        );

        // clear() 强制从库里重新水化，而不是从 UnitOfWork 的身份映射里拿回同一个对象 ——
        // 不清的话这条用例什么持久化行为都没验到。
        $this->entityManager->clear();

        $found = $this->repository->findById($id);

        self::assertInstanceOf(User::class, $found);
        self::assertNull($found->username());
        self::assertFalse($found->hasUsername());
        self::assertSame(UserStatus::Active, $found->status());
        self::assertSame(Locale::English, $found->locale());
        // BYTEA 往返：库里读回来的是 stream，HashDigestType 负责收口。
        self::assertTrue($emailHash->equals($found->emailHash()));
        // TEXT 往返：读回来必须还是 Ciphertext，不是裸 string。
        self::assertTrue($emailEncrypted->equals($found->emailEncrypted()));
        self::assertEquals($now, $found->createdAt());
    }

    /**
     * 摘要里出现 0x00 的概率约 12%。绑定类型不是 BINARY 的话，
     * pdo_pgsql 会在第一个 0x00 处截断 —— 大约每八条摘要坏一条，且不报错。
     */
    public function testRoundTripsADigestContainingNullBytes(): void
    {
        // 刻意手写而不是 digest('...')：真实摘要含不含 0x00 是随机的
        // （约 12% 概率），用例不能靠运气。0x00 放在开头、中间与结尾各一个。
        $emailHash = HashDigest::fromRaw("\x00".str_repeat("\x41", 15)."\x00".str_repeat("\x42", 14)."\x00");

        $this->repository->save(IdentityEntities::user(emailHash: $emailHash));
        $this->entityManager->clear();

        $found = $this->repository->findByEmailHash($emailHash);

        self::assertInstanceOf(User::class, $found);
        self::assertSame(32, \strlen($found->emailHash()->toRaw()));
        self::assertTrue($emailHash->equals($found->emailHash()));
    }

    /**
     * §3.8 下唯一能按邮箱找人的方式 —— 库里没有明文邮箱列。
     */
    public function testFindsByEmailHash(): void
    {
        $emailHash = IdentityEntities::digest('anna@example.de');
        $this->repository->save(IdentityEntities::user(emailHash: $emailHash));
        $this->entityManager->clear();

        self::assertInstanceOf(User::class, $this->repository->findByEmailHash($emailHash));
        self::assertNull($this->repository->findByEmailHash(IdentityEntities::digest('bea@example.de')));
    }

    public function testRejectsADuplicateEmailHash(): void
    {
        $emailHash = IdentityEntities::digest('anna@example.de');
        $this->repository->save(IdentityEntities::user(IdentityEntities::id(1), $emailHash));

        $this->expectException(UniqueConstraintViolationException::class);

        $this->repository->save(IdentityEntities::user(IdentityEntities::id(2), $emailHash));
    }

    public function testFindsByUsername(): void
    {
        $user = IdentityEntities::user();
        $user->assignUsername('anna_b', IdentityEntities::now());
        $this->repository->save($user);
        $this->entityManager->clear();

        $found = $this->repository->findByUsername('anna_b');

        self::assertInstanceOf(User::class, $found);
        self::assertSame('anna_b', $found->username());
    }

    /**
     * ⚠️ 大小写不敏感是靠「**只存归一化小写值** + 普通 UNIQUE」达成的（§17.1），
     * **不是**靠函数索引或 `ILIKE`。所以查找方必须先归一化 ——
     * 这条用例把那个前提钉成显式约定：传大写进来查不到，是**正确**行为。
     *
     * 归一化本身归 T-107 的 `Username` 值对象（`trim` + `Locale.ROOT` 小写）。
     */
    public function testLookupRequiresTheCallerToNormaliseFirst(): void
    {
        $user = IdentityEntities::user();
        $user->assignUsername('anna_b', IdentityEntities::now());
        $this->repository->save($user);
        $this->entityManager->clear();

        self::assertNull($this->repository->findByUsername('Anna_B'));
    }

    /**
     * 归一化之后，`Anna_B ` 与 `anna_b` 是同一个名字 —— 第二次写入必须撞唯一约束。
     * 这是 T-107 `409 username_taken` 的库层依据。
     */
    public function testRejectsADuplicateNormalisedUsername(): void
    {
        $first = IdentityEntities::user(IdentityEntities::id(1), IdentityEntities::digest('anna'));
        $first->assignUsername('anna_b', IdentityEntities::now());
        $this->repository->save($first);

        $second = IdentityEntities::user(IdentityEntities::id(2), IdentityEntities::digest('bea'));
        // T-107 的值对象会把 "Anna_B " 归一化成这个；这里直接给归一化后的形态。
        $second->assignUsername(strtolower(trim('Anna_B ')), IdentityEntities::now());

        $this->expectException(UniqueConstraintViolationException::class);

        $this->repository->save($second);
    }

    // ========================================================================
    // saveNewUsername()：把 uq_users_username 翻译成 409（T-107）
    // ========================================================================

    public function testSaveNewUsernamePersistsTheNameAndTheAttemptCounter(): void
    {
        $user = IdentityEntities::user();
        $user->recordUsernameAttempt(10);
        $user->assignUsername('anna_b', IdentityEntities::now());

        $this->repository->saveNewUsername($user);
        $this->entityManager->clear();

        $reloaded = $this->repository->findById($user->id());

        self::assertInstanceOf(User::class, $reloaded);
        self::assertSame('anna_b', $reloaded->username());
        self::assertSame(1, $reloaded->usernameAttempts());
    }

    /**
     * ⚠️ 这是 T-107 真正的防线：`findByUsername()` 预查有 TOCTOU，
     * 两个并发请求可以同时通过它，唯一索引才是最后拦下来的那一道。
     *
     * 而**翻译**必须发生在仓储里 —— deptrac 只允许 Infrastructure 看见 Doctrine，
     * Application 层接不住 `UniqueConstraintViolationException`。
     */
    public function testSaveNewUsernameTranslatesADuplicateIntoUsernameTaken(): void
    {
        $first = IdentityEntities::user(IdentityEntities::id(1), IdentityEntities::digest('anna'));
        $first->assignUsername('anna_b', IdentityEntities::now());
        $this->repository->save($first);

        $second = IdentityEntities::user(IdentityEntities::id(2), IdentityEntities::digest('bea'));
        $second->assignUsername('anna_b', IdentityEntities::now());

        try {
            $this->repository->saveNewUsername($second);
            self::fail('Expected DomainException.');
        } catch (DomainException $e) {
            self::assertSame(ErrorCode::UsernameTaken, $e->errorCode());
            self::assertSame(409, $e->errorCode()->httpStatus());
            // detail 里**不放**那个 username（§3.8-C4）—— 它会进日志与 Sentry。
            self::assertStringNotContainsString('anna_b', $e->detail());
            // 原异常仍挂在 previous 上供日志取用。
            self::assertInstanceOf(UniqueConstraintViolationException::class, $e->getPrevious());
        }
    }

    /**
     * ⚠️ **只翻译 `uq_users_username`。** `uq_users_email_hash` 的冲突是
     * T-104 的并发注册问题，与本卡无关 —— 翻成一个 409 只会让它更难查，
     * 所以它必须原样冒泡（在 Http 层变成 500）。
     */
    public function testSaveNewUsernameDoesNotSwallowOtherUniqueViolations(): void
    {
        $hash = IdentityEntities::digest('anna');
        $this->repository->save(IdentityEntities::user(IdentityEntities::id(1), $hash));

        $collidingOnEmail = IdentityEntities::user(IdentityEntities::id(2), $hash);
        $collidingOnEmail->assignUsername('anna_b', IdentityEntities::now());

        $this->expectException(UniqueConstraintViolationException::class);

        $this->repository->saveNewUsername($collidingOnEmail);
    }

    /**
     * `save()` 的既有契约不变 —— 它仍然抛裸异常。
     *
     * 上面 `testRejectsADuplicateNormalisedUsername()` 已经断言了这一点；
     * 这条从反面再钉一次：翻译是 `saveNewUsername()` **独有**的行为，
     * 有人图省事把 try/catch 挪进 `save()` 时两边一起红。
     */
    public function testSaveStillThrowsTheRawDriverException(): void
    {
        $first = IdentityEntities::user(IdentityEntities::id(1), IdentityEntities::digest('anna'));
        $first->assignUsername('anna_b', IdentityEntities::now());
        $this->repository->save($first);

        $second = IdentityEntities::user(IdentityEntities::id(2), IdentityEntities::digest('bea'));
        $second->assignUsername('anna_b', IdentityEntities::now());

        $this->expectException(UniqueConstraintViolationException::class);

        $this->repository->save($second);
    }

    /**
     * 库层的 CHECK 是第二道防线：实体刻意不校验格式（那归 T-107），
     * 所以一个绕过值对象的写入必须在这里被拦下。
     */
    public function testTheDatabaseRejectsANonNormalisedUsername(): void
    {
        $user = IdentityEntities::user();
        $user->assignUsername('Anna_B', IdentityEntities::now());

        $this->expectExceptionMessageMatches('/chk_users_username_format/');

        $this->repository->save($user);
    }

    public function testPersistsMutationsOfAManagedUser(): void
    {
        $id = IdentityEntities::id(1);
        $this->repository->save(IdentityEntities::user($id));
        $this->entityManager->clear();

        $user = $this->repository->findById($id);
        self::assertInstanceOf(User::class, $user);

        $requestedAt = IdentityEntities::now('2026-10-01T12:00:00+00:00');
        $user->assignUsername('anna_b', $requestedAt);
        $user->changeLocale(Locale::English, $requestedAt);
        $user->requestDeletion($requestedAt);
        $this->repository->save($user);
        $this->entityManager->clear();

        $reloaded = $this->repository->findById($id);

        self::assertInstanceOf(User::class, $reloaded);
        self::assertSame('anna_b', $reloaded->username());
        self::assertSame(Locale::English, $reloaded->locale());
        self::assertSame(UserStatus::PendingDeletion, $reloaded->status());
        self::assertEquals($requestedAt, $reloaded->deletionRequestedAt());
        self::assertEquals($requestedAt, $reloaded->updatedAt());
    }

    public function testReturnsNullForAnUnknownId(): void
    {
        self::assertNull($this->repository->findById(IdentityEntities::id(999)));
    }

    // ========================================================================
    // deleteZombieRegistrationsBefore()：僵尸注册行的物删（T-113）
    // ========================================================================

    /**
     * 边界：`created_at < :cutoff`，严格小于（§5.2 的 `now() - 7 days`）。
     *
     * ⚠️ 这条必须打真库：判据里有 `username IS NULL`，而 NULL 在 SQL 里的比较
     * 语义与 PHP 的 `null ===` 不是一回事 —— 写成 `u.username = :null` 之类的话
     * 一行都匹配不上，症状是「僵尸行永远不被清理」，而替身上完全看不出来。
     */
    public function testDeletesOnlyZombiesStrictlyOlderThanTheCutoff(): void
    {
        $now = IdentityEntities::now('2026-09-11T04:30:00+00:00');
        $cutoff = $now->modify('-7 days');

        $this->repository->save(IdentityEntities::user(
            id: IdentityEntities::id(1),
            emailHash: IdentityEntities::digest('one-second-past'),
            now: $cutoff->modify('-1 second'),
        ));
        $this->repository->save(IdentityEntities::user(
            id: IdentityEntities::id(2),
            emailHash: IdentityEntities::digest('exactly-at-the-boundary'),
            now: $cutoff,
        ));
        $this->entityManager->clear();

        self::assertSame(1, $this->repository->deleteZombieRegistrationsBefore($cutoff));

        $this->entityManager->clear();
        self::assertNull($this->repository->findById(IdentityEntities::id(1)));
        self::assertInstanceOf(User::class, $this->repository->findById(IdentityEntities::id(2)));
    }

    /**
     * ⚠️ **红了就是删号事故。** 设过 username 的用户不是僵尸行，无论多老 ——
     * §8.2 给「身份」的保留期是「账号存续期 + 30 天宽限」，而删掉一个活跃账号
     * 没有任何流程能挽回。
     */
    public function testNeverDeletesAUserWithAUsername(): void
    {
        $old = IdentityEntities::now('2020-01-01T00:00:00+00:00');

        $veteran = IdentityEntities::user(id: IdentityEntities::id(1), now: $old);
        $veteran->assignUsername('anna_b', $old);
        $this->repository->save($veteran);
        $this->entityManager->clear();

        self::assertSame(0, $this->repository->deleteZombieRegistrationsBefore(
            IdentityEntities::now('2026-09-11T04:30:00+00:00'),
        ));

        $this->entityManager->clear();
        self::assertInstanceOf(User::class, $this->repository->findById(IdentityEntities::id(1)));
    }

    /**
     * ⚠️ **这条是「为什么批量 DQL 是安全的」的全部依据。**.
     *
     * 批量 DQL DELETE 绕过 UnitOfWork，Doctrine 的 cascade 配置一行都不生效。
     * `devices` / `sessions` 跟着消失，靠的是 `Version20260905101500.php` 里那两条
     * `ON DELETE CASCADE` 外键 —— 也就是**库**在做级联。
     *
     * 少了这条断言，「改成逐条 remove() 更安全」会是一个看起来很有道理、
     * 实际只是把一条语句换成 N+1 的改动；而如果哪天有人把外键的 on-delete 改了，
     * 僵尸行清理会开始报外键错误，也只有这条用例说得出原因。
     */
    public function testCascadesToDevicesAndSessionsThroughTheDatabase(): void
    {
        $now = IdentityEntities::now('2026-09-01T00:00:00+00:00');

        $zombie = IdentityEntities::user(id: IdentityEntities::id(1), now: $now);
        $device = IdentityEntities::device(user: $zombie, id: IdentityEntities::id(2), now: $now);
        $session = IdentityEntities::session(user: $zombie, device: $device, id: IdentityEntities::id(3), now: $now);

        $this->entityManager->persist($zombie);
        $this->entityManager->persist($device);
        $this->entityManager->persist($session);
        $this->entityManager->flush();
        $this->entityManager->clear();

        self::assertSame(1, $this->repository->deleteZombieRegistrationsBefore(
            IdentityEntities::now('2026-09-11T04:30:00+00:00'),
        ));

        // 直接查库而不是经 ORM：UnitOfWork 对批量 DELETE 一无所知，
        // 经 ORM 查可能读到身份映射里的缓存实体。
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM devices'));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM sessions'));
    }
}
