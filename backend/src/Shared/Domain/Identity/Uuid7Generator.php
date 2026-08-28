<?php

declare(strict_types=1);

namespace App\Shared\Domain\Identity;

use App\Shared\Domain\Random\RandomnessInterface;
use App\Shared\Domain\Time\ClockInterface;

/**
 * RFC 9562 §5.7 的 UUIDv7 生成器，纯 PHP 实现。
 *
 * ============================================================================
 * 位布局（128 位）
 * ============================================================================
 * ```
 *  0                   1                   2                   3
 *  0 1 2 3 4 5 6 7 8 9 0 1 2 3 4 5 6 7 8 9 0 1 2 3 4 5 6 7 8 9 0 1
 * +-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+
 * |                          unix_ts_ms (48)                      |
 * +-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+
 * |  unix_ts_ms   |  ver(0111)  |       rand_a (12)               |
 * +-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+
 * |var(10)|                     rand_b (62)                       |
 * +---------------------------------------------------------------+
 * ```
 *
 * ============================================================================
 * 为什么用计数器占住 rand_a
 * ============================================================================
 * 采用 RFC 9562 §6.2 的「Method 1 —— 固定长度专用计数器」：`rand_a` 的 12 位当作
 * 同毫秒内的单调计数器，而不是纯随机。
 *
 * 单调性不是锦上添花。UUIDv7 的全部意义就是**按时间有序**：
 *   - 它会成为 Postgres 主键。乱序插入会让 B-tree 页分裂，§9.3 的 75 万行 cards 表上
 *     这是实打实的写放大。
 *   - §5.4.1 的同步游标按时间序推进。同毫秒内乱序意味着两条记录的相对顺序不稳定。
 * 纯随机的 rand_a 在同一毫秒内是乱序的；计数器不是。
 *
 * ============================================================================
 * 三个边界情况
 * ============================================================================
 * 1. **同毫秒溢出**：计数器只播种低 8 位（0x00–0xFF），留下 3840 个递增余量。
 *    §9.3 的峰值约 15 req/s，碰不到上限；真碰到就「向未来借一毫秒」，
 *    顺序仍然严格递增，代价是 id 的时间戳最多快 1 ms。
 * 2. **时钟回拨**（NTP 校正）：`lastMs` 只增不减。回拨期间继续在旧毫秒上递增计数器，
 *    等挂钟追上来再继续。**绝不产生倒退的 id** —— 倒退会让游标同步漏掉记录。
 * 3. **worker 模式**：本类是有状态单例。FrankenPHP worker 模式下计数器跨请求保持，
 *    正是想要的；经典 FPM 下每个进程各自播种，仍然进程内单调 —— 而这已经是
 *    UUID 唯一承诺的东西。
 *
 * ⚠️ 本类**不是** readonly，也**不是**线程安全的。PHP-FPM/FrankenPHP 的请求模型
 * 是单线程的，所以这没问题；将来若引入真正的并行（ext-parallel 之类），这里要重看。
 */
final class Uuid7Generator implements UuidGeneratorInterface
{
    /** rand_a 是 12 位，计数器上限 4095。 */
    private const COUNTER_MAX = 0xFFF;

    /** 只播种低 8 位，给同毫秒留 3840 个递增名额。 */
    private const COUNTER_SEED_MAX = 0xFF;

    private int $lastMs = 0;

    private int $counter = 0;

    public function __construct(
        private readonly ClockInterface $clock,
        private readonly RandomnessInterface $random,
    ) {
    }

    public function generate(): Uuid
    {
        $ms = $this->clock->nowMillis();

        if ($ms > $this->lastMs) {
            // 常规路径：新的一毫秒，重新播种计数器。
            $this->lastMs = $ms;
            $this->counter = $this->random->int(0, self::COUNTER_SEED_MAX);
        } elseif (++$this->counter > self::COUNTER_MAX) {
            // 同一毫秒内用光了 4096 个名额 —— 向未来借一毫秒，顺序仍严格递增。
            ++$this->lastMs;
            $this->counter = $this->random->int(0, self::COUNTER_SEED_MAX);
        }
        // $ms < $this->lastMs（时钟回拨）落在 elseif 的 else 分支：lastMs 保持不变，
        // 上面的 ++$this->counter 已经生效，id 继续递增。

        // 48 位大端毫秒时间戳。pack('J') 出 8 字节大端 uint64，砍掉高 2 字节即得。
        $bytes = substr(pack('J', $this->lastMs), 2, 6);

        // 第 7 字节：高 4 位是版本号 0111，低 4 位是计数器的高 4 位。
        // 第 8 字节：计数器的低 8 位。
        $bytes .= \chr(0x70 | ($this->counter >> 8)).\chr($this->counter & 0xFF);

        // rand_b：8 字节随机，最高 2 位改写成 variant 10。
        $rand = $this->random->bytes(8);
        $rand[0] = \chr((\ord($rand[0]) & 0x3F) | 0x80);

        return Uuid::fromBytes($bytes.$rand);
    }
}
