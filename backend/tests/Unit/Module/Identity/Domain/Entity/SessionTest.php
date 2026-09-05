<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Identity\Domain\Entity;

use App\Module\Identity\Domain\Entity\Session;
use App\Module\Identity\Domain\ValueObject\SessionRevokedReason;
use App\Shared\Domain\Error\DomainException;
use App\Shared\Domain\Error\ErrorCode;
use App\Tests\Double\Identity\IdentityEntities;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Session::class)]
final class SessionTest extends TestCase
{
    public function testStartsActiveWithoutAPreviousToken(): void
    {
        $now = IdentityEntities::now();
        $session = IdentityEntities::session(now: $now);

        self::assertNull($session->previousTokenHash());
        self::assertFalse($session->isRevoked());
        self::assertNull($session->revokedReason());
        self::assertTrue($session->isActiveAt($now));
        self::assertEquals($now->modify('+90 days'), $session->expiresAt());
    }

    public function testKeepsTheIdentityItWasStartedWith(): void
    {
        $user = IdentityEntities::user();
        $device = IdentityEntities::device($user);
        $id = IdentityEntities::id(9);
        $hash = IdentityEntities::digest('refresh-1');

        $session = IdentityEntities::session($user, $device, $id, $hash);

        self::assertTrue($id->equals($session->id()));
        self::assertSame($user, $session->user());
        self::assertSame($device, $session->device());
        self::assertTrue($hash->equals($session->refreshTokenHash()));
    }

    /**
     * §7.1：每次刷新签发新 refresh token，旧的立即失效并记进 previous_token_hash。
     */
    public function testRotationMovesTheCurrentHashToPrevious(): void
    {
        $now = IdentityEntities::now();
        $first = IdentityEntities::digest('refresh-1');
        $second = IdentityEntities::digest('refresh-2');
        $session = IdentityEntities::session(refreshTokenHash: $first, now: $now);

        $session->rotate($second, $now->modify('+90 days'));

        self::assertTrue($second->equals($session->refreshTokenHash()));
        self::assertTrue($first->equals($session->previousTokenHash() ?? $second));
    }

    /**
     * ⚠️ **只保留一代**，不是一条链。客户端任意时刻手里只有一个 refresh token，
     * 更早的它自己已经丢弃了 —— 留整条链没有额外检出能力，只会让这一行无限增长。
     */
    public function testRotationKeepsOnlyOneGenerationOfHistory(): void
    {
        $now = IdentityEntities::now();
        $session = IdentityEntities::session(
            refreshTokenHash: IdentityEntities::digest('refresh-1'),
            now: $now,
        );
        $second = IdentityEntities::digest('refresh-2');
        $third = IdentityEntities::digest('refresh-3');

        $session->rotate($second, $now->modify('+90 days'));
        $session->rotate($third, $now->modify('+91 days'));

        self::assertTrue($third->equals($session->refreshTokenHash()));
        self::assertTrue($second->equals($session->previousTokenHash() ?? $third));
    }

    /**
     * §7.1 的 90 天**滑动**过期：每次轮换顺延。
     */
    public function testRotationExtendsTheExpiry(): void
    {
        $now = IdentityEntities::now();
        $session = IdentityEntities::session(now: $now);
        $extended = $now->modify('+120 days');

        $session->rotate(IdentityEntities::digest('refresh-2'), $extended);

        self::assertEquals($extended, $session->expiresAt());
    }

    public function testRefusesToRotateARevokedSession(): void
    {
        $now = IdentityEntities::now();
        $session = IdentityEntities::session(now: $now);
        $session->revoke(SessionRevokedReason::Logout, $now);

        try {
            $session->rotate(IdentityEntities::digest('refresh-2'), $now->modify('+90 days'));
            self::fail('Expected DomainException.');
        } catch (DomainException $e) {
            self::assertSame(ErrorCode::TokenInvalid, $e->errorCode());
            self::assertSame(401, $e->errorCode()->httpStatus());
        }
    }

    public function testRevokesWithAReason(): void
    {
        $now = IdentityEntities::now();
        $session = IdentityEntities::session(now: $now);

        $session->revoke(SessionRevokedReason::UserRevoked, $now);

        self::assertTrue($session->isRevoked());
        self::assertSame(SessionRevokedReason::UserRevoked, $session->revokedReason());
        self::assertEquals($now, $session->revokedAt());
        self::assertFalse($session->isActiveAt($now));
    }

    /**
     * ⚠️ 首个 reason 胜出。一条会话先因 reuse_detected 被撤销、随后又被账号删除
     * 流程扫到时，留下的必须是安全事件那个原因 —— 它是 audit_log 与告警的依据，
     * 被 account_deleted 覆盖掉就再也查不出这个账号曾经发生过令牌被窃。
     */
    public function testFirstRevocationReasonWins(): void
    {
        $now = IdentityEntities::now();
        $session = IdentityEntities::session(now: $now);

        $session->revoke(SessionRevokedReason::ReuseDetected, $now);
        $session->revoke(SessionRevokedReason::AccountDeleted, $now->modify('+1 day'));

        self::assertSame(SessionRevokedReason::ReuseDetected, $session->revokedReason());
        self::assertEquals($now, $session->revokedAt());
    }

    public function testExpiryIsInclusiveOfTheExpiryInstant(): void
    {
        $now = IdentityEntities::now();
        $session = IdentityEntities::session(now: $now);
        $expiresAt = $now->modify('+90 days');

        self::assertFalse($session->isExpiredAt($expiresAt->modify('-1 second')));
        self::assertTrue($session->isExpiredAt($expiresAt));
        self::assertFalse($session->isActiveAt($expiresAt));
    }
}
