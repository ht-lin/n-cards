<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Identity\Application\Session;

use App\Module\Identity\Application\Session\DeviceService;
use App\Module\Identity\Domain\Entity\Device;
use App\Module\Identity\Domain\Entity\User;
use App\Module\Identity\Domain\ValueObject\SessionRevokedReason;
use App\Shared\Domain\Error\DomainException;
use App\Shared\Domain\Error\ErrorCode;
use App\Shared\Domain\Http\AuthContext;
use App\Shared\Domain\Identity\Uuid;
use App\Tests\Double\Identity\IdentityEntities;
use App\Tests\Double\Identity\InMemoryDeviceRepository;
use App\Tests\Double\Identity\InMemorySessionRepository;
use App\Tests\Double\Metrics\RecordingMetrics;
use App\Tests\Double\Time\FrozenClock;
use App\Tests\Double\Transaction\RecordingTransactionRunner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * 设备管理页的三个端点（§6.2 / §7.2 T02，T-105）。
 *
 * ============================================================================
 * 两条不变量，各占一半用例
 * ============================================================================
 * 1. **别人的设备一律 404，不是 403。** `devices.id` 由客户端生成、不是凭据，
 *    403 等于确认「这个 id 存在，只是不是你的」—— 而设备 id 会出现在
 *    另一个用户的设备管理页上。
 *
 * 2. **远程登出必须同时撤销会话。** 只撤设备的话，那台机器手里的 refresh token
 *    仍然有效 90 天 —— 而「远程登出后该设备的 refresh 立即失效」正是本卡
 *    验收标准的第二条。{@see testRevokingADeviceAlsoKillsItsSessions()}。
 */
#[CoversClass(DeviceService::class)]
final class DeviceServiceTest extends TestCase
{
    private const NOW = '2026-09-06T12:00:00+00:00';

    private InMemoryDeviceRepository $devices;

    private InMemorySessionRepository $sessions;

    private RecordingMetrics $metrics;

    private FrozenClock $clock;

    private User $user;

    private Device $current;

    private Device $other;

    protected function setUp(): void
    {
        $this->clock = new FrozenClock(IdentityEntities::now(self::NOW)->getTimestamp() * 1000);

        $now = IdentityEntities::now(self::NOW);

        $this->user = IdentityEntities::user(now: $now);
        $this->current = IdentityEntities::device($this->user, IdentityEntities::id(41), $now);
        $this->other = IdentityEntities::device($this->user, IdentityEntities::id(42), $now);

        $this->devices = new InMemoryDeviceRepository($this->current, $this->other);
        $this->sessions = new InMemorySessionRepository();
        $this->metrics = new RecordingMetrics();
    }

    // ========================================================================
    // 列表
    // ========================================================================

    public function testListsTheUsersDevicesAndMarksTheCurrentOne(): void
    {
        $views = $this->service()->list($this->auth());

        self::assertCount(2, $views);

        $byId = [];

        foreach ($views as $view) {
            $byId[$view->id->toString()] = $view;
        }

        self::assertTrue($byId[$this->current->id()->toString()]->isCurrent);
        self::assertFalse($byId[$this->other->id()->toString()]->isCurrent);
    }

    /**
     * ⚠️ 「当前」由 access token 的 `did` 决定，**不是**「last_seen_at 最新的那台」。
     *
     * 后者在两台设备几乎同时活跃时会标错，而标错的后果是用户远程登出了
     * 自己正在用的手机。这里把另一台设成更晚活跃，标记仍然不该动。
     */
    public function testTheCurrentMarkerFollowsTheTokenNotTheMostRecentlyActiveDevice(): void
    {
        $this->other->touch($this->clock->now()->modify('+1 hour'));

        $views = $this->service()->list($this->auth());

        foreach ($views as $view) {
            self::assertSame($view->id->equals($this->current->id()), $view->isCurrent);
        }
    }

    /**
     * 已撤销的设备不出现在列表里 —— 否则「远程登出」看起来像没生效。
     */
    public function testRevokedDevicesAreNotListed(): void
    {
        $this->other->revoke($this->clock->now());

        $views = $this->service()->list($this->auth());

        self::assertCount(1, $views);
        self::assertTrue($views[0]->id->equals($this->current->id()));
    }

    public function testOtherUsersDevicesAreNotListed(): void
    {
        $stranger = IdentityEntities::user(IdentityEntities::id(90), now: IdentityEntities::now(self::NOW));
        $this->devices->save(IdentityEntities::device($stranger, IdentityEntities::id(91), IdentityEntities::now(self::NOW)));

        self::assertCount(2, $this->service()->list($this->auth()));
    }

    // ========================================================================
    // ⚠️ 验收标准第二条：远程登出
    // ========================================================================

    /**
     * 撤销设备**并且**撤销它的会话。
     *
     * `Device::revoke()` 的注释明确写着「撤销设备不会自动撤销它的会话，
     * 那是 T-105 的编排」—— 只调它就交差的话，被踢的设备手里那枚 refresh token
     * 还能用 90 天。
     */
    public function testRevokingADeviceAlsoKillsItsSessions(): void
    {
        $session = IdentityEntities::session($this->user, $this->other, IdentityEntities::id(51), now: IdentityEntities::now(self::NOW));
        $this->sessions->save($session);

        $this->service()->revoke($this->auth(), $this->other->id());

        self::assertTrue($this->other->isRevoked());
        self::assertTrue($session->isRevoked());
        self::assertSame(SessionRevokedReason::UserRevoked, $session->revokedReason());
        self::assertFalse($session->isActiveAt($this->clock->now()));
    }

    /**
     * 别的设备的会话不受影响 —— 撤销一台不该把用户从所有设备上踢下线。
     */
    public function testOtherDevicesSessionsSurvive(): void
    {
        $mine = IdentityEntities::session($this->user, $this->current, IdentityEntities::id(52), now: IdentityEntities::now(self::NOW));
        $theirs = IdentityEntities::session($this->user, $this->other, IdentityEntities::id(53), now: IdentityEntities::now(self::NOW));
        $this->sessions->save($mine);
        $this->sessions->save($theirs);

        $this->service()->revoke($this->auth(), $this->other->id());

        self::assertFalse($mine->isRevoked());
        self::assertTrue($theirs->isRevoked());
    }

    /**
     * ROPA §8.2：「设备撤销后即删 push_token」。它是设备行里唯一会被发给
     * 第三方（FCM / Google Ireland）的字段。
     */
    public function testRevokingClearsThePushToken(): void
    {
        $this->other->updatePushToken('fcm-token-value', $this->clock->now());

        $this->service()->revoke($this->auth(), $this->other->id());

        self::assertNull($this->other->pushToken());
    }

    /**
     * ⚠️ 已因 `reuse_detected` 撤销的会话不会被改写成 `user_revoked` ——
     * 仓储逐行走 `Session::revoke()`，而那个方法首个 reason 胜出。
     */
    public function testASecurityIncidentReasonSurvivesARemoteLogout(): void
    {
        $session = IdentityEntities::session($this->user, $this->other, IdentityEntities::id(54), now: IdentityEntities::now(self::NOW));
        $session->revoke(SessionRevokedReason::ReuseDetected, $this->clock->now());
        $this->sessions->save($session);

        $this->service()->revoke($this->auth(), $this->other->id());

        self::assertSame(SessionRevokedReason::ReuseDetected, $session->revokedReason());
    }

    public function testRevokingIsIdempotentAndKeepsTheFirstTimestamp(): void
    {
        $this->service()->revoke($this->auth(), $this->other->id());
        $first = $this->other->revokedAt();

        $this->clock->advance(60_000);
        $this->service()->revoke($this->auth(), $this->other->id());

        self::assertSame($first?->getTimestamp(), $this->other->revokedAt()?->getTimestamp());
    }

    /**
     * 允许踢掉自己当前这台 —— 效果等同登出。禁止它没有安全收益，
     * 只会让「我丢了这台手机，但手上就这一台」变成做不到的事。
     */
    public function testAUserMayRevokeTheDeviceTheyAreCallingFrom(): void
    {
        $this->service()->revoke($this->auth(), $this->current->id());

        self::assertTrue($this->current->isRevoked());
    }

    public function testTheRevocationRunsInsideATransaction(): void
    {
        $transactions = new RecordingTransactionRunner();

        $this->service($transactions)->revoke($this->auth(), $this->other->id());

        self::assertSame(1, $transactions->runs());
    }

    // ========================================================================
    // push token
    // ========================================================================

    public function testStoresThePushTokenWithItsTimestamp(): void
    {
        $this->service()->updatePushToken($this->auth(), $this->current->id(), 'fcm-token-value');

        self::assertSame('fcm-token-value', $this->current->pushToken());
        self::assertSame($this->clock->now()->getTimestamp(), $this->current->pushTokenUpdatedAt()?->getTimestamp());
    }

    /**
     * `null` 是「用户关掉了通知权限」，不是「没传」。清掉之后
     * §14.4 的 `fcm_send_total` 才不会被一堆必然失败的投递污染。
     */
    public function testANullTokenClearsIt(): void
    {
        $this->current->updatePushToken('fcm-token-value', $this->clock->now());

        $this->service()->updatePushToken($this->auth(), $this->current->id(), null);

        self::assertNull($this->current->pushToken());
    }

    /**
     * ⚠️ 已撤销的设备拒收。允许的话，一台被远程登出的设备可以继续刷新
     * push_token 把自己留在推送目标里，而用户以为它已经被踢掉了 ——
     * 直接与 §5.2「设备撤销后即删 push_token」冲突。
     */
    public function testARevokedDeviceCannotReportAPushToken(): void
    {
        $this->service()->revoke($this->auth(), $this->other->id());

        $this->expectNotFound(fn () => $this->service()->updatePushToken($this->auth(), $this->other->id(), 'fcm'));
    }

    // ========================================================================
    // ⚠️ 归属：一律 404
    // ========================================================================

    /**
     * 三个端点共用同一个入口，所以三条都要断言 —— 漏掉任何一个就是一个
     * 可以操作别人设备的洞。
     */
    public function testAnotherUsersDeviceIsNotFoundOnEveryEndpoint(): void
    {
        $stranger = IdentityEntities::user(IdentityEntities::id(90), now: IdentityEntities::now(self::NOW));
        $theirs = IdentityEntities::device($stranger, IdentityEntities::id(91), IdentityEntities::now(self::NOW));
        $this->devices->save($theirs);

        $this->expectNotFound(fn () => $this->service()->revoke($this->auth(), $theirs->id()));
        $this->expectNotFound(fn () => $this->service()->updatePushToken($this->auth(), $theirs->id(), 'fcm'));

        // 而且什么都没被改动。
        self::assertFalse($theirs->isRevoked());
        self::assertNull($theirs->pushToken());
    }

    /**
     * 「不存在」与「不是你的」必须**逐字**同一个响应 —— 差别可辨认的话，
     * 它就是一个「这个设备 id 存不存在」的探测接口。
     */
    public function testAMissingDeviceAndAForeignDeviceAreIndistinguishable(): void
    {
        $stranger = IdentityEntities::user(IdentityEntities::id(90), now: IdentityEntities::now(self::NOW));
        $theirs = IdentityEntities::device($stranger, IdentityEntities::id(91), IdentityEntities::now(self::NOW));
        $this->devices->save($theirs);

        $foreign = $this->notFoundOf(fn () => $this->service()->revoke($this->auth(), $theirs->id()));
        $missing = $this->notFoundOf(fn () => $this->service()->revoke($this->auth(), IdentityEntities::id(777)));

        self::assertSame($missing->errorCode(), $foreign->errorCode());
        self::assertSame($missing->getMessage(), $foreign->getMessage());
    }

    // ========================================================================
    // 支撑
    // ========================================================================

    private function auth(?Uuid $deviceId = null): AuthContext
    {
        return new AuthContext($this->user->id(), IdentityEntities::id(50), $deviceId ?? $this->current->id());
    }

    private function service(?RecordingTransactionRunner $transactions = null): DeviceService
    {
        return new DeviceService(
            $this->devices,
            $this->sessions,
            $transactions ?? new RecordingTransactionRunner(),
            $this->metrics,
            $this->clock,
        );
    }

    private function expectNotFound(callable $call): void
    {
        self::assertSame(ErrorCode::NotFound, $this->notFoundOf($call)->errorCode());
    }

    private function notFoundOf(callable $call): DomainException
    {
        try {
            $call();
        } catch (DomainException $e) {
            return $e;
        }

        self::fail('The call must have been rejected with 404.');
    }
}
