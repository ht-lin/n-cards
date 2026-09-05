<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Identity\Domain\Entity;

use App\Module\Identity\Domain\Entity\OtpChallenge;
use App\Shared\Domain\Error\DomainException;
use App\Shared\Domain\Error\ErrorCode;
use App\Tests\Double\Identity\IdentityEntities;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OtpChallenge::class)]
final class OtpChallengeTest extends TestCase
{
    public function testIssuedChallengeStartsUnusedAndUnconsumed(): void
    {
        $challenge = IdentityEntities::challenge();

        self::assertSame(0, $challenge->attempts());
        self::assertFalse($challenge->isConsumed());
        self::assertNull($challenge->consumedAt());
        self::assertFalse($challenge->isDecoy());
    }

    public function testDecoyChallengeIsFlagged(): void
    {
        // §3.8：邮箱不存在时创建的哑挑战 —— 不发信，验证恒失败，
        // 但响应体与耗时与真实路径不可区分。
        $challenge = IdentityEntities::decoyChallenge();

        self::assertTrue($challenge->isDecoy());
        // 哑挑战不发 Magic Link，所以那一列必然为空。
        self::assertNull($challenge->magicTokenHash());
    }

    public function testRecordsAttempts(): void
    {
        $challenge = IdentityEntities::challenge();

        $challenge->recordAttempt();
        $challenge->recordAttempt();

        self::assertSame(2, $challenge->attempts());
    }

    /**
     * §7.1 的最大尝试次数是 **5**，但那个数字是策略（T-104），不是实体的不变量 ——
     * 所以上限由调用方传入。这条用例钉住边界语义：第 5 次之后就没了。
     */
    public function testAttemptBudgetIsExhaustedAtTheGivenMaximum(): void
    {
        $challenge = IdentityEntities::challenge();

        for ($i = 0; $i < 4; ++$i) {
            $challenge->recordAttempt();
        }

        self::assertTrue($challenge->hasAttemptsLeft(5));

        $challenge->recordAttempt();

        self::assertFalse($challenge->hasAttemptsLeft(5));
    }

    public function testExpiryIsInclusiveOfTheExpiryInstant(): void
    {
        $now = IdentityEntities::now();
        $challenge = IdentityEntities::challenge(now: $now);
        $expiresAt = $now->modify('+10 minutes');

        self::assertFalse($challenge->isExpiredAt($expiresAt->modify('-1 second')));
        // 到点即过期，不给「正好那一秒」留缝。
        self::assertTrue($challenge->isExpiredAt($expiresAt));
        self::assertTrue($challenge->isExpiredAt($expiresAt->modify('+1 second')));
    }

    public function testConsumesOnce(): void
    {
        $challenge = IdentityEntities::challenge();
        $at = IdentityEntities::now('2026-09-05T10:20:00+00:00');

        $challenge->consume($at);

        self::assertTrue($challenge->isConsumed());
        self::assertEquals($at, $challenge->consumedAt());
    }

    /**
     * T-106 的验收标准：`POST` 消费一次后重复 POST 返回 401。
     * 静默成功等于把一次性令牌变成可重放的，而库层没有任何约束能拦住它。
     */
    public function testRefusesToConsumeTwice(): void
    {
        $challenge = IdentityEntities::challenge();
        $first = IdentityEntities::now('2026-09-05T10:20:00+00:00');
        $challenge->consume($first);

        try {
            $challenge->consume(IdentityEntities::now('2026-09-05T10:21:00+00:00'));
            self::fail('Expected DomainException.');
        } catch (DomainException $e) {
            self::assertSame(ErrorCode::TokenInvalid, $e->errorCode());
            self::assertSame(401, $e->errorCode()->httpStatus());
        }

        self::assertEquals($first, $challenge->consumedAt());
    }

    public function testKeepsTheDigestsItWasIssuedWith(): void
    {
        $emailHash = IdentityEntities::digest('anna');
        $codeHash = IdentityEntities::digest('123456');
        $magicTokenHash = IdentityEntities::digest('magic');
        $requestIpHash = IdentityEntities::digest('203.0.113.7');

        $challenge = IdentityEntities::challenge(
            emailHash: $emailHash,
            codeHash: $codeHash,
            magicTokenHash: $magicTokenHash,
            requestIpHash: $requestIpHash,
        );

        self::assertTrue($emailHash->equals($challenge->emailHash()));
        self::assertTrue($codeHash->equals($challenge->codeHash()));
        self::assertTrue($magicTokenHash->equals($challenge->magicTokenHash() ?? $codeHash));
        self::assertTrue($requestIpHash->equals($challenge->requestIpHash() ?? $codeHash));
    }

    /**
     * ROPA §8.2：`ip_hash` 保留 30 天。清理任务（T-113）把这一列清空而不是删整行 ——
     * 挑战行本身还要留着给「同一个 challenge_id 重复验证」的判定用。
     */
    public function testForgetsTheRequestIp(): void
    {
        $challenge = IdentityEntities::challenge(requestIpHash: IdentityEntities::digest('203.0.113.7'));

        $challenge->forgetRequestIp();

        self::assertNull($challenge->requestIpHash());
    }
}
