<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Identity\Application\Cleanup;

use App\Module\Identity\Application\Cleanup\PurgeZombieRegistrationsTask;
use App\Module\Identity\Domain\Entity\User;
use App\Tests\Double\Identity\IdentityEntities;
use App\Tests\Double\Identity\InMemoryUserRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * §5.2 的「删除 `username IS NULL` 且 `created_at < now() - 7 days` 的僵尸行」。
 *
 * ⚠️ 任务卡的验收标准点名要**第 7 天 vs 第 8 天**的边界断言，所以下面四条
 * 边界用例是这个文件存在的主要理由。差一天（`<=` 写成 `<`，或 `now` 与
 * `cutoff` 比反）不会让任何功能测试红，但会让一批还在 onboarding 的用户
 * 在设名字的路上被删掉。
 *
 * 真库上的两件事（`devices`/`sessions` 的级联、`cards` 的 RESTRICT）不在这里 ——
 * 替身没有外键。断言在 DoctrineUserRepositoryTest。
 */
#[CoversClass(PurgeZombieRegistrationsTask::class)]
final class PurgeZombieRegistrationsTaskTest extends TestCase
{
    private const RETENTION_DAYS = 7;

    /** 判定时刻。用例造的行都相对它定位。 */
    private const NOW = '2026-09-11T04:30:00+00:00';

    public function testDeletesAZombieThatIsOneSecondPastTheSeventhDay(): void
    {
        $zombie = $this->zombieCreatedAt('-7 days -1 second');
        $users = new InMemoryUserRepository($zombie);

        self::assertSame(1, $this->task($users)->run($this->now()));
        self::assertSame(0, $users->count());
    }

    public function testKeepsAZombieThatIsExactlySevenDaysOld(): void
    {
        $zombie = $this->zombieCreatedAt('-7 days');
        $users = new InMemoryUserRepository($zombie);

        // 严格小于：正好第 7 天的那条**留下**。§5.2 写的是 `created_at < now() - 7 days`。
        self::assertSame(0, $this->task($users)->run($this->now()));
        self::assertSame(1, $users->count());
    }

    public function testKeepsAFreshZombie(): void
    {
        $users = new InMemoryUserRepository($this->zombieCreatedAt('-1 day'));

        self::assertSame(0, $this->task($users)->run($this->now()));
        self::assertSame(1, $users->count());
    }

    /**
     * ⚠️ **红了就是删号事故。** 一个设过 username 的用户不是僵尸行 ——
     * 无论他多老、多久没登录。§8.2 给「身份」的保留期是「账号存续期 + 30 天宽限」，
     * 而删掉一个活跃账号没有任何流程能挽回（§3.7 的宽限期走的是另一条路）。
     */
    public function testNeverDeletesAUserWhoHasAUsernameNoMatterHowOld(): void
    {
        $veteran = $this->zombieCreatedAt('-400 days');
        $veteran->assignUsername('anna', $this->now());

        $users = new InMemoryUserRepository($veteran);

        self::assertSame(0, $this->task($users)->run($this->now()));
        self::assertSame(1, $users->count());
    }

    public function testDeletesOnlyTheZombiesWhenBothKindsAreOldEnough(): void
    {
        $named = $this->zombieCreatedAt('-30 days', nth: 1);
        $named->assignUsername('anna', $this->now());

        $users = new InMemoryUserRepository(
            $named,
            $this->zombieCreatedAt('-30 days', nth: 2),
            $this->zombieCreatedAt('-30 days', nth: 3),
        );

        self::assertSame(2, $this->task($users)->run($this->now()));
        self::assertSame(1, $users->count());
    }

    /**
     * 保留期来自 `ncards.cleanup.zombie_registration_days`（§8.2 的真相源是 ROPA 表），
     * 不是写死在类里的 7。这条用例证明那个参数真的接上了。
     */
    public function testTheRetentionWindowComesFromConfiguration(): void
    {
        $users = new InMemoryUserRepository($this->zombieCreatedAt('-10 days'));

        $task = new PurgeZombieRegistrationsTask($users, 30);

        self::assertSame(0, $task->run($this->now()));
        self::assertSame(1, $users->count());
    }

    public function testIsNamedForTheMetricLabelAndTheTaskFilter(): void
    {
        self::assertSame(
            'identity.zombie_registrations',
            $this->task(new InMemoryUserRepository())->name(),
        );
    }

    private function task(InMemoryUserRepository $users): PurgeZombieRegistrationsTask
    {
        return new PurgeZombieRegistrationsTask($users, self::RETENTION_DAYS);
    }

    private function now(): \DateTimeImmutable
    {
        return IdentityEntities::now(self::NOW);
    }

    /**
     * 一个从未设过 username 的用户（{@see User::register()} 之后的状态）。
     */
    private function zombieCreatedAt(string $offset, int $nth = 1): User
    {
        return IdentityEntities::user(
            id: IdentityEntities::id($nth),
            emailHash: IdentityEntities::digest('zombie-'.$nth),
            now: $this->now()->modify($offset),
        );
    }
}
