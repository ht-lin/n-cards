package de.ncards.core.model.id

import java.util.UUID
import kotlin.random.Random

/**
 * RFC 9562 的 UUID 版本 7：**前 48 位是毫秒时间戳**，其余是随机。
 *
 * ```
 *  0                   1                   2                   3
 *  0 1 2 3 4 5 6 7 8 9 0 1 2 3 4 5 6 7 8 9 0 1 2 3 4 5 6 7 8 9 0 1
 * ┌───────────────────────────────────────────────────────────────┐
 * │                        unix_ts_ms (48 位，大端)                │
 * ├───────────────┬───────────────────────────────────────────────┤
 * │  ver = 0b0111 │              rand_a (12 位)                    │
 * ├───┬───────────┴───────────────────────────────────────────────┤
 * │var│                     rand_b (62 位)                         │
 * │ 10│                                                            │
 * └───┴────────────────────────────────────────────────────────────┘
 * ```
 *
 * ============================================================================
 * 为什么是 v7 而不是 v4
 * ============================================================================
 * §0 的原文：「所有标识符为 **UUIDv7**（时间有序，利于索引与客户端生成）」。
 * 时间有序是给**服务端的 B-tree 索引**用的 —— v4 的随机主键会让每次插入落在索引的
 * 随机位置，页分裂率随表增长而恶化。客户端这边看不出区别，但主键是客户端生成的，
 * 所以这个选择只能在这里做对。
 *
 * ============================================================================
 * ⚠️ 同毫秒内**不做**单调递增（RFC 9562 §6.2 的可选方法三）
 * ============================================================================
 * 那个方法用一个计数器占掉 `rand_a`，保证同一毫秒内连续生成的 id 仍然递增。
 * 本仓库不需要它，而且写了反而要维护一个跨调用的可变状态：
 *
 * - id 的**唯一**产出点是「用户手动保存一张卡」（T-155）与后续的扫码录入
 *   （T-156/T-157）。人在同一毫秒里保存两张卡是做不到的。
 * - 钱包列表的排序是 `is_pinned DESC, sort_order ASC, created_at DESC` ——
 *   **没有一处按 id 排序**。同毫秒的两个 id 谁大谁小，对本仓库没有可观测后果。
 *
 * 写在这里，免得下一个人把它当成遗漏「顺手补上」。真需要它的那天
 * （批量导入？）请连同一条「同毫秒内严格递增」的测试一起加。
 *
 * @param now 取当前毫秒。注入是为了让测试能钉住时间戳那 48 位。
 * @param random 随机源。注入是为了让测试可复现。
 */
class UuidV7Generator(
    private val now: () -> Long = System::currentTimeMillis,
    private val random: Random = Random.Default,
) : IdGenerator {
    override fun newId(): String {
        val timestamp = now()
        val randomA = random.nextInt(RAND_A_RANGE).toLong()

        // 高 64 位：48 位时间戳 ‖ 4 位版本 ‖ 12 位 rand_a
        val mostSignificant =
            (timestamp and TIMESTAMP_MASK shl TIMESTAMP_SHIFT) or
                (VERSION shl VERSION_SHIFT) or
                randomA

        // 低 64 位：2 位变体 ‖ 62 位 rand_b。
        // ⚠️ 先清掉最高两位再或上 0b10 —— 直接 `or` 的话，随机数原本的最高两位
        // 若是 11 就仍然是 11，产出的是一个变体位非法的 UUID，而它看起来完全正常。
        val randomB = random.nextLong()
        val leastSignificant = (randomB and RAND_B_MASK) or VARIANT

        return UUID(mostSignificant, leastSignificant).toString()
    }

    private companion object {
        /** 时间戳只取低 48 位。到公元 10889 年才会溢出。 */
        const val TIMESTAMP_MASK = 0x0000_FFFF_FFFF_FFFFL
        const val TIMESTAMP_SHIFT = 16

        /** 版本 7，放在高 64 位的第 48–51 位。 */
        const val VERSION = 0x7L
        const val VERSION_SHIFT = 12

        /** rand_a 是 12 位 → [0, 4096)。 */
        const val RAND_A_RANGE = 1 shl 12

        /** 变体 `0b10`（RFC 4122 / 9562 变体），占低 64 位的最高两位。 */
        const val VARIANT = Long.MIN_VALUE // 0b10 << 62 == 0x8000_0000_0000_0000
        const val RAND_B_MASK = 0x3FFF_FFFF_FFFF_FFFFL
    }
}
