<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Identity\Domain\Entity;

use App\Module\Identity\Domain\Entity\User;
use App\Module\Identity\Domain\ValueObject\Locale;
use App\Module\Identity\Domain\ValueObject\UserStatus;
use App\Shared\Domain\Error\DomainException;
use App\Shared\Domain\Error\ErrorCode;
use App\Tests\Double\Identity\IdentityEntities;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(User::class)]
final class UserTest extends TestCase
{
    /**
     * ⚠️ §5.2 的注册中间态：首次 OTP 验证即建行，那一刻还没有 username。
     * T-104 的集成测试会在真库上再断言一次同样的事。
     */
    public function testRegistrationLeavesUsernameNull(): void
    {
        $user = IdentityEntities::user();

        self::assertNull($user->username());
        self::assertFalse($user->hasUsername());
    }

    public function testRegistrationStartsActiveWithoutDeletionRequest(): void
    {
        $user = IdentityEntities::user();

        self::assertSame(UserStatus::Active, $user->status());
        self::assertNull($user->deletionRequestedAt());
    }

    public function testRegistrationSetsBothTimestampsToTheSameInstant(): void
    {
        $now = IdentityEntities::now();
        $user = IdentityEntities::user(now: $now);

        self::assertEquals($now, $user->createdAt());
        self::assertEquals($now, $user->updatedAt());
    }

    public function testKeepsTheIdentityItWasRegisteredWith(): void
    {
        $id = IdentityEntities::id(42);
        $emailHash = IdentityEntities::digest('anna');
        $emailEncrypted = IdentityEntities::ciphertext('YW5uYUBleGFtcGxlLmRl');

        $user = IdentityEntities::user($id, $emailHash, $emailEncrypted, Locale::English);

        self::assertTrue($id->equals($user->id()));
        self::assertTrue($emailHash->equals($user->emailHash()));
        self::assertTrue($emailEncrypted->equals($user->emailEncrypted()));
        self::assertSame(Locale::English, $user->locale());
    }

    public function testAssignsUsernameOnce(): void
    {
        $user = IdentityEntities::user();
        $later = IdentityEntities::now('2026-09-06T08:00:00+00:00');

        $user->assignUsername('anna_b', $later);

        self::assertSame('anna_b', $user->username());
        self::assertTrue($user->hasUsername());
        self::assertEquals($later, $user->updatedAt());
    }

    /**
     * §5.2 / T-107：一次性写入，已非空 → `409 username_immutable`。
     *
     * ⚠️ **必须抛，不能静默忽略** —— T-107 的验收标准写明设定后 `POST` 与 `PATCH`
     * 都返回 409。静默成功会让客户端以为改成了。
     */
    public function testRefusesToChangeAnAlreadySetUsername(): void
    {
        $user = IdentityEntities::user();
        $user->assignUsername('anna_b', IdentityEntities::now());

        try {
            $user->assignUsername('anna_c', IdentityEntities::now());
            self::fail('Expected DomainException.');
        } catch (DomainException $e) {
            self::assertSame(ErrorCode::UsernameImmutable, $e->errorCode());
            self::assertSame(409, $e->errorCode()->httpStatus());
        }

        self::assertSame('anna_b', $user->username());
    }

    /**
     * 连「设成同一个值」也拒绝。放行的话客户端就有了一个能重复调用成功的端点，
     * 而 T-107 的限流是「按 user 10 次总计」—— 幂等成功会让那条限流失去意义。
     */
    public function testRefusesEvenTheSameUsernameTwice(): void
    {
        $user = IdentityEntities::user();
        $user->assignUsername('anna_b', IdentityEntities::now());

        $this->expectException(DomainException::class);

        $user->assignUsername('anna_b', IdentityEntities::now());
    }

    /**
     * 格式校验**不在这里** —— 归 T-107 的 Username 值对象，库层的
     * chk_users_username_format 是第二道防线。这条用例把这个分工钉住：
     * 有人把校验挪进实体的话它会红，那时该先去改 T-107 的边界而不是改这里。
     */
    public function testDoesNotValidateTheUsernameFormat(): void
    {
        $user = IdentityEntities::user();

        $user->assignUsername('Anna_B', IdentityEntities::now());

        self::assertSame('Anna_B', $user->username());
    }

    // ========================================================================
    // §7.5 的「10 次总计」计数（T-107）
    // ========================================================================

    public function testStartsWithNoUsernameAttempts(): void
    {
        self::assertSame(0, IdentityEntities::user()->usernameAttempts());
    }

    public function testCountsEveryRecordedAttempt(): void
    {
        $user = IdentityEntities::user();

        $user->recordUsernameAttempt(10);
        $user->recordUsernameAttempt(10);

        self::assertSame(2, $user->usernameAttempts());
    }

    /**
     * 饱和而不是无限累加，与 `OtpChallenge::recordAttempt()` 同一个形状。
     *
     * 正常路径走不到这里（`AssignUsernameService` 先 `enforceCanAdd()`）——
     * 它防的是「日后有人加了第二个调用点却忘了先检查」，那时的后果会是
     * 计数溢出 SMALLINT，而不是一个能被看见的错误。
     */
    public function testSaturatesAtTheGivenMaximum(): void
    {
        $user = IdentityEntities::user();

        for ($i = 0; $i < 25; ++$i) {
            $user->recordUsernameAttempt(10);
        }

        self::assertSame(10, $user->usernameAttempts());
    }

    /**
     * ⚠️ 记次数与写名字是**两个**动作，实体不把它们绑在一起。
     *
     * 绑住的话「哪些失败消耗次数」这条策略（ADR-0017）就被钉死在 Domain 里了，
     * 而它是策略不是不变量 —— 真正的编排在 `AssignUsernameService`。
     */
    public function testAssigningAUsernameDoesNotItselfCountAnAttempt(): void
    {
        $user = IdentityEntities::user();

        $user->assignUsername('anna_b', IdentityEntities::now());

        self::assertSame(0, $user->usernameAttempts());
    }

    public function testChangesLocale(): void
    {
        $user = IdentityEntities::user(locale: Locale::German);
        $later = IdentityEntities::now('2026-09-06T08:00:00+00:00');

        $user->changeLocale(Locale::English, $later);

        self::assertSame(Locale::English, $user->locale());
        self::assertEquals($later, $user->updatedAt());
    }

    /**
     * 设成同一个 locale 不该动 updated_at —— 那一列是同步下发的依据，
     * 空写会让客户端以为有变化。
     */
    public function testChangingLocaleToTheSameValueIsANoop(): void
    {
        $created = IdentityEntities::now();
        $user = IdentityEntities::user(locale: Locale::German, now: $created);

        $user->changeLocale(Locale::German, IdentityEntities::now('2026-09-06T08:00:00+00:00'));

        self::assertEquals($created, $user->updatedAt());
    }

    public function testRewrapsTheEmailCiphertext(): void
    {
        $user = IdentityEntities::user();
        $emailHashBefore = $user->emailHash();
        $rewrapped = IdentityEntities::ciphertext('cmV3cmFwcGVk');
        $later = IdentityEntities::now('2027-01-01T00:00:00+00:00');

        $user->rewrapEmail($rewrapped, $later);

        self::assertTrue($rewrapped->equals($user->emailEncrypted()));
        self::assertEquals($later, $user->updatedAt());
        // ⚠️ email_hash 绝不跟着换 —— ncards-hmac 那把 key 不可轮换，
        // 换了所有既有行都查不回来（CryptoKey::isRotatable()）。
        self::assertTrue($emailHashBefore->equals($user->emailHash()));
    }

    public function testRequestsDeletion(): void
    {
        $user = IdentityEntities::user();
        $requestedAt = IdentityEntities::now('2026-10-01T12:00:00+00:00');

        $user->requestDeletion($requestedAt);

        self::assertSame(UserStatus::PendingDeletion, $user->status());
        self::assertEquals($requestedAt, $user->deletionRequestedAt());
    }

    /**
     * 幂等且**不重置起点** —— 否则反复调用就能把删除无限期推迟下去。
     */
    public function testRepeatedDeletionRequestKeepsTheOriginalGracePeriodStart(): void
    {
        $user = IdentityEntities::user();
        $first = IdentityEntities::now('2026-10-01T12:00:00+00:00');

        $user->requestDeletion($first);
        $user->requestDeletion(IdentityEntities::now('2026-10-20T12:00:00+00:00'));

        self::assertEquals($first, $user->deletionRequestedAt());
    }

    public function testCancelsDeletion(): void
    {
        $user = IdentityEntities::user();
        $user->requestDeletion(IdentityEntities::now('2026-10-01T12:00:00+00:00'));

        $user->cancelDeletion(IdentityEntities::now('2026-10-05T12:00:00+00:00'));

        self::assertSame(UserStatus::Active, $user->status());
        self::assertNull($user->deletionRequestedAt());
    }

    public function testCancellingWithoutAPendingDeletionIsANoop(): void
    {
        $created = IdentityEntities::now();
        $user = IdentityEntities::user(now: $created);

        $user->cancelDeletion(IdentityEntities::now('2026-10-05T12:00:00+00:00'));

        self::assertSame(UserStatus::Active, $user->status());
        self::assertEquals($created, $user->updatedAt());
    }
}
