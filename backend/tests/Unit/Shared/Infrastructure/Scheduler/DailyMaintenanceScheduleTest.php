<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Scheduler;

use App\Shared\Application\Cleanup\RunDailyCleanup;
use App\Shared\Infrastructure\Scheduler\DailyMaintenanceSchedule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Scheduler\Generator\MessageContext;
use Symfony\Component\Scheduler\RecurringMessage;

/**
 * 时刻表本身（T-113 / ADR-0022）。
 *
 * ============================================================================
 * 为什么「几点跑」值得写测试
 * ============================================================================
 * §9.2 的计划内维护窗口是**每周二 03:00–04:00 CET**。清理落进窗口里会让
 * 「周二的清理没跑」与「周二本来就停服」变成同一个现象，而区分它们要靠翻部署记录。
 *
 * 而「04:30 是不是真的落在窗口外」取决于一件容易写错的事：时区。
 * 容器的 PHP 默认时区是 UTC，`RecurringMessage::cron()` 不传时区就用它 ——
 * 那样「04:30」会随夏令时在柏林的 05:30 与 06:30 之间跳，一年两次地漂。
 * 漂进窗口里不会有任何症状，直到某个周二有人问「昨晚清理跑了吗」。
 *
 * 所以下面用**跨夏令时切换**的两条断言把它钉死：一条在 CEST（UTC+2）、
 * 一条在 CET（UTC+1）。两条都要求本地墙钟是 04:30，而 UTC 时刻不同 ——
 * 那正是「传了时区」与「没传时区」的可观测差异。
 */
#[CoversClass(DailyMaintenanceSchedule::class)]
final class DailyMaintenanceScheduleTest extends TestCase
{
    public function testDispatchesExactlyOneRecurringMessage(): void
    {
        $messages = (new DailyMaintenanceSchedule())->getSchedule()->getRecurringMessages();

        self::assertCount(1, $messages);
    }

    public function testTheScheduledMessageIsTheDailyCleanup(): void
    {
        $messages = iterator_to_array($this->onlyMessage()->getMessages(new MessageContext(
            'default',
            'test',
            $this->onlyMessage()->getTrigger(),
            new \DateTimeImmutable('2026-07-01T02:30:00+00:00'),
        )), false);

        self::assertCount(1, $messages);
        self::assertInstanceOf(RunDailyCleanup::class, $messages[0]);
    }

    /**
     * 夏令时期间（CEST = UTC+2）：柏林 04:30 == 02:30 UTC。
     */
    public function testRunsAtHalfPastFourBerlinTimeDuringSummerTime(): void
    {
        $next = $this->nextRunAfter('2026-07-01T00:00:00+00:00');

        self::assertSame('2026-07-01 04:30', $this->inBerlin($next));
        self::assertSame('2026-07-01T02:30:00+00:00', $this->inUtc($next));
    }

    /**
     * 冬令时期间（CET = UTC+1）：柏林 04:30 == 03:30 UTC。
     *
     * ⚠️ 与上一条的 UTC 时刻**不同**而本地时刻相同 —— 这就是时区参数买到的东西。
     * 不传时区的实现会让这两条里的一条红。
     */
    public function testRunsAtHalfPastFourBerlinTimeDuringWinterTime(): void
    {
        $next = $this->nextRunAfter('2026-12-01T00:00:00+00:00');

        self::assertSame('2026-12-01 04:30', $this->inBerlin($next));
        self::assertSame('2026-12-01T03:30:00+00:00', $this->inUtc($next));
    }

    /**
     * 夏令时切换当天也不例外 —— 2026-10-25 是欧洲回拨日（03:00 CEST → 02:00 CET）。
     * 那天 04:30 只出现一次，且在回拨之后。
     */
    public function testSurvivesTheDstFallBackDay(): void
    {
        $next = $this->nextRunAfter('2026-10-25T00:00:00+00:00');

        self::assertSame('2026-10-25 04:30', $this->inBerlin($next));
    }

    /**
     * ⚠️ **红了说明清理被排进了维护窗口。** §9.2：每周二 03:00–04:00 CET。
     *
     * 取一个周二（2026-09-15）来验，因为窗口只在周二存在。
     */
    public function testTheNextRunOnATuesdayIsOutsideTheMaintenanceWindow(): void
    {
        $next = $this->nextRunAfter('2026-09-15T00:00:00+00:00');

        $berlin = $next->setTimezone(new \DateTimeZone('Europe/Berlin'));

        self::assertSame('Tuesday', $berlin->format('l'), 'This case only means something on a Tuesday.');
        self::assertGreaterThanOrEqual(
            '04:00',
            $berlin->format('H:i'),
            '§9.2 的维护窗口是周二 03:00–04:00 CET，清理必须排在它之后。',
        );
    }

    /**
     * 每天一次，不是每小时或每周 —— 相邻两次触发正好差 24 小时（同一时区内）。
     */
    public function testRunsOncePerDay(): void
    {
        $first = $this->nextRunAfter('2026-07-01T00:00:00+00:00');
        $second = $this->onlyMessage()->getTrigger()->getNextRunDate($first);

        self::assertNotNull($second);
        self::assertSame('2026-07-02 04:30', $this->inBerlin($second));
    }

    private function onlyMessage(): RecurringMessage
    {
        $messages = (new DailyMaintenanceSchedule())->getSchedule()->getRecurringMessages();

        self::assertArrayHasKey(0, $messages);

        return $messages[0];
    }

    private function nextRunAfter(string $after): \DateTimeImmutable
    {
        $next = $this->onlyMessage()->getTrigger()->getNextRunDate(new \DateTimeImmutable($after));

        self::assertNotNull($next);

        return $next;
    }

    private function inBerlin(\DateTimeImmutable $moment): string
    {
        return $moment->setTimezone(new \DateTimeZone('Europe/Berlin'))->format('Y-m-d H:i');
    }

    /**
     * ⚠️ 必须先 `setTimezone()` 再 format。触发器返回的对象**带着柏林时区**，
     * 直接 format 出来的字符串是 `+02:00` / `+01:00` 形态 —— 两条断言会各自
     * 「看起来对」，而它们要比的恰恰是同一个本地时刻在 UTC 上的**不同**位置。
     */
    private function inUtc(\DateTimeImmutable $moment): string
    {
        return $moment->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::RFC3339);
    }
}
