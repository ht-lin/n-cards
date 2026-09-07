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

    public function save(OtpChallenge $challenge): void
    {
        $this->operations[] = 'save';
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
