package de.ncards.core.barcode.cache

/**
 * 按**字节**（而不是条目数）计量的 LRU 缓存。
 *
 * ============================================================================
 * 为什么手写，而不用 android.util.LruCache 或 androidx.collection.LruCache
 * ============================================================================
 * - `android.util.LruCache` 在单元测试里是 `android.jar` 的**桩**，一调就抛
 *   `RuntimeException("Stub!")`。而 `:core:barcode` 不在 `Coverage.kt` 的
 *   `COVERAGE_EXEMPT` 里，Kover 又只统计单测 —— 用它等于把这段逻辑挪出门禁视野。
 * - `androidx.collection.LruCache` 在 JVM 上是能用的，但它的 `sizeOf` 是**可覆写方法**
 *   而不是 lambda，为测试参数化就得开子类；而且版本目录第 3 条说「只声明已经在用的
 *   东西」，为省下这五十行去加一条依赖不划算。
 *
 * 于是 [sizeOf] 从构造参数注入：生产代码传 `Bitmap::getAllocationByteCount`，
 * 测试传 `String::length`，同一份淘汰逻辑两边都跑得到。
 *
 * ⚠️ **淘汰时绝不 `recycle()` 被踢出去的值。** 这个类不知道自己装的是什么，
 * 但它的生产用途是 Bitmap —— 而 Compose 的 `Image` 或 Glance 的 `ImageProvider`
 * 很可能还持有那张图，`Canvas: trying to use a recycled bitmap` 是这里的经典崩法。
 * 交给 GC。这一句写在这里，是因为它是将来最可能被人「顺手优化」加上去的一行。
 *
 * 全部方法 `@Synchronized`：调用方在 `Dispatchers.Default` 的线程池上，
 * 可能有多屏并发。条目数是十几个量级，锁竞争可以忽略，无锁设计在这里是没理由的复杂度。
 */
internal class SizedLruCache<K : Any, V : Any>(
    private val maxSizeBytes: Int,
    private val sizeOf: (V) -> Int,
) {
    // accessOrder = true 才是 LRU（false 是插入序）。
    private val entries = LinkedHashMap<K, V>(INITIAL_CAPACITY, LOAD_FACTOR, true)

    private var currentSizeBytes = 0

    /** 当前占用字节数。仅供测试与诊断。 */
    @get:Synchronized
    val sizeBytes: Int get() = currentSizeBytes

    /** 当前条目数。仅供测试与诊断。 */
    @get:Synchronized
    val count: Int get() = entries.size

    @Synchronized
    fun get(key: K): V? = entries[key]

    /**
     * 放入并按需淘汰。
     *
     * 比 [maxSizeBytes] 还大的单个值**不入缓存**（否则放进去就要把自己淘汰掉，
     * 白白把别人挤走）。同 key 覆盖时先减旧值的大小 —— 漏掉这一步就是经典的
     * 计数漂移：缓存看起来没满，实际早已超。
     */
    @Synchronized
    fun put(
        key: K,
        value: V,
    ) {
        val size = sizeOf(value)
        if (size > maxSizeBytes) {
            entries.remove(key)?.let { currentSizeBytes -= sizeOf(it) }
            return
        }
        entries.put(key, value)?.let { currentSizeBytes -= sizeOf(it) }
        currentSizeBytes += size
        trimToSize()
    }

    private fun trimToSize() {
        val iterator = entries.entries.iterator()
        while (currentSizeBytes > maxSizeBytes && iterator.hasNext()) {
            // accessOrder 的 LinkedHashMap 里，迭代器先给出的就是最久未访问的那个。
            val eldest = iterator.next()
            currentSizeBytes -= sizeOf(eldest.value)
            iterator.remove()
        }
    }

    private companion object {
        const val INITIAL_CAPACITY = 8
        const val LOAD_FACTOR = 0.75f
    }
}
