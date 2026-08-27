<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Domain\Identity;

use App\Shared\Domain\Identity\Uuid;
use App\Shared\Domain\Identity\Uuid7Generator;
use App\Tests\Double\Random\SequenceRandomness;
use App\Tests\Double\Time\FrozenClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * RFC 9562 §5.7 / §6.2 的位布局与单调性。
 *
 * 这些断言之所以能写成「精确字符串」，全靠 FrozenClock + SequenceRandomness ——
 * 没有它们，这里就只能退化成「看起来像个 UUID」这类抓不到位错的弱断言。
 */
#[CoversClass(Uuid7Generator::class)]
final class Uuid7GeneratorTest extends TestCase
{
    /** 2025-01-01T00:00:00Z，48 位十六进制是 01941f297c00。 */
    private const MS = 1735689600000;

    /**
     * 位布局的锚点测试：给定毫秒、计数器种子与随机字节，产物必须**逐字符**等于这个值。
     *
     * 拆开看：
     *   01941f29-7c00  ← 48 位时间戳 1735689600000
     *   7              ← 版本号 7
     *   0ab            ← 计数器（种子 0x0ab）
     *   8              ← variant 位 10xx，0x00 & 0x3F | 0x80 = 0x80
     *   000-000000000000 ← rand_b 其余部分
     */
    public function testProducesTheExactLayoutRfc9562Prescribes(): void
    {
        $generator = new Uuid7Generator(
            new FrozenClock(self::MS),
            new SequenceRandomness([str_repeat("\x00", 8)], [0x0AB]),
        );

        self::assertSame('01941f29-7c00-70ab-8000-000000000000', $generator->generate()->toString());
    }

    public function testVersionIs7AndVariantIsRfc9562(): void
    {
        $generator = new Uuid7Generator(
            new FrozenClock(self::MS),
            new SequenceRandomness([str_repeat("\xFF", 8)], [0x00]),
        );

        $uuid = $generator->generate();

        self::assertSame(7, $uuid->version(), '版本 nibble 必须是 7');

        // variant 位是第 17 个十六进制字符（去掉连字符后的 index 16），必须落在 8..b。
        $hex = str_replace('-', '', $uuid->toString());
        self::assertContains($hex[16], ['8', '9', 'a', 'b'], 'variant 位必须是 10xx');
    }

    public function testTimestampRoundTripsThroughTheUuid(): void
    {
        $generator = new Uuid7Generator(
            new FrozenClock(self::MS),
            new SequenceRandomness([str_repeat("\x00", 8)], [0]),
        );

        self::assertSame(self::MS, $generator->generate()->timestampMillis());
    }

    /**
     * 同一毫秒内必须严格递增 —— 这是 UUIDv7 存在的全部意义（B-tree 局部性 + 游标序）。
     */
    public function testIdsAreStrictlyIncreasingWithinTheSameMillisecond(): void
    {
        $count = 500;
        $generator = new Uuid7Generator(
            new FrozenClock(self::MS),
            new SequenceRandomness(
                array_fill(0, $count, str_repeat("\x00", 8)),
                [0], // 只在第一次（新毫秒）播种，之后走 ++counter 分支
            ),
        );

        $previous = null;

        for ($i = 0; $i < $count; ++$i) {
            $current = $generator->generate()->toString();

            if (null !== $previous) {
                self::assertGreaterThan($previous, $current, "第 {$i} 个 id 没有比前一个大");
            }

            $previous = $current;
        }
    }

    /**
     * 计数器耗尽（同毫秒 4096 个）时向未来借一毫秒，顺序仍严格递增。
     *
     * 种子取 0xFF，于是第 1 个 id 用 counter=0xFF，第 3841 个用 0xFFF，
     * 第 3842 个触发溢出分支 —— 时间戳 +1ms，计数器重新播种。
     */
    public function testBorrowsAMillisecondWhenTheCounterOverflows(): void
    {
        $count = 3842;
        $generator = new Uuid7Generator(
            new FrozenClock(self::MS),
            new SequenceRandomness(
                array_fill(0, $count, str_repeat("\x00", 8)),
                [0xFF, 0x00], // 首次播种 + 溢出后重新播种
            ),
        );

        $ids = [];

        for ($i = 0; $i < $count; ++$i) {
            $ids[] = $generator->generate();
        }

        $last = $ids[$count - 1];

        self::assertSame(self::MS + 1, $last->timestampMillis(), '溢出后时间戳应向前借一毫秒');
        self::assertSame(self::MS, $ids[$count - 2]->timestampMillis(), '倒数第二个仍在原毫秒');
        self::assertGreaterThan($ids[$count - 2]->toString(), $last->toString(), '借毫秒后顺序仍必须递增');
    }

    /**
     * NTP 回拨：挂钟倒退时**绝不能**产出倒退的 id。
     *
     * 倒退的 id 会让 §5.4.1 的游标同步漏掉记录 —— 客户端的游标已经越过了那个位置。
     */
    public function testNeverGoesBackwardsWhenTheClockDoes(): void
    {
        $clock = new FrozenClock(self::MS);
        $generator = new Uuid7Generator(
            $clock,
            new SequenceRandomness(array_fill(0, 3, str_repeat("\x00", 8)), [0x10]),
        );

        $before = $generator->generate();

        // 挂钟倒退整整一秒。
        $clock->set(self::MS - 1000);

        $afterStep = $generator->generate();
        $afterStep2 = $generator->generate();

        self::assertGreaterThan($before->toString(), $afterStep->toString(), '回拨后的 id 不得小于回拨前');
        self::assertGreaterThan($afterStep->toString(), $afterStep2->toString());
        self::assertSame(self::MS, $afterStep->timestampMillis(), '时间戳应停在回拨前的值，而不是跟着倒退');
    }

    public function testAdvancingTheClockReseedsTheCounter(): void
    {
        $clock = new FrozenClock(self::MS);
        $generator = new Uuid7Generator(
            $clock,
            new SequenceRandomness(array_fill(0, 2, str_repeat("\x00", 8)), [0x01, 0x02]),
        );

        $first = $generator->generate();
        $clock->advance(1);
        $second = $generator->generate();

        self::assertSame(self::MS, $first->timestampMillis());
        self::assertSame(self::MS + 1, $second->timestampMillis());
        self::assertGreaterThan($first->toString(), $second->toString());
    }

    public function testGeneratesDistinctIdsWithRealRandomnessShape(): void
    {
        $count = 50;
        $generator = new Uuid7Generator(
            new FrozenClock(self::MS),
            new SequenceRandomness(
                array_map(static fn (int $i): string => str_pad((string) $i, 8, "\x01", \STR_PAD_LEFT), range(1, $count)),
                [0],
            ),
        );

        $ids = [];

        for ($i = 0; $i < $count; ++$i) {
            $ids[] = $generator->generate()->toString();
        }

        self::assertCount($count, array_unique($ids), '不应产出重复 id');

        foreach ($ids as $id) {
            self::assertTrue(Uuid::isValid($id));
        }
    }
}
