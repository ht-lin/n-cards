package de.ncards.core.barcode.di

import org.junit.jupiter.api.Assertions.assertEquals
import org.junit.jupiter.api.Assertions.assertTrue
import org.junit.jupiter.api.DisplayName
import org.junit.jupiter.api.Test

/**
 * 缓存预算之所以可测，是因为 [barcodeCacheBudgetBytes] 把 `maxMemory` 参数化了，
 * 而且用的是 `Runtime.maxMemory()` 而不是要 `Context` 的 `ActivityManager.memoryClass`。
 */
@DisplayName("条码缓存预算")
class BarcodeCacheBudgetTest {
    private val mib = 1024L * 1024

    /** 1080p 屏上最大的一张：全屏 QR 是方的，1080×1080×4 ≈ 4.45 MiB。 */
    private val largestFullscreenBitmap = 1080 * 1080 * 4

    /**
     * **本文件里最重要的一条。**
     *
     * 预算装不下单张全屏图的话，`SizedLruCache.put` 会因为「单个值比预算还大」
     * 而直接不收 —— 缓存变成死重，**而且没有任何症状**：功能全对，只是每次
     * 打开全屏页都重算一遍。这正是本卡开发中真的踩到的那个数（地板曾是 2 MiB）。
     */
    @Test
    @DisplayName("任何堆大小下都至少装得下一张全屏条码")
    fun alwaysFitsAtLeastOneFullscreenBarcode() {
        listOf(16L, 48L, 96L, 128L, 192L, 256L, 512L, 4096L).forEach { heapMib ->
            assertTrue(
                barcodeCacheBudgetBytes(maxMemory = heapMib * mib) >= largestFullscreenBitmap,
                "堆 ${heapMib}MiB 时预算装不下一张全屏条码",
            )
        }
    }

    @Test
    @DisplayName("小堆落到地板，不会给出 0")
    fun staysAboveTheFloorOnSmallHeaps() {
        assertEquals((5 * mib).toInt(), barcodeCacheBudgetBytes(maxMemory = 16 * mib))
        assertEquals((5 * mib).toInt(), barcodeCacheBudgetBytes(maxMemory = 1 * mib))
    }

    @Test
    @DisplayName("大堆设备也不会超过天花板")
    fun staysBelowTheCeilingOnLargeHeaps() {
        assertEquals((12 * mib).toInt(), barcodeCacheBudgetBytes(maxMemory = 512 * mib))
        assertEquals((12 * mib).toInt(), barcodeCacheBudgetBytes(maxMemory = 4096 * mib))
    }

    /**
     * §13.8 点名的验证设备是 Android 8 + 2 GB RAM，那一档 `maxMemory` 通常是
     * 96–192 MB。这条钉住「那个区间里，随堆走的那一段真的在起作用」——
     * 否则地板与天花板一夹，分数就成了摆设。
     */
    @Test
    @DisplayName("低端机的典型堆区间里按 1/16 随堆走")
    fun scalesWithTheHeapInTheLowEndRange() {
        assertEquals((6 * mib).toInt(), barcodeCacheBudgetBytes(maxMemory = 96 * mib))
        assertEquals((8 * mib).toInt(), barcodeCacheBudgetBytes(maxMemory = 128 * mib))
        assertEquals((12 * mib).toInt(), barcodeCacheBudgetBytes(maxMemory = 192 * mib))
    }

    @Test
    @DisplayName("预算随堆单调不减")
    fun budgetIsMonotonic() {
        var previous = 0
        (1..1024).forEach { heapMib ->
            val budget = barcodeCacheBudgetBytes(maxMemory = heapMib * mib)
            assertTrue(budget >= previous, "堆 ${heapMib}MiB 时预算反而变小了")
            previous = budget
        }
    }
}
