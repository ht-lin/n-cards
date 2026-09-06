<?php

declare(strict_types=1);

namespace App\Tests\Double\Identity;

use App\Module\Identity\Domain\Entity\User;
use App\Module\Identity\Domain\Repository\UserRepositoryInterface;
use App\Shared\Domain\Crypto\HashDigest;
use App\Shared\Domain\Identity\Uuid;

/**
 * 进程内的 `users` 仓储，**并记下查询次数**。
 *
 * 查询次数是 §3.8 那条「两条路径做功相同」断言的一半（另一半是 Vault 往返，
 * 见 {@see \App\Tests\Double\Crypto\RecordingHmacHasher}）。
 *
 * 真 Postgres 上的行为由 tests/Integration/Module/Identity/Doctrine 下的
 * 用例覆盖 —— 那里验的是唯一约束与列类型，这里验的是编排。
 */
final class InMemoryUserRepository implements UserRepositoryInterface
{
    /** @var list<User> */
    private array $users = [];

    private int $findByEmailHashCalls = 0;

    public function __construct(User ...$users)
    {
        $this->users = array_values($users);
    }

    public function save(User $user): void
    {
        foreach ($this->users as $index => $existing) {
            if ($existing->id()->equals($user->id())) {
                $this->users[$index] = $user;

                return;
            }
        }

        $this->users[] = $user;
    }

    public function findById(Uuid $id): ?User
    {
        foreach ($this->users as $user) {
            if ($user->id()->equals($id)) {
                return $user;
            }
        }

        return null;
    }

    public function findByEmailHash(HashDigest $emailHash): ?User
    {
        ++$this->findByEmailHashCalls;

        foreach ($this->users as $user) {
            if ($user->emailHash()->equals($emailHash)) {
                return $user;
            }
        }

        return null;
    }

    public function findByUsername(string $normalized): ?User
    {
        foreach ($this->users as $user) {
            if ($user->username() === $normalized) {
                return $user;
            }
        }

        return null;
    }

    public function findByEmailHashCalls(): int
    {
        return $this->findByEmailHashCalls;
    }
}
