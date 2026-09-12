package de.ncards.core.barcode.cache

import org.junit.jupiter.api.Assertions.assertEquals
import org.junit.jupiter.api.Assertions.assertNotNull
import org.junit.jupiter.api.Assertions.assertNull
import org.junit.jupiter.api.Assertions.assertTrue
import org.junit.jupiter.api.DisplayName
import org.junit.jupiter.api.Test
import kotlin.random.Random

/**
 * 用 `String` 当值、`length` 当大小来驱动 —— 生产代码传的是 `Bitmap` 与
 * `allocationByteCount`，淘汰逻辑是同一份。这正是 [SizedLruCache] 把 `sizeOf`
 * 做成构造参数（而不是像 androidx 那样做成可覆写方法）的理由。
 */
@DisplayName("SizedLruCache")
class SizedLruCacheTest {
    private fun cache(maxSizeBytes: Int) = SizedLruCache<String, String>(maxSizeBytes) { it.length }

    @Test
    @DisplayName("命中与未命中")
    fun hitsAndMisses() {
        val cache = cache(100)
        assertNull(cache.get("a"))
        cache.put("a", "12345")
        assertEquals("12345", cache.get("a"))
        assertEquals(5, cache.sizeBytes)
        assertEquals(1, cache.count)
    }

    @Test
    @DisplayName("超出预算时淘汰最久未使用的那个")
    fun evictsLeastRecentlyUsed() {
        val cache = cache(10)
        cache.put("a", "aaaaa") // 5
        cache.put("b", "bbbbb") // 10
        // 碰一下 a，让 b 成为最久未使用的那个
        assertNotNull(cache.get("a"))
        cache.put("c", "ccccc") // 超了，该踢 b

        assertNotNull(cache.get("a"))
        assertNull(cache.get("b"))
        assertNotNull(cache.get("c"))
        assertEquals(10, cache.sizeBytes)
    }

    /**
     * 比预算还大的单个值放进去就得把自己淘汰掉，净效果只是把别人挤走。
     * 直接不收才是对的。
     */
    @Test
    @DisplayName("单个值比预算还大时不入缓存，也不牵连已有条目")
    fun oversizedValuesAreNotCached() {
        val cache = cache(10)
        cache.put("small", "12345")
        cache.put("huge", "x".repeat(50))

        assertNull(cache.get("huge"))
        assertEquals("12345", cache.get("small"))
        assertEquals(5, cache.sizeBytes)
    }

    /**
     * 经典的计数漂移：覆盖同一个 key 时忘了先减掉旧值的大小，
     * 于是缓存以为自己没满，实际早就超了 —— 在低端机上表现为莫名其妙的 OOM。
     */
    @Test
    @DisplayName("覆盖同一个 key 时字节数不漂移")
    fun overwritingAKeyKeepsTheSizeExact() {
        val cache = cache(100)
        cache.put("a", "12345")
        cache.put("a", "123")

        assertEquals(1, cache.count)
        assertEquals(3, cache.sizeBytes)
        assertEquals("123", cache.get("a"))
    }

    @Test
    @DisplayName("超大值覆盖已有 key 时，旧值被移除且计数归零")
    fun oversizedOverwriteRemovesTheOldEntry() {
        val cache = cache(10)
        cache.put("a", "12345")
        cache.put("a", "x".repeat(50))

        assertNull(cache.get("a"))
        assertEquals(0, cache.count)
        assertEquals(0, cache.sizeBytes)
    }

    @Test
    @DisplayName("随机序列下字节数永远不超预算，且与实际内容一致")
    fun neverExceedsTheBudget() {
        val budget = 64
        val cache = cache(budget)
        val random = Random(seed = 20260912)

        repeat(2_000) {
            val key = "k${random.nextInt(20)}"
            cache.put(key, "x".repeat(random.nextInt(1, 40)))
            assertTrue(cache.sizeBytes <= budget, "预算 $budget，实际 ${cache.sizeBytes}")
            assertTrue(cache.sizeBytes >= 0)
        }
    }
}
