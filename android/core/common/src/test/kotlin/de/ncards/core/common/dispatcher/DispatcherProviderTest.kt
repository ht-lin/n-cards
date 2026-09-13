package de.ncards.core.common.dispatcher

import kotlinx.coroutines.Dispatchers
import org.junit.jupiter.api.Assertions.assertSame
import org.junit.jupiter.api.DisplayName
import org.junit.jupiter.api.Test

@DisplayName("DefaultDispatcherProvider")
class DispatcherProviderTest {
    private val provider = DefaultDispatcherProvider()

    /**
     * 这两条看着像同义反复，但它们守的是一个真会发生的手滑：
     * 把 `io` 写成 `Dispatchers.Default`。
     *
     * 后果不会在任何测试里显形（两个池都能跑完协程），只会在低端真机上表现为
     * 「卡列表偶尔卡一下」—— `Default` 的并行度是 CPU 核数，被一次磁盘读堵住
     * 就少一个核。§9.1 的 P95 ≤ 400 ms 是在那种机器上量的。
     */
    @Test
    @DisplayName("io 是 Dispatchers.IO")
    fun ioIsTheIoDispatcher() {
        assertSame(Dispatchers.IO, provider.io)
    }

    @Test
    @DisplayName("default 是 Dispatchers.Default")
    fun defaultIsTheDefaultDispatcher() {
        assertSame(Dispatchers.Default, provider.default)
    }
}
