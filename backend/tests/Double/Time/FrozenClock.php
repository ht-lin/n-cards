<?php

declare(strict_types=1);

namespace App\Tests\Double\Time;

use App\Shared\Domain\Time\ClockInterface;

/**
 * 停住的时钟，可手动推进。
 *
 * 用它而不是 symfony/phpunit-bridge 的 `ClockMock`（phpunit.xml.dist 里配了
 * `clock-mock-namespaces=App`）的理由：`ClockMock` 劫持的是全局函数，作用于整个
 * 命名空间且要靠 `#[Group('time-sensitive')]` 打标签；而这里要测的是
 * {@see \App\Shared\Domain\Identity\Uuid7Generator} 在**同一毫秒内**与**时钟回拨**下的行为，
 * 需要精确到「这一次调用返回这个毫秒数」的控制。显式注入更直接，也不会 flaky。
 */
final class FrozenClock implements ClockInterface
{
    public function __construct(private int $millis = 0)
    {
    }

    public function now(): \DateTimeImmutable
    {
        // 与 SystemClock 一样从毫秒派生，保证两个方法永远自洽。
        return (new \DateTimeImmutable('@'.intdiv($this->millis, 1000), new \DateTimeZone('UTC')))
            ->modify(\sprintf('+%d milliseconds', $this->millis % 1000));
    }

    public function nowMillis(): int
    {
        return $this->millis;
    }

    public function advance(int $millis): void
    {
        $this->millis += $millis;
    }

    /**
     * 直接设定 —— 用来构造「时钟回拨」（传一个比当前小的值）。
     */
    public function set(int $millis): void
    {
        $this->millis = $millis;
    }
}
