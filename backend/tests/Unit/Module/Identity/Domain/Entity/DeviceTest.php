<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Identity\Domain\Entity;

use App\Module\Identity\Domain\Entity\Device;
use App\Tests\Double\Identity\IdentityEntities;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Device::class)]
final class DeviceTest extends TestCase
{
    public function testRegistrationStartsUnrevokedWithoutPushToken(): void
    {
        $now = IdentityEntities::now();
        $device = IdentityEntities::device(now: $now);

        self::assertFalse($device->isRevoked());
        self::assertNull($device->revokedAt());
        self::assertNull($device->pushToken());
        self::assertNull($device->pushTokenUpdatedAt());
        self::assertEquals($now, $device->lastSeenAt());
        self::assertEquals($now, $device->createdAt());
    }

    public function testKeepsTheIdentityItWasRegisteredWith(): void
    {
        $user = IdentityEntities::user();
        $id = IdentityEntities::id(7);

        $device = IdentityEntities::device($user, $id);

        self::assertTrue($id->equals($device->id()));
        self::assertSame($user, $device->user());
        self::assertSame('Pixel 6a', $device->model());
        self::assertSame('Android 14', $device->osVersion());
        self::assertSame('1.0.0', $device->appVersion());
    }

    public function testUpdatesTheDisplayedDescription(): void
    {
        $device = IdentityEntities::device();

        $device->describe('Pixel 8', 'Android 15', '1.4.0');

        self::assertSame('Pixel 8', $device->model());
        self::assertSame('Android 15', $device->osVersion());
        self::assertSame('1.4.0', $device->appVersion());
    }

    /**
     * 令牌与时间戳必须一起写：分开的话会出现「有令牌但不知道多旧」的行，
     * 而 FCM 令牌会过期，§4.4 的推送链路要靠这个时间判断该不该催客户端刷新。
     */
    public function testUpdatesPushTokenTogetherWithItsTimestamp(): void
    {
        $device = IdentityEntities::device();
        $at = IdentityEntities::now('2026-09-06T09:00:00+00:00');

        $device->updatePushToken('fcm-token-1', $at);

        self::assertSame('fcm-token-1', $device->pushToken());
        self::assertEquals($at, $device->pushTokenUpdatedAt());
    }

    public function testTouchesLastSeen(): void
    {
        $device = IdentityEntities::device();
        $at = IdentityEntities::now('2026-09-07T09:00:00+00:00');

        $device->touch($at);

        self::assertEquals($at, $device->lastSeenAt());
    }

    /**
     * ROPA §8.2 的设备行保留期是「设备撤销后即删」，而 push_token 是里面唯一会被
     * 发给第三方（FCM / Google Ireland）的字段 —— 撤销时必须一并清掉。
     */
    public function testRevokingClearsThePushToken(): void
    {
        $device = IdentityEntities::device();
        $device->updatePushToken('fcm-token-1', IdentityEntities::now('2026-09-06T09:00:00+00:00'));
        $revokedAt = IdentityEntities::now('2026-09-08T09:00:00+00:00');

        $device->revoke($revokedAt);

        self::assertTrue($device->isRevoked());
        self::assertEquals($revokedAt, $device->revokedAt());
        self::assertNull($device->pushToken());
    }

    /**
     * 幂等且不重置时间戳 —— 那是「什么时候被踢下线的」这个事实，
     * 安全提醒邮件与 audit_log 都引用它。
     */
    public function testRepeatedRevocationKeepsTheOriginalInstant(): void
    {
        $device = IdentityEntities::device();
        $first = IdentityEntities::now('2026-09-08T09:00:00+00:00');

        $device->revoke($first);
        $device->revoke(IdentityEntities::now('2026-09-09T09:00:00+00:00'));

        self::assertEquals($first, $device->revokedAt());
    }
}
