<?php

declare(strict_types=1);

namespace App\Tests\Double\Identity;

use App\Module\Identity\Domain\Entity\OtpChallenge;
use App\Module\Identity\Domain\Repository\OtpChallengeRepositoryInterface;
use App\Shared\Domain\Crypto\HashDigest;
use App\Shared\Domain\Identity\Uuid;

/**
 * 进程内的 `otp_challenges` 仓储，**并记下写操作的顺序**。
 *
 * 顺序是一条真实的不变量：{@see invalidateActiveFor()} 必须发生在
 * {@see save()} **之前**，否则会把刚建的挑战一起作废，用户拿到的码当场失效。
 * 这个替身把顺序留在 {@see operations()} 里，单测直接对着数组断言 ——
 * 那比在真库上验「新挑战的 consumed_at 是不是 null」更能说清失败原因。
 *
 * ⚠️ `invalidateActiveFor()` 这里是**逐条遍历**实现的，与生产的批量 DQL
 * 语义相同但机制不同。DQL 的 WHERE 是否真的按预期收窄，只有真 Postgres
 * 能证明 —— 那条断言在
 * tests/Integration/Module/Identity/Doctrine/DoctrineOtpChallengeRepositoryTest。
 */
final class InMemoryOtpChallengeRepository implements OtpChallengeRepositoryInterface
{
    /** @var list<OtpChallenge> */
    private array $challenges = [];

    /** @var list<string> `save` / `invalidate` 依次记账 */
    private array $operations = [];

    public function __construct(OtpChallenge ...$challenges)
    {
        $this->challenges = array_values($challenges);
    }

    /**
     * ⚠️ **按 id upsert**，不是无条件追加（与 {@see InMemoryUserRepository::upsert()}
     * 同一个理由）。生产实现是 `persist()` + `flush()`，对一个已经被管理的实体
     * 那是一次 UPDATE —— 追加的话，任何「读出来改一下再存回去」的调用方
     * （T-113 的 ForgetOtpRequestIpsTask 就是）都会在替身上凭空多出一行，
     * 而那种失败看起来像被测代码写错了。
     */
    public function save(OtpChallenge $challenge): void
    {
        $this->operations[] = 'save';

        foreach ($this->challenges as $index => $existing) {
            if ($existing->id()->equals($challenge->id())) {
                $this->challenges[$index] = $challenge;

                return;
            }
        }

        $this->challenges[] = $challenge;
    }

    public function findById(Uuid $id): ?OtpChallenge
    {
        foreach ($this->challenges as $challenge) {
            if ($challenge->id()->equals($id)) {
                return $challenge;
            }
        }

        return null;
    }

    /**
     * ⚠️ 这里**没有**行锁，而生产实现靠 `PESSIMISTIC_WRITE` 挡住
     * 「一个令牌换到两个会话」。单测因此证明不了那条不变量 ——
     * 它只能由真 Postgres 证明，断言在
     * tests/Integration/Module/Identity/Doctrine/DoctrineOtpChallengeRepositoryTest。
     */
    public function findByMagicTokenHash(HashDigest $magicTokenHash): ?OtpChallenge
    {
        foreach ($this->challenges as $challenge) {
            if ($challenge->magicTokenHash()?->equals($magicTokenHash) ?? false) {
                return $challenge;
            }
        }

        return null;
    }

    public function invalidateActiveFor(HashDigest $emailHash, \DateTimeImmutable $now): int
    {
        $this->operations[] = 'invalidate';

        $affected = 0;

        foreach ($this->challenges as $challenge) {
            if (!$challenge->emailHash()->equals($emailHash)) {
                continue;
            }

            if ($challenge->isConsumed() || $challenge->isExpiredAt($now)) {
                continue;
            }

            $challenge->consume($now);
            ++$affected;
        }

        return $affected;
    }

    /**
     * T-113。与生产实现一样按 `expires_at` / `consumed_at` 两条时间线判「已死」，
     * 但这里是逐条遍历 —— DQL 的 `OR` 是否真的按预期展开，仍然只有真 Postgres
     * 能证明（断言在 DoctrineOtpChallengeRepositoryTest）。
     */
    public function deleteDeadBefore(\DateTimeImmutable $cutoff): int
    {
        $this->operations[] = 'deleteDead';

        $kept = [];
        $deleted = 0;

        foreach ($this->challenges as $challenge) {
            $dead = $challenge->expiresAt() < $cutoff
                || (null !== $challenge->consumedAt() && $challenge->consumedAt() < $cutoff);

            if ($dead) {
                ++$deleted;

                continue;
            }

            $kept[] = $challenge;
        }

        $this->challenges = $kept;

        return $deleted;
    }

    /**
     * T-113。`$limit` 用 `array_slice` 实现，与生产的 `setMaxResults()` 同语义。
     *
     * ⚠️ 排序也照抄（`created_at` 升序）：单测若依赖「先拿到最老的那条」，
     * 而替身按插入顺序返回，那条断言在真库上会随机失败。
     *
     * @return list<OtpChallenge>
     */
    public function findWithRequestIpOlderThan(\DateTimeImmutable $cutoff, int $limit): array
    {
        $matching = [];

        foreach ($this->challenges as $challenge) {
            if (null === $challenge->requestIpHash()) {
                continue;
            }

            if ($challenge->createdAt() >= $cutoff) {
                continue;
            }

            $matching[] = $challenge;
        }

        usort($matching, static fn (OtpChallenge $a, OtpChallenge $b) => $a->createdAt() <=> $b->createdAt());

        return \array_slice($matching, 0, $limit);
    }

    /**
     * @return list<OtpChallenge>
     */
    public function all(): array
    {
        return $this->challenges;
    }

    /**
     * 最后一条被 save 的挑战 —— 用例几乎总是要断言它。
     */
    public function lastSaved(): OtpChallenge
    {
        if ([] === $this->challenges) {
            throw new \LogicException('No challenge was saved.');
        }

        return $this->challenges[\count($this->challenges) - 1];
    }

    /**
     * @return list<string>
     */
    public function operations(): array
    {
        return $this->operations;
    }
}
