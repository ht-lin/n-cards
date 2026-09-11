<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Identity\Application\Cleanup;

use App\Module\Identity\Application\Cleanup\ForgetOtpRequestIpsTask;
use App\Module\Identity\Domain\Entity\OtpChallenge;
use App\Tests\Double\Identity\IdentityEntities;
use App\Tests\Double\Identity\InMemoryOtpChallengeRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * §8.2 的「ip_hash 30 天」上限。
 *
 * ============================================================================
 * ⚠️ 这个任务在生产里恒处理 0 行，而这些用例**不是**因此就没意义
 * ============================================================================
 * 挑战 10 分钟过期、24 小时后整行被 {@see \App\Module\Identity\Application\Cleanup\PurgeDeadOtpChallengesTask}
 * 删掉，活不到 30 天。所以线上永远走不到这里。
 *
 * 这个文件因此是那条上限**唯一**的证据：它证明「假如有一行真的活过了 30 天，
 * 它的 IP 会被忘掉」。那个「假如」不是臆想 —— 把宽限期调到 45 天是一个完全合理的
 * 排障诉求，而调完之后没有任何别的测试会红。
 *
 * {@see testTheRowItselfSurvives()} 是与 PurgeDeadOtpChallengesTask 的分界线：
 * 这个任务**只置空一列**，不删行。写成删行的话，24 小时的宽限期会被这条
 * 30 天的规则悄悄覆盖掉一部分语义。
 */
#[CoversClass(ForgetOtpRequestIpsTask::class)]
final class ForgetOtpRequestIpsTaskTest extends TestCase
{
    private const RETENTION_DAYS = 30;

    private const BATCH_SIZE = 1000;

    private const NOW = '2026-09-11T04:30:00+00:00';

    public function testForgetsTheIpOfARowOneSecondPastTheThirtiethDay(): void
    {
        $challenge = $this->challengeCreatedAt('-30 days -1 second');
        $challenges = new InMemoryOtpChallengeRepository($challenge);

        self::assertSame(1, $this->task($challenges)->run($this->now()));
        self::assertNull($challenge->requestIpHash());
    }

    public function testKeepsTheIpOfARowThatIsExactlyThirtyDaysOld(): void
    {
        $challenge = $this->challengeCreatedAt('-30 days');
        $challenges = new InMemoryOtpChallengeRepository($challenge);

        // 严格小于，与另外两个任务同一个口径。
        self::assertSame(0, $this->task($challenges)->run($this->now()));
        self::assertNotNull($challenge->requestIpHash());
    }

    /**
     * ⚠️ 这条划出与 PurgeDeadOtpChallengesTask 的分界：**只忘掉一列，不删行**。
     */
    public function testTheRowItselfSurvives(): void
    {
        $challenges = new InMemoryOtpChallengeRepository($this->challengeCreatedAt('-90 days'));

        $this->task($challenges)->run($this->now());

        self::assertCount(1, $challenges->all(), 'This task nulls a column; deleting the row is another task.');
    }

    /**
     * 在**当前**配置下（24 小时宽限）这个任务碰不到任何行 —— 那是正常状态，
     * 不是故障。返回 0，且不写任何东西。
     */
    public function testFindsNothingWhenEveryRowIsYoungerThanTheCeiling(): void
    {
        $challenges = new InMemoryOtpChallengeRepository(
            $this->challengeCreatedAt('-1 hour'),
            $this->challengeCreatedAt('-2 days', nth: 2),
        );

        self::assertSame(0, $this->task($challenges)->run($this->now()));
        self::assertSame([], $challenges->operations(), 'Nothing to forget must mean nothing written.');
    }

    /**
     * 已经忘过的行不该被反复重写 —— 否则「处理行数」会天天报同一个非零值，
     * 而那个数字是运维判断「这道闸有没有真的在动」的唯一依据。
     */
    public function testSkipsRowsWhoseIpIsAlreadyForgotten(): void
    {
        $challenges = new InMemoryOtpChallengeRepository(
            $this->challengeCreatedAt('-90 days', withIp: false),
        );

        self::assertSame(0, $this->task($challenges)->run($this->now()));
    }

    public function testForgetsEveryStaleRow(): void
    {
        $challenges = new InMemoryOtpChallengeRepository(
            $this->challengeCreatedAt('-90 days', nth: 1),
            $this->challengeCreatedAt('-60 days', nth: 2),
            $this->challengeCreatedAt('-1 day', nth: 3),
        );

        self::assertSame(2, $this->task($challenges)->run($this->now()));
    }

    /**
     * `$batchSize` 兜的是「有人把宽限期调过 30 天」之后的第一趟：一次处理上限
     * 那么多行，剩下的明天接着，而不是把几十万行一次拉进内存。
     */
    public function testProcessesAtMostOneBatchPerRun(): void
    {
        $challenges = new InMemoryOtpChallengeRepository(
            $this->challengeCreatedAt('-90 days', nth: 1),
            $this->challengeCreatedAt('-90 days', nth: 2),
            $this->challengeCreatedAt('-90 days', nth: 3),
        );

        $task = new ForgetOtpRequestIpsTask($challenges, self::RETENTION_DAYS, 2);

        self::assertSame(2, $task->run($this->now()));
        // 下一趟把剩下的那条收掉 —— 这就是「明天接着」。
        self::assertSame(1, $task->run($this->now()));
        self::assertSame(0, $task->run($this->now()));
    }

    public function testTheCeilingComesFromConfiguration(): void
    {
        $challenges = new InMemoryOtpChallengeRepository($this->challengeCreatedAt('-40 days'));

        $task = new ForgetOtpRequestIpsTask($challenges, 90, self::BATCH_SIZE);

        self::assertSame(0, $task->run($this->now()));
    }

    public function testIsNamedForTheMetricLabelAndTheTaskFilter(): void
    {
        self::assertSame(
            'identity.otp_request_ips',
            $this->task(new InMemoryOtpChallengeRepository())->name(),
        );
    }

    private function task(InMemoryOtpChallengeRepository $challenges): ForgetOtpRequestIpsTask
    {
        return new ForgetOtpRequestIpsTask($challenges, self::RETENTION_DAYS, self::BATCH_SIZE);
    }

    private function now(): \DateTimeImmutable
    {
        return IdentityEntities::now(self::NOW);
    }

    private function challengeCreatedAt(string $offset, int $nth = 1, bool $withIp = true): OtpChallenge
    {
        $createdAt = $this->now()->modify($offset);

        return IdentityEntities::challenge(
            id: IdentityEntities::id($nth),
            requestIpHash: $withIp ? IdentityEntities::digest('ip-'.$nth) : null,
            now: $createdAt,
        );
    }
}
