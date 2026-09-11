<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Identity\Application\Cleanup;

use App\Module\Identity\Application\Cleanup\PurgeDeadOtpChallengesTask;
use App\Module\Identity\Domain\Entity\OtpChallenge;
use App\Tests\Double\Identity\IdentityEntities;
use App\Tests\Double\Identity\InMemoryOtpChallengeRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * §8.2 ROPA「认证」那一行的保留期。
 *
 * ⚠️ 这张表自 ADR-0014 起每行都带 `email_encrypted`（Vault Transit 加密的收件
 * 邮箱），所以下面的边界不是磁盘占用问题，是**个人数据留存期**。宽限期算错一档，
 * ROPA 那一行就成了一份描述未发生之事的 Art. 30 记录。
 *
 * 「死」有两条独立的时间线（`consumed_at` / `expires_at`），两条都要单独验 ——
 * 生产实现里它们是一个 `OR`，而 `OR` 的某一半写错不会让另一半的用例红。
 */
#[CoversClass(PurgeDeadOtpChallengesTask::class)]
final class PurgeDeadOtpChallengesTaskTest extends TestCase
{
    private const GRACE_HOURS = 24;

    private const NOW = '2026-09-11T04:30:00+00:00';

    // ========================================================================
    // 过期这条时间线
    // ========================================================================

    public function testDeletesAChallengeThatExpiredOneSecondPastTheGrace(): void
    {
        $challenges = new InMemoryOtpChallengeRepository(
            $this->challengeExpiringAt('-24 hours -1 second'),
        );

        self::assertSame(1, $this->task($challenges)->run($this->now()));
        self::assertSame([], $challenges->all());
    }

    public function testKeepsAChallengeThatExpiredExactlyAtTheGraceBoundary(): void
    {
        $challenges = new InMemoryOtpChallengeRepository(
            $this->challengeExpiringAt('-24 hours'),
        );

        // 严格小于：正好卡在宽限期边界上的那条**留下**。
        self::assertSame(0, $this->task($challenges)->run($this->now()));
        self::assertCount(1, $challenges->all());
    }

    /**
     * ⚠️ **红了就是所有人都登不进去。** 一条还没过期的挑战被删掉，
     * 等于用户手里那个刚收到的 6 位码对应不到任何东西。
     */
    public function testNeverDeletesALiveChallenge(): void
    {
        $challenges = new InMemoryOtpChallengeRepository(
            $this->challengeExpiringAt('+10 minutes'),
        );

        self::assertSame(0, $this->task($challenges)->run($this->now()));
        self::assertCount(1, $challenges->all());
    }

    /**
     * 刚过期、还在宽限期内的那些留着 —— 这正是 24 小时买来的东西：
     * 「昨晚那批登录失败是码错了还是过期了」还答得上来。
     */
    public function testKeepsARecentlyExpiredChallengeForTroubleshooting(): void
    {
        $challenges = new InMemoryOtpChallengeRepository(
            $this->challengeExpiringAt('-1 hour'),
        );

        self::assertSame(0, $this->task($challenges)->run($this->now()));
        self::assertCount(1, $challenges->all());
    }

    // ========================================================================
    // 已消费这条时间线
    // ========================================================================

    public function testDeletesAChallengeConsumedOneSecondPastTheGrace(): void
    {
        $challenges = new InMemoryOtpChallengeRepository(
            $this->challengeConsumedAt('-24 hours -1 second'),
        );

        self::assertSame(1, $this->task($challenges)->run($this->now()));
        self::assertSame([], $challenges->all());
    }

    public function testKeepsAChallengeConsumedExactlyAtTheGraceBoundary(): void
    {
        $challenges = new InMemoryOtpChallengeRepository(
            $this->challengeConsumedAt('-24 hours'),
        );

        self::assertSame(0, $this->task($challenges)->run($this->now()));
        self::assertCount(1, $challenges->all());
    }

    // ========================================================================

    public function testDeletesOnlyTheDeadOnesInAMixedTable(): void
    {
        $challenges = new InMemoryOtpChallengeRepository(
            $this->challengeExpiringAt('-48 hours', nth: 1),
            $this->challengeConsumedAt('-48 hours', nth: 2),
            $this->challengeExpiringAt('+10 minutes', nth: 3),
        );

        self::assertSame(2, $this->task($challenges)->run($this->now()));
        self::assertCount(1, $challenges->all());
    }

    /**
     * 宽限期来自 `ncards.cleanup.otp_challenge_grace_hours`，不是写死的 24。
     */
    public function testTheGraceComesFromConfiguration(): void
    {
        $challenges = new InMemoryOtpChallengeRepository($this->challengeExpiringAt('-48 hours'));

        $task = new PurgeDeadOtpChallengesTask($challenges, 72);

        self::assertSame(0, $task->run($this->now()));
        self::assertCount(1, $challenges->all());
    }

    public function testIsNamedForTheMetricLabelAndTheTaskFilter(): void
    {
        self::assertSame(
            'identity.dead_otp_challenges',
            $this->task(new InMemoryOtpChallengeRepository())->name(),
        );
    }

    private function task(InMemoryOtpChallengeRepository $challenges): PurgeDeadOtpChallengesTask
    {
        return new PurgeDeadOtpChallengesTask($challenges, self::GRACE_HOURS);
    }

    private function now(): \DateTimeImmutable
    {
        return IdentityEntities::now(self::NOW);
    }

    /**
     * 一条在 `$offset` 过期的挑战（未消费）。
     */
    private function challengeExpiringAt(string $offset, int $nth = 1): OtpChallenge
    {
        $expiresAt = $this->now()->modify($offset);

        return IdentityEntities::challenge(
            id: IdentityEntities::id($nth),
            expiresAt: $expiresAt,
            // §7.1：挑战在签发 10 分钟后过期，夹具照着这条反推 created_at，
            // 免得造出「创建于未来」这种真库里不可能出现的行。
            now: $expiresAt->modify('-10 minutes'),
        );
    }

    /**
     * 一条在 `$offset` 被消费的挑战。
     *
     * ⚠️ `expires_at` 刻意设在**将来**：这样它只可能因为 `consumed_at` 而被删，
     * 于是这条用例真的在验 `OR` 的第二半，而不是搭第一半的便车。
     */
    private function challengeConsumedAt(string $offset, int $nth = 1): OtpChallenge
    {
        $consumedAt = $this->now()->modify($offset);

        $challenge = IdentityEntities::challenge(
            id: IdentityEntities::id($nth),
            expiresAt: $this->now()->modify('+10 minutes'),
            now: $consumedAt,
        );

        $challenge->consume($consumedAt);

        return $challenge;
    }
}
