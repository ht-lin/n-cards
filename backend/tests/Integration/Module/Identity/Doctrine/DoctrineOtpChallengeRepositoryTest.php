<?php

declare(strict_types=1);

namespace App\Tests\Integration\Module\Identity\Doctrine;

use App\Module\Identity\Domain\Entity\OtpChallenge;
use App\Module\Identity\Domain\Repository\OtpChallengeRepositoryInterface;
use App\Module\Identity\Domain\ValueObject\Locale;
use App\Module\Identity\Domain\ValueObject\OtpPurpose;
use App\Module\Identity\Infrastructure\Doctrine\DoctrineOtpChallengeRepository;
use App\Shared\Domain\Crypto\HashDigest;
use App\Tests\Double\Identity\IdentityEntities;
use App\Tests\Integration\Support\RequiresIdentitySchema;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * `otp_challenges` 经 ORM 的真实往返，重点是 T-103 新加的
 * {@see OtpChallengeRepositoryInterface::invalidateActiveFor()}。
 *
 * ============================================================================
 * 为什么这条**必须**打真库
 * ============================================================================
 * 它是一条批量 DQL UPDATE，而 DQL 的 WHERE 有三个条件要同时成立
 * （email_hash 匹配、未消费、未过期）。进程内的替身按 PHP 逻辑遍历，
 * 验的是「我以为的语义」；DQL 到 SQL 的翻译对不对，只有真 Postgres 能说。
 *
 * 特别是 `email_hash` 那一条：它是 BYTEA，参数必须以 `hash_digest` 类型绑定。
 * 绑错的话不会报错，而是**一行都匹配不上** —— 症状是旧挑战永远不被作废，
 * 用户手里同时有好几个有效的码，§7.1 给的爆破预算（5 次）当场翻倍。
 * 这种失败在替身上一定看不见。
 */
#[CoversClass(DoctrineOtpChallengeRepository::class)]
final class DoctrineOtpChallengeRepositoryTest extends KernelTestCase
{
    use RequiresIdentitySchema;

    private OtpChallengeRepositoryInterface $repository;

    /**
     * 直接 `new` 而不从容器取，理由见 {@see DoctrineUserRepositoryTest} 的 setUp 注释。
     */
    protected function setUp(): void
    {
        $this->bootIdentitySchema();

        $this->repository = new DoctrineOtpChallengeRepository($this->entityManager);
    }

    protected function tearDown(): void
    {
        $this->rollbackIdentitySchema();

        parent::tearDown();
    }

    public function testRoundTripsAnIssuedChallenge(): void
    {
        $now = IdentityEntities::now();
        $challenge = $this->issue('anna', $now);

        $this->repository->save($challenge);
        $this->entityManager->clear();

        $loaded = $this->repository->findById($challenge->id());

        self::assertNotNull($loaded);
        self::assertFalse($loaded->isDecoy());
        // `purpose` 现在只有 Login 一个取值，断言它是恒真的（PHPStan 会直接报出来）。
        // 等 T-106 加了第二个 purpose，这里该补一条真正的往返断言。
        self::assertSame(0, $loaded->attempts());
        self::assertNull($loaded->consumedAt());
        // BYTEA 经 pdo_pgsql 读回来是 stream，HashDigestType 负责收口 ——
        // 单测喂字符串看不出这一层。
        self::assertTrue($loaded->emailHash()->equals(IdentityEntities::digest('anna')));
        self::assertTrue($loaded->codeHash()->equals(IdentityEntities::digest('anna-code')));
        self::assertNotNull($loaded->requestIpHash());
    }

    /**
     * T-104 新加的两列 —— **注册路径的全部输入**。
     *
     * `email_encrypted` 走 `CiphertextType`（TEXT），`locale` 走带 `enum-type`
     * 的 TEXT。两者都是「单测喂什么读回什么、真库经过一次类型转换」的形状，
     * 而这条往返正是那层转换的唯一检验。
     *
     * 这条红了的症状是「新用户能收到码、能验过，但注册失败」——
     * 因为 `VerifyOtpService::register()` 读到 null 就返回 401。
     */
    public function testRoundTripsTheRecipientAndLocaleThatRegistrationNeeds(): void
    {
        $challenge = OtpChallenge::issue(
            IdentityEntities::id(91),
            IdentityEntities::digest('anna'),
            IdentityEntities::ciphertext(),
            Locale::English,
            IdentityEntities::digest('anna-code'),
            OtpPurpose::Login,
            IdentityEntities::now()->modify('+10 minutes'),
            null,
            null,
            IdentityEntities::now(),
        );

        $this->repository->save($challenge);
        $this->entityManager->clear();

        $loaded = $this->repository->findById($challenge->id());

        self::assertNotNull($loaded);
        self::assertSame(IdentityEntities::ciphertext()->toString(), $loaded->emailEncrypted()?->toString());
        self::assertSame(Locale::English, $loaded->locale());
    }

    /**
     * 两列都可空，且**这不是过渡态**（见 Version20260906120000 的注释）：
     * 哑挑战天然没有收件人，而本次迁移之前建的行也没有。
     *
     * 读侧（`VerifyOtpService::register()`）对 null 显式返回 401 ——
     * 所以库层必须真的允许它，否则那条分支根本走不到，却仍然要为覆盖率买单。
     */
    public function testBothNewColumnsAreNullableForLegacyAndDecoyRows(): void
    {
        $decoy = OtpChallenge::decoy(
            IdentityEntities::id(92),
            IdentityEntities::digest('legacy'),
            IdentityEntities::digest('legacy-code'),
            OtpPurpose::Login,
            IdentityEntities::now()->modify('+10 minutes'),
            null,
            IdentityEntities::now(),
        );

        $this->repository->save($decoy);
        $this->entityManager->clear();

        $loaded = $this->repository->findById($decoy->id());

        self::assertNotNull($loaded);
        self::assertNull($loaded->emailEncrypted());
        self::assertNull($loaded->locale());
    }

    /**
     * `chk_otp_challenges_locale` 挡住取值域之外的东西。
     *
     * ⚠️ 走裸 SQL 而不是实体：PHP 侧的 enum 根本造不出一个非法值，
     * 而这条约束防的正是「绕过 ORM 的写入」（运维手工修数据、将来的批量导入）。
     */
    public function testTheLocaleCheckConstraintRejectsAnUnknownValue(): void
    {
        $this->expectException(\Doctrine\DBAL\Exception::class);

        $this->entityManager->getConnection()->executeStatement(
            'INSERT INTO otp_challenges (id, email_hash, code_hash, purpose, expires_at, locale)'
            ." VALUES (?, ?, ?, 'login', now(), 'fr')",
            [
                IdentityEntities::id(93)->toString(),
                IdentityEntities::digest('fr')->toRaw(),
                IdentityEntities::digest('fr-code')->toRaw(),
            ],
        );
    }

    /**
     * §3.8 的承重墙在库层的形态：`otp_challenges` **没有**到 `users` 的外键，
     * 所以给一个根本不存在的用户插哑挑战必须成功。
     * 有外键的话这一行会违反引用完整性，整套防枚举就无从落地。
     */
    public function testStoresADecoyForAnEmailThatBelongsToNoUser(): void
    {
        $now = IdentityEntities::now();

        $decoy = OtpChallenge::decoy(
            IdentityEntities::id(90),
            IdentityEntities::digest('niemand@example.de'),
            IdentityEntities::digest('niemand-code'),
            OtpPurpose::Login,
            $now->modify('+10 minutes'),
            IdentityEntities::digest('ip'),
            $now,
        );

        $this->repository->save($decoy);
        $this->entityManager->clear();

        $loaded = $this->repository->findById($decoy->id());

        self::assertNotNull($loaded);
        self::assertTrue($loaded->isDecoy());
    }

    // ========================================================================
    // invalidateActiveFor()
    // ========================================================================

    public function testInvalidatesTheActiveChallengesOfThatEmail(): void
    {
        $now = IdentityEntities::now();

        $first = $this->issue('anna', $now, nth: 1);
        $second = $this->issue('anna', $now, nth: 2);

        $this->repository->save($first);
        $this->repository->save($second);

        self::assertSame(2, $this->repository->invalidateActiveFor(IdentityEntities::digest('anna'), $now));

        // 批量 DQL 绕过 UnitOfWork —— 不 clear 的话读到的是内存里的旧状态，
        // 这条断言会假绿。
        $this->entityManager->clear();

        self::assertTrue($this->reload($first)->isConsumed());
        self::assertTrue($this->reload($second)->isConsumed());
    }

    /**
     * ⚠️ 最要紧的一条：**别人的挑战不能被误伤**。
     * `email_hash` 是 BYTEA，参数类型绑错的症状是「一行都不匹配」或
     * 「全表都匹配」，两种都会被这条抓住。
     */
    public function testLeavesOtherEmailsAlone(): void
    {
        $now = IdentityEntities::now();

        $anna = $this->issue('anna', $now, nth: 1);
        $bob = $this->issue('bob', $now, nth: 2);

        $this->repository->save($anna);
        $this->repository->save($bob);

        self::assertSame(1, $this->repository->invalidateActiveFor(IdentityEntities::digest('anna'), $now));

        $this->entityManager->clear();

        self::assertTrue($this->reload($anna)->isConsumed());
        self::assertFalse($this->reload($bob)->isConsumed(), "Bob's challenge must survive.");
    }

    /**
     * 已消费的不再动：`consumed_at` 是「什么时候失效的」，
     * 重写它会把 T-104 的审计线索抹掉。
     */
    public function testDoesNotTouchAlreadyConsumedChallenges(): void
    {
        $now = IdentityEntities::now();
        $consumedAt = $now->modify('-1 minute');

        $challenge = $this->issue('anna', $now);
        $challenge->consume($consumedAt);

        $this->repository->save($challenge);

        self::assertSame(0, $this->repository->invalidateActiveFor(IdentityEntities::digest('anna'), $now));

        $this->entityManager->clear();

        self::assertEquals($consumedAt, $this->reload($challenge)->consumedAt());
    }

    /**
     * 已过期的也不动 —— 它们已经死了，多写一次 UPDATE 只是白白锁行。
     * T-113 的清理任务会把它们删掉。
     */
    public function testDoesNotTouchExpiredChallenges(): void
    {
        $now = IdentityEntities::now();

        // 有效期已经过去 1 秒
        $expired = OtpChallenge::issue(
            IdentityEntities::id(7),
            IdentityEntities::digest('anna'),
            IdentityEntities::ciphertext(),
            Locale::German,
            IdentityEntities::digest('anna-code'),
            OtpPurpose::Login,
            $now->modify('-1 second'),
            null,
            IdentityEntities::digest('ip'),
            $now->modify('-10 minutes'),
        );

        $this->repository->save($expired);

        self::assertSame(0, $this->repository->invalidateActiveFor(IdentityEntities::digest('anna'), $now));

        $this->entityManager->clear();

        self::assertFalse($this->reload($expired)->isConsumed());
    }

    /**
     * decoy 与真实挑战一视同仁。
     *
     * ADR-0014 之后已无生产写入方（`RequestOtpService` 恒建真实挑战），
     * 但库里还有上个版本留下的哑挑战 —— 它们同样要能被作废，
     * 否则那个邮箱的下一次登录会撞上「已有活跃挑战」。
     */
    public function testInvalidatesDecoysToo(): void
    {
        $now = IdentityEntities::now();

        $decoy = OtpChallenge::decoy(
            IdentityEntities::id(11),
            IdentityEntities::digest('niemand'),
            IdentityEntities::digest('niemand-code'),
            OtpPurpose::Login,
            $now->modify('+10 minutes'),
            null,
            $now,
        );

        $this->repository->save($decoy);

        self::assertSame(1, $this->repository->invalidateActiveFor(IdentityEntities::digest('niemand'), $now));
    }

    /**
     * 没有任何匹配时返回 0 而不是抛 —— 这是**首次登录**的常态路径，
     * 每个新用户的第一次请求都会走到它。
     */
    public function testReturnsZeroWhenThereIsNothingToInvalidate(): void
    {
        self::assertSame(
            0,
            $this->repository->invalidateActiveFor(IdentityEntities::digest('never-seen'), IdentityEntities::now()),
        );
    }

    private function issue(string $seed, \DateTimeImmutable $now, int $nth = 1, ?HashDigest $magicTokenHash = null): OtpChallenge
    {
        return OtpChallenge::issue(
            IdentityEntities::id($nth),
            IdentityEntities::digest($seed),
            IdentityEntities::ciphertext(),
            Locale::German,
            IdentityEntities::digest($seed.'-code'),
            OtpPurpose::Login,
            $now->modify('+10 minutes'),
            $magicTokenHash,
            IdentityEntities::digest('ip'),
            $now,
        );
    }

    // ========================================================================
    // T-106：Magic Link 的查找键
    // ========================================================================

    /**
     * 与 `invalidateActiveFor()` 同一条理由：`magic_token_hash` 是 BYTEA，
     * 参数必须以 `hash_digest` 类型绑定。绑错了不会报错，而是**一行都匹配不上** ——
     * 症状是「每一个 Magic Link 都 401」，而单测里的内存替身按 PHP 逻辑比较，
     * 一定看不见这层。
     */
    public function testFindsAChallengeByItsMagicTokenHash(): void
    {
        $hash = IdentityEntities::digest('magic-token');
        $challenge = $this->issue('anna', IdentityEntities::now(), magicTokenHash: $hash);

        $this->repository->save($challenge);
        $this->entityManager->clear();

        $loaded = $this->connection->isTransactionActive()
            ? $this->repository->findByMagicTokenHash($hash)
            : null;

        self::assertNotNull($loaded, 'FOR UPDATE 要求在事务里 —— RequiresIdentitySchema 已经开了一个。');
        self::assertTrue($loaded->id()->equals($challenge->id()));
    }

    /** 查不到就是 null —— 调用方据此返回 401，不是抛。 */
    public function testReturnsNullForAnUnknownMagicTokenHash(): void
    {
        $this->repository->save($this->issue('anna', IdentityEntities::now(), magicTokenHash: IdentityEntities::digest('magic-token')));
        $this->entityManager->clear();

        self::assertNull($this->repository->findByMagicTokenHash(IdentityEntities::digest('some-other-token')));
    }

    /**
     * 绝大多数历史行在这一列上是 NULL，而**它们不能被一个 NULL 查出来**。
     *
     * ⚠️ 这条看着像凑数，其实钉的是唯一索引的取舍：索引是普通 UNIQUE
     * （不是部分索引，理由见 Version20260907140000），所以库里会同时存在
     * 多行 `magic_token_hash IS NULL`。PG 的 NULLS DISTINCT 让它们互不冲突，
     * 而查询侧走的是 `= :hash`，对 NULL 恒不匹配 —— 两者合起来才成立。
     */
    public function testChallengesWithoutAMagicTokenAreNeverFound(): void
    {
        $now = IdentityEntities::now();

        // 两行都没有 Magic Link。唯一索引若不是 NULLS DISTINCT，第二次 save 就会炸。
        $this->repository->save($this->issue('anna', $now, nth: 1));
        $this->repository->save($this->issue('bea', $now, nth: 2));
        $this->entityManager->clear();

        self::assertSame(2, (int) $this->connection->fetchOne(
            'SELECT count(*) FROM otp_challenges WHERE magic_token_hash IS NULL',
        ));
    }

    /**
     * `uq_otp_challenges_magic_token_hash`：一个令牌只能对应一条挑战。
     *
     * 碰撞的概率是 2^-256，所以这条不是在防随机碰撞 —— 它防的是**代码 bug**
     * （比如把令牌生成挪到循环外、或者错误地复用了上一条挑战的令牌）。
     * 那种 bug 的后果是「一个人的链接把另一个人登进去」，
     * 而没有这个约束的话它在库里看起来完全正常。
     */
    public function testTheSameMagicTokenCannotBeIssuedTwice(): void
    {
        $now = IdentityEntities::now();
        $hash = IdentityEntities::digest('magic-token');

        $this->repository->save($this->issue('anna', $now, nth: 1, magicTokenHash: $hash));

        $this->expectException(UniqueConstraintViolationException::class);

        $this->repository->save($this->issue('bea', $now, nth: 2, magicTokenHash: $hash));
    }

    private function reload(OtpChallenge $challenge): OtpChallenge
    {
        $loaded = $this->repository->findById($challenge->id());

        self::assertNotNull($loaded);

        return $loaded;
    }
}
