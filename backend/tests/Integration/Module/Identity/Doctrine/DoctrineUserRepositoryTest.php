<?php

declare(strict_types=1);

namespace App\Tests\Integration\Module\Identity\Doctrine;

use App\Module\Identity\Domain\Entity\User;
use App\Module\Identity\Domain\Repository\UserRepositoryInterface;
use App\Module\Identity\Domain\ValueObject\Locale;
use App\Module\Identity\Domain\ValueObject\UserStatus;
use App\Module\Identity\Infrastructure\Doctrine\DoctrineUserRepository;
use App\Shared\Domain\Crypto\HashDigest;
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
}
