<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Identity\Application\Me;

use App\Module\Identity\Application\Me\ProfileService;
use App\Module\Identity\Application\Me\ProfileUpdatePayload;
use App\Module\Identity\Domain\Entity\User;
use App\Module\Identity\Domain\ValueObject\Locale;
use App\Shared\Domain\Error\DomainException;
use App\Shared\Domain\Error\ErrorCode;
use App\Shared\Domain\Http\AuthContext;
use App\Tests\Double\Identity\IdentityEntities;
use App\Tests\Double\Identity\InMemoryUserRepository;
use App\Tests\Double\Time\FrozenClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * `GET /v1/me` 与 `PATCH /v1/me` 的编排（T-108）。
 */
#[CoversClass(ProfileService::class)]
final class ProfileServiceTest extends TestCase
{
    // ========================================================================
    // GET
    // ========================================================================

    /**
     * 注册中间态照样读得出来 —— `GET /me` 是那个状态下仍然可达的三个端点之一，
     * 而客户端要的正是这里的 `onboardingComplete: false`。
     */
    public function testReadsTheProfileOfAnIncompleteUser(): void
    {
        $user = IdentityEntities::user();
        $service = self::service($users = new InMemoryUserRepository($user));

        $profile = $service->profile(self::auth($user));

        self::assertNull($profile->username);
        self::assertFalse($profile->onboardingComplete);
        self::assertSame('de', $profile->locale);
        self::assertSame(['findById'], $users->calls());
    }

    public function testReadsTheProfileOfACompleteUser(): void
    {
        $user = IdentityEntities::user();
        $user->assignUsername('anna_b', IdentityEntities::now());

        $profile = self::service(new InMemoryUserRepository($user))->profile(self::auth($user));

        self::assertSame('anna_b', $profile->username);
        self::assertTrue($profile->onboardingComplete);
    }

    // ========================================================================
    // PATCH
    // ========================================================================

    public function testChangesTheLocaleAndPersistsIt(): void
    {
        $user = IdentityEntities::user();
        $users = new InMemoryUserRepository($user);

        $profile = self::service($users)->update(self::auth($user), new ProfileUpdatePayload(Locale::English));

        self::assertSame('en', $profile->locale);
        self::assertSame(Locale::English, $user->locale());
        self::assertContains('save', $users->calls());
    }

    /**
     * 同值时 `User::changeLocale()` 自身是 no-op，连 `updatedAt` 都不动 ——
     * 于是客户端重发同一个 PATCH（离线队列最常见的形态）不会在 `users` 上
     * 留下一串无意义的更新，也不会把 `updated_at` 变成一个没有意义的时间戳。
     */
    public function testRepeatingTheSameLocaleDoesNotTouchUpdatedAt(): void
    {
        $user = IdentityEntities::user();
        $before = $user->updatedAt();

        $clock = new FrozenClock();
        $clock->advance(60_000);

        self::service(new InMemoryUserRepository($user), $clock)
            ->update(self::auth($user), new ProfileUpdatePayload(Locale::German));

        self::assertEquals($before, $user->updatedAt());
    }

    // ========================================================================
    // 用户行不见了
    // ========================================================================

    /**
     * ⚠️ 500 而不是 404，口径与 `AssignUsernameService` 逐字相同。
     *
     * 令牌验过签，而且 `OnboardingListener` 刚刚为了判定状态查过同一行 ——
     * 走到这里还落空，只可能是它在这两步之间被删号流程或 T-113 的清理删掉了。
     * 那不是客户端能修的东西，也不该是 404：对调用方来说，
     * 它拿着一个有效令牌却被告知自己不存在。
     */
    public function testTreatsAMissingRowAsAnInternalError(): void
    {
        $service = self::service(new InMemoryUserRepository());

        $this->expectException(DomainException::class);

        try {
            $service->profile(self::auth(IdentityEntities::user()));
        } catch (DomainException $e) {
            self::assertSame(ErrorCode::InternalError, $e->errorCode());

            throw $e;
        }
    }

    public function testTreatsAMissingRowAsAnInternalErrorOnUpdateToo(): void
    {
        $service = self::service(new InMemoryUserRepository());

        $this->expectException(DomainException::class);

        $service->update(self::auth(IdentityEntities::user()), new ProfileUpdatePayload(Locale::English));
    }

    // ========================================================================
    // helpers
    // ========================================================================

    private static function service(InMemoryUserRepository $users, ?FrozenClock $clock = null): ProfileService
    {
        return new ProfileService($users, $clock ?? new FrozenClock());
    }

    private static function auth(User $user): AuthContext
    {
        return new AuthContext($user->id(), IdentityEntities::id(), IdentityEntities::id());
    }
}
