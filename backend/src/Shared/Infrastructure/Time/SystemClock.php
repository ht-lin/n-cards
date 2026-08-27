<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Time;

use App\Shared\Domain\Time\ClockInterface;

/**
 * 真实时钟。
 *
 * ⚠️ **两个方法必须从同一次时间读取派生。**
 * 如果 `now()` 走 `new \DateTimeImmutable()`、`nowMillis()` 走 `microtime()`，
 * 两次调用之间会跨毫秒边界，于是同一个请求里「事件发生时刻」与
 * 「UUIDv7 时间戳」会对不上 —— 这种偏差极少发生、发生了极难查。
 * 所以这里只读一次 `microtime(true)`，其余全部从那个值算出来。
 *
 * 恒为 UTC（§6.1：时间一律 RFC 3339 UTC）。
 */
final readonly class SystemClock implements ClockInterface
{
    public function now(): \DateTimeImmutable
    {
        return self::fromMillis($this->nowMillis());
    }

    public function nowMillis(): int
    {
        return (int) round(microtime(true) * 1000);
    }

    private static function fromMillis(int $millis): \DateTimeImmutable
    {
        // `@<seconds>` 构造出的对象带 +00:00 时区，再显式钉一次 UTC，
        // 免得将来有人加了 date_default_timezone_set 就悄悄漂了。
        return (new \DateTimeImmutable(
            \sprintf('@%d.%03d', intdiv($millis, 1000), $millis % 1000),
        ))->setTimezone(new \DateTimeZone('UTC'));
    }
}
