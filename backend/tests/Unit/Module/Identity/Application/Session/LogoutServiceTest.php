<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Identity\Application\Session;

use App\Module\Identity\Application\Session\LogoutService;
use App\Module\Identity\Domain\ValueObject\SessionRevokedReason;
use App\Tests\Double\Identity\IdentityEntities;
use App\Tests\Double\Identity\InMemorySessionRepository;
use App\Tests\Double\Metrics\RecordingMetrics;
use App\Tests\Double\Time\FrozenClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * `POST /v1/auth/logout`（§7.1，T-105）。
 *
 * 这个服务短到几乎没有分支，所以用例的重点全在**幂等**与
 * **「不覆盖已有的撤销原因」**上 —— 后者是安全事件调查的依据。
 */
#[CoversClass(LogoutService::class)]
final class LogoutServiceTest extends TestCase
{
    private const NOW = '2026-09-06T12:00:00+00:00';

    private InMemorySessionRepository $sessions;

    private RecordingMetrics $metrics;

    private FrozenClock $clock;

    protected function setUp(): void
    {
        $this->sessions = new InMemorySessionRepository();
        $this->metrics = new RecordingMetrics();
        $this->clock = new FrozenClock(IdentityEntities::now(self::NOW)->getTimestamp() * 1000);
    }

    public function testRevokesTheCurrentSessionWithTheLogoutReason(): void
    {
        $session = IdentityEntities::session(now: IdentityEntities::now(self::NOW));
        $this->sessions->save($session);

        $this->service()->logout($session->id());

        self::assertTrue($session->isRevoked());
        self::assertSame(SessionRevokedReason::Logout, $session->revokedReason());
        self::assertSame($this->clock->now()->getTimestamp(), $session->revokedAt()?->getTimestamp());
    }

    /**
     * §7.1：「会话撤销后 refresh 立即失效」。这里断言的是那个前提 ——
     * 会话不再 active，于是 `RefreshTokenService` 会拒掉它。
     */
    public function testTheSessionIsNoLongerActiveAfterwards(): void
    {
        $session = IdentityEntities::session(now: IdentityEntities::now(self::NOW));
        $this->sessions->save($session);

        $this->service()->logout($session->id());

        self::assertFalse($session->isActiveAt($this->clock->now()));
    }

    /**
     * 契约规定恒 204。重复登出是客户端重试的正常形态 ——
     * 报错只会让它无法收敛。
     */
    public function testLoggingOutTwiceIsIdempotentAndKeepsTheFirstTimestamp(): void
    {
        $session = IdentityEntities::session(now: IdentityEntities::now(self::NOW));
        $this->sessions->save($session);

        $this->service()->logout($session->id());
        $firstRevokedAt = $session->revokedAt();

        $this->clock->advance(60_000);
        $this->service()->logout($session->id());

        self::assertSame($firstRevokedAt?->getTimestamp(), $session->revokedAt()?->getTimestamp());
        // 第二次不该再打一次点，否则「有多少人登出了」这条曲线会被重试放大。
        self::assertCount(1, $this->metrics->labelsFor('session_revoked_total'));
    }

    /**
     * ⚠️ 本文件最重要的一条。
     *
     * 一条已因 `reuse_detected` 被撤销的会话，随后收到一次 logout ——
     * 留下的原因必须**仍然是**安全事件那个。被覆盖成 `logout` 的话，
     * 这个账号曾经发生过令牌被窃就再也查不出来了，而那正是攻击者
     * 拿着偷来的 access token 顺手做一次 logout 就能达到的效果。
     */
    public function testLogoutDoesNotOverwriteASecurityIncidentReason(): void
    {
        $session = IdentityEntities::session(now: IdentityEntities::now(self::NOW));
        $session->revoke(SessionRevokedReason::ReuseDetected, $this->clock->now());
        $this->sessions->save($session);

        $this->clock->advance(60_000);
        $this->service()->logout($session->id());

        self::assertSame(SessionRevokedReason::ReuseDetected, $session->revokedReason());
    }

    /**
     * 那一行不存在（被 T-113 的清理任务删了）。仍然 204，不声张 ——
     * 客户端要做的事（清掉本地令牌）与成功时完全相同。
     */
    public function testAnUnknownSessionIsSilentlyAccepted(): void
    {
        $this->service()->logout(IdentityEntities::id(999));

        self::assertSame([], $this->metrics->labelsFor('session_revoked_total'));
    }

    private function service(): LogoutService
    {
        return new LogoutService($this->sessions, $this->metrics, $this->clock);
    }
}
