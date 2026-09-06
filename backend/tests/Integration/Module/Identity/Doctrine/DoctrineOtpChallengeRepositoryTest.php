<?php

declare(strict_types=1);

namespace App\Tests\Integration\Module\Identity\Doctrine;

use App\Module\Identity\Domain\Entity\OtpChallenge;
use App\Module\Identity\Domain\Repository\OtpChallengeRepositoryInterface;
use App\Module\Identity\Domain\ValueObject\OtpPurpose;
use App\Module\Identity\Infrastructure\Doctrine\DoctrineOtpChallengeRepository;
use App\Tests\Double\Identity\IdentityEntities;
use App\Tests\Integration\Support\RequiresIdentitySchema;
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
     * decoy 与真实挑战一视同仁 —— 不存在的邮箱同样会攒下旧的哑挑战，
     * 而两条路径的 DB 往返次数必须相等（§3.8）。
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

    private function issue(string $seed, \DateTimeImmutable $now, int $nth = 1): OtpChallenge
    {
        return OtpChallenge::issue(
            IdentityEntities::id($nth),
            IdentityEntities::digest($seed),
            IdentityEntities::digest($seed.'-code'),
            OtpPurpose::Login,
            $now->modify('+10 minutes'),
            null,
            IdentityEntities::digest('ip'),
            $now,
        );
    }

    private function reload(OtpChallenge $challenge): OtpChallenge
    {
        $loaded = $this->repository->findById($challenge->id());

        self::assertNotNull($loaded);

        return $loaded;
    }
}
