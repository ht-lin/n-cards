<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Time;

use App\Shared\Infrastructure\Time\SystemClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SystemClock::class)]
final class SystemClockTest extends TestCase
{
    public function testNowIsAlwaysUtc(): void
    {
        // §6.1：时间一律 RFC 3339 UTC。带本地时区的时间戳流进 change_log
        // 会让同步的游标比较出错。
        self::assertSame('UTC', (new SystemClock())->now()->getTimezone()->getName());
    }

    public function testNowMillisLooksLikeAUnixMillisecondTimestamp(): void
    {
        $millis = (new SystemClock())->nowMillis();

        // 2020-01-01 与 2100-01-01 之间 —— 抓的是「单位搞错了」（秒当成毫秒之类）。
        self::assertGreaterThan(1_577_836_800_000, $millis);
        self::assertLessThan(4_102_444_800_000, $millis);
    }

    /**
     * 两个方法必须自洽 —— 这正是实现里只读一次 microtime 的理由。
     */
    public function testNowAndNowMillisAgree(): void
    {
        $clock = new SystemClock();

        $before = $clock->nowMillis();
        $now = $clock->now();
        $after = $clock->nowMillis();

        $fromDate = (int) $now->format('Uv');

        self::assertGreaterThanOrEqual($before, $fromDate);
        self::assertLessThanOrEqual($after, $fromDate);
    }

    /**
     * 毫秒精度不能被截断掉 —— UUIDv7 的单调计数器靠的就是毫秒分辨率。
     */
    public function testKeepsMillisecondPrecision(): void
    {
        $clock = new SystemClock();
        $seen = [];

        // 取若干次，只要有一次的毫秒部分非零，就说明精度没被砍到秒。
        for ($i = 0; $i < 200; ++$i) {
            $seen[] = (int) $clock->now()->format('v');

            if (0 !== end($seen)) {
                self::assertNotSame(0, end($seen));

                return;
            }

            usleep(1000);
        }

        self::fail('200 次采样的毫秒部分全是 0 —— 精度被截断到秒了');
    }

    public function testTimeMovesForward(): void
    {
        $clock = new SystemClock();

        $first = $clock->nowMillis();
        usleep(2000);

        self::assertGreaterThan($first, $clock->nowMillis());
    }
}
