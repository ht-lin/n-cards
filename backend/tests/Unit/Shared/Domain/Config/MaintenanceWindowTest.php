<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Domain\Config;

use App\Shared\Domain\Config\MaintenanceMessageKey;
use App\Shared\Domain\Config\MaintenanceWindow;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * 维护窗口的四段判定与两处边界（§9.2，T-112）。
 *
 * ============================================================================
 * 为什么这个文件值得这么细
 * ============================================================================
 * 这是 T-112 里**唯一有分支的逻辑**。端点的其余部分是「把三个配置值换成 JSON」，
 * 一眼能看出对错；而「现在算不算在窗口里」「算不算在公告期里」有四段、两个边界，
 * 且每一处错的症状都在服务端这侧**完全不可见** —— 横幅早一天、晚一天、或者永远
 * 挂着，只有用户看得见。
 *
 * 所以边界逐个钉：含/不含写成两条相邻的用例（`start - 86400` 与 `start - 86401`，
 * `end - 1` 与 `end`），这样「把 `>=` 写成 `>`」这种改动一定会红。
 */
#[CoversClass(MaintenanceWindow::class)]
final class MaintenanceWindowTest extends TestCase
{
    /** 窗口本体：2026-09-15 01:00:00Z – 02:00:00Z（= 03:00–04:00 CEST，§9.2 的那个窗口）。 */
    private const START = '2026-09-15T03:00:00+02:00';
    private const END = '2026-09-15T04:00:00+02:00';

    private const START_TS = 1789434000; // 2026-09-15T01:00:00Z
    private const END_TS = 1789437600;   // 2026-09-15T02:00:00Z

    /**
     * @return iterable<string, array{int, bool, MaintenanceMessageKey|null, int|null}>
     */
    public static function instants(): iterable
    {
        // 公告期还没开始 —— 提前量是 24 小时，多一秒都不公告。
        yield '24h + 1s 之前' => [self::START_TS - 86401, false, null, null];

        // ⚠️ 与上一条相邻。这两条一起钉住 ANNOUNCEMENT_LEAD_SECONDS 这个边界是闭的。
        yield '整好 24 小时之前（含）' => [self::START_TS - 86400, false, MaintenanceMessageKey::Scheduled, null];

        yield '窗口开始前 1 秒' => [self::START_TS - 1, false, MaintenanceMessageKey::Scheduled, null];

        // ⚠️ active 翻转的那一刻。retry_after 这时才第一次有值，且等于整个窗口长度。
        yield '窗口开始那一刻（含）' => [self::START_TS, true, MaintenanceMessageKey::InProgress, 3600];

        yield '窗口过半' => [self::START_TS + 1800, true, MaintenanceMessageKey::InProgress, 1800];

        // ⚠️ 这一条钉住「retry_after 恒 ≥ 1」—— 写成截断取整会在这里给出 0，
        // 而 0 的意思是「立刻重试」，与「还在维护中」自相矛盾。
        yield '窗口结束前 1 秒' => [self::END_TS - 1, true, MaintenanceMessageKey::InProgress, 1];

        // ⚠️ 与上一条相邻。半开区间 [start, end)：end 那一刻已经**不在**窗口内。
        yield '窗口结束那一刻（不含）' => [self::END_TS, false, null, null];

        yield '窗口结束之后' => [self::END_TS + 86400, false, null, null];
    }

    #[DataProvider('instants')]
    public function testStatusAcrossTheWindow(int $nowTs, bool $active, ?MaintenanceMessageKey $key, ?int $retryAfter): void
    {
        $status = MaintenanceWindow::fromIso(self::START, self::END)->statusAt(self::at($nowTs));

        self::assertSame($active, $status->active);
        self::assertSame($key, $status->messageKey);
        self::assertSame($retryAfter, $status->retryAfter);
    }

    /**
     * 两头都留空 = 没有计划中的维护。这是 `.env` 与生产的常态。
     */
    public function testNoWindowConfiguredMeansNoAnnouncementEver(): void
    {
        foreach ([MaintenanceWindow::fromIso(null, null), MaintenanceWindow::fromIso('', '  '), MaintenanceWindow::none()] as $window) {
            $status = $window->statusAt(self::at(self::START_TS + 60));

            self::assertFalse($status->active);
            self::assertNull($status->messageKey);
            self::assertNull($status->retryAfter);
        }
    }

    /**
     * ⚠️ offset 不是可选的。
     *
     * 不带 offset 的值会按容器的 `date.timezone` 解释，于是同一份配置在两个环境
     * 指向**不同时刻**；而本地与 CI 通常都是 UTC，于是这个故障只在生产显形，
     * 症状是「横幅早了/晚了两小时」——没人会把它和时区联系起来。
     *
     * @return iterable<string, array{string}>
     */
    public static function malformedInstants(): iterable
    {
        yield '无 offset' => ['2026-09-15T03:00:00'];
        yield '只有日期' => ['2026-09-15'];
        yield '空格代替 T' => ['2026-09-15 03:00:00+02:00'];
        yield '缺秒' => ['2026-09-15T03:00+02:00'];
        yield '带小数秒' => ['2026-09-15T03:00:00.000+02:00'];
        yield 'offset 缺冒号' => ['2026-09-15T03:00:00+0200'];
        yield '根本不是时间' => ['next tuesday'];
    }

    #[DataProvider('malformedInstants')]
    public function testMalformedInstantIsAConfigurationError(string $raw): void
    {
        self::expectException(\LogicException::class);
        self::expectExceptionMessage('is not an RFC 3339 instant with an explicit offset');

        MaintenanceWindow::fromIso($raw, self::END);
    }

    /**
     * ⚠️ 只配一头必须炸，不能宽容地当成「无窗口」。
     *
     * 漏填 end 的那次部署，运维以为公告发出去了而客户端什么也没收到 ——
     * 而这件事要等到维护窗口当天有用户投诉才会被发现。500 + §14.4 的告警
     * 则是当场可见的。
     *
     * @return iterable<string, array{string|null, string|null}>
     */
    public static function halfConfiguredWindows(): iterable
    {
        yield '只有 start' => [self::START, null];
        yield '只有 end' => [null, self::END];
        yield 'start 留空字符串' => ['', self::END];
        yield 'end 留空字符串' => [self::START, '   '];
    }

    #[DataProvider('halfConfiguredWindows')]
    public function testHalfConfiguredWindowIsAConfigurationError(?string $start, ?string $end): void
    {
        self::expectException(\LogicException::class);
        self::expectExceptionMessage('must be set together');

        MaintenanceWindow::fromIso($start, $end);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invertedWindows(): iterable
    {
        yield 'start 晚于 end' => [self::END, self::START];
        // 零长度窗口：半开区间 [x, x) 是空集，`active` 永远不会为 true ——
        // 也就是一条发不出去的公告。配出来只可能是手误。
        yield '零长度' => [self::START, self::START];
        // 同一时刻的两种写法。纯字符串比较看不出它们相等，所以这一条顺带证明
        // 比较发生在解析之后。
        yield '零长度（不同 offset 写法）' => [self::START, '2026-09-15T01:00:00Z'];
    }

    #[DataProvider('invertedWindows')]
    public function testWindowMustBeStrictlyForward(string $start, string $end): void
    {
        self::expectException(\LogicException::class);
        self::expectExceptionMessage('must be strictly before');

        MaintenanceWindow::fromIso($start, $end);
    }

    /**
     * 同一时刻的不同 offset 写法必须判定一致 —— 否则 §9.2 的窗口在夏令时切换
     * 前后会错一小时，而那正是德国每年两次会发生的事。
     */
    public function testOffsetsAreNormalisedBeforeComparing(): void
    {
        $viaCest = MaintenanceWindow::fromIso(self::START, self::END);
        $viaUtc = MaintenanceWindow::fromIso('2026-09-15T01:00:00Z', '2026-09-15T02:00:00Z');

        $now = self::at(self::START_TS + 60);

        self::assertSame($viaCest->statusAt($now)->retryAfter, $viaUtc->statusAt($now)->retryAfter);
        self::assertTrue($viaUtc->statusAt($now)->active);
    }

    private static function at(int $timestamp): \DateTimeImmutable
    {
        // ClockInterface 的约定是「恒为 UTC」，照那个约定造。
        return new \DateTimeImmutable('@'.$timestamp, new \DateTimeZone('UTC'));
    }
}
