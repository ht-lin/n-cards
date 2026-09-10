<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Domain\Config;

use App\Shared\Domain\Config\MaintenanceMessageKey;
use App\Shared\Domain\Config\MaintenanceStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * `retry_after` 与 `active` 的绑定（§9.2，T-112）。
 *
 * 这条不变量在 {@see MaintenanceWindow} 那边已经成立，所以这个文件测的是
 * **第二道防线**：将来有人在别处手工构造一个 `MaintenanceStatus`（比如给客户端
 * 做一个「预览公告」的运维端点），绑定不能靠他记得。
 *
 * 违反它在服务端这侧没有任何症状 —— 坏的是客户端：公告期按一个不存在的秒数倒计时，
 * 或者维护中却没有倒计时。
 */
#[CoversClass(MaintenanceStatus::class)]
final class MaintenanceStatusTest extends TestCase
{
    public function testNoneIsInactiveAndCarriesNothing(): void
    {
        $status = MaintenanceStatus::none();

        self::assertFalse($status->active);
        self::assertNull($status->messageKey);
        self::assertNull($status->retryAfter);
    }

    public function testAnnouncedButNotActiveCarriesNoRetryAfter(): void
    {
        $status = new MaintenanceStatus(false, MaintenanceMessageKey::Scheduled);

        self::assertFalse($status->active);
        self::assertSame(MaintenanceMessageKey::Scheduled, $status->messageKey);
        self::assertNull($status->retryAfter);
    }

    public function testActiveCarriesARetryAfter(): void
    {
        $status = new MaintenanceStatus(true, MaintenanceMessageKey::InProgress, 1800);

        self::assertTrue($status->active);
        self::assertSame(1800, $status->retryAfter);
    }

    public function testActiveWithoutARetryAfterIsRejected(): void
    {
        self::expectException(\LogicException::class);
        self::expectExceptionMessage('set exactly when $active is true');

        new MaintenanceStatus(true, MaintenanceMessageKey::InProgress);
    }

    public function testRetryAfterWithoutActiveIsRejected(): void
    {
        self::expectException(\LogicException::class);
        self::expectExceptionMessage('set exactly when $active is true');

        new MaintenanceStatus(false, MaintenanceMessageKey::Scheduled, 1800);
    }

    /**
     * 0 的意思是「立刻重试」，与「还在维护中」自相矛盾。
     */
    public function testZeroRetryAfterIsRejected(): void
    {
        self::expectException(\LogicException::class);
        self::expectExceptionMessage('at least 1 second');

        new MaintenanceStatus(true, MaintenanceMessageKey::InProgress, 0);
    }
}
