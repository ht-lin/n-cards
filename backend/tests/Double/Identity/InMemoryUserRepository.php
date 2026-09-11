<?php

declare(strict_types=1);

namespace App\Tests\Double\Identity;

use App\Module\Identity\Domain\Entity\User;
use App\Module\Identity\Domain\Repository\UserRepositoryInterface;
use App\Shared\Domain\Crypto\HashDigest;
use App\Shared\Domain\Error\DomainException;
use App\Shared\Domain\Error\ErrorCode;
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

    /** @var list<string> 方法调用顺序，见 {@see calls()} */
    private array $calls = [];

    /**
     * 预查放行、写入时才撞车的那个名字（T-107）。
     *
     * @see failSaveNewUsernameFor()
     */
    private ?string $racingUsername = null;

    public function __construct(User ...$users)
    {
        $this->users = array_values($users);
    }

    /**
     * 让 {@see saveNewUsername()} 对这个名字**无条件**抛 `username_taken`，
     * 即使 `findByUsername()` 刚刚说它是空的。
     *
     * 模拟的是真库上 `uq_users_username` 的并发路径：两个请求同时通过预查，
     * 后一个在 INSERT 时才撞上唯一索引。没有这个开关的话，那条分支
     * （`AssignUsernameService` 里 `saveNewUsername()` 外面那个 try）
     * 在单测里够不着。
     */
    public function failSaveNewUsernameFor(string $username): void
    {
        $this->racingUsername = $username;
    }

    /**
     * 方法调用顺序。
     *
     * T-107 用它钉住「尝试计数必须在查重**之前**落库」：真库上 Doctrine 会在
     * flush 失败时关掉 EntityManager，两者挤进同一次 flush 的话，撞唯一约束
     * 那一路的计数会被一起丢掉，而那恰好是唯一真正在试探占用情况的那条路径。
     * 顺序之外没有别的东西能表达这条约束。
     *
     * @return list<string>
     */
    public function calls(): array
    {
        return $this->calls;
    }

    public function save(User $user): void
    {
        $this->calls[] = 'save';

        $this->upsert($user);
    }

    /**
     * 真库那边靠 `uq_users_username` 兜底，这里手工模拟同一条：**别人**已经占了
     * 同一个归一化值时抛 `username_taken`（T-107）。
     *
     * ⚠️ 必须比对 id 排除自己 —— upsert 的语义下，一个刚在内存里被
     * `assignUsername()` 过的用户此刻已经在 `$this->users` 里带着那个名字了，
     * 不排除的话每一次正常的设定都会撞上自己。
     */
    public function saveNewUsername(User $user): void
    {
        $this->calls[] = 'saveNewUsername';

        $username = $user->username();

        if (null !== $username && $username === $this->racingUsername) {
            throw new DomainException(ErrorCode::UsernameTaken, 'The username is already taken.');
        }

        foreach ($this->users as $existing) {
            if (null !== $username
                && $existing->username() === $username
                && !$existing->id()->equals($user->id())
            ) {
                throw new DomainException(ErrorCode::UsernameTaken, 'The username is already taken.');
            }
        }

        $this->upsert($user);
    }

    private function upsert(User $user): void
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
        $this->calls[] = 'findById';

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
        $this->calls[] = 'findByUsername';

        foreach ($this->users as $user) {
            if ($user->username() === $normalized) {
                return $user;
            }
        }

        return null;
    }

    /**
     * T-113。
     *
     * ⚠️ 这个替身**没有外键**，所以它证明不了两件真库上才有的事：
     * `devices` / `sessions` 跟着级联消失（`ON DELETE CASCADE`），以及
     * 持卡人会撞上 `cards.owner_id` 的 `ON DELETE RESTRICT`。两者都在
     * tests/Integration/Module/Identity/Doctrine/DoctrineUserRepositoryTest。
     * 这里只验编排：任务算出来的 `$cutoff` 对不对、`username IS NULL` 有没有漏。
     */
    public function deleteZombieRegistrationsBefore(\DateTimeImmutable $cutoff): int
    {
        $this->calls[] = 'deleteZombieRegistrationsBefore';

        $kept = [];
        $deleted = 0;

        foreach ($this->users as $user) {
            if (null === $user->username() && $user->createdAt() < $cutoff) {
                ++$deleted;

                continue;
            }

            $kept[] = $user;
        }

        $this->users = $kept;

        return $deleted;
    }

    public function findByEmailHashCalls(): int
    {
        return $this->findByEmailHashCalls;
    }

    public function count(): int
    {
        return \count($this->users);
    }
}
