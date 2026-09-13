package de.ncards.core.common.dispatcher

import kotlinx.coroutines.CoroutineDispatcher
import kotlinx.coroutines.Dispatchers

/**
 * 把「切到哪个线程」变成一个可注入的依赖（§12.3 的 `core:common` 交付物）。
 *
 * ============================================================================
 * 它解决的是一个具体的测试问题，不是抽象洁癖
 * ============================================================================
 * 直接写 `withContext(Dispatchers.IO)` 的代码在单测里**没法被确定性地驱动**：
 * `runTest` 的虚拟时间只管得到它自己的 `TestDispatcher`，管不到 `Dispatchers.IO`
 * 那个真线程池。于是测试要么靠 `delay` 碰运气，要么就只能不测。
 *
 * `Dispatchers.setMain` 只能换掉 Main 那一个（那是 `MainDispatcherExtension`
 * 做的事），换不掉 IO —— 这就是为什么除了那个扩展之外还需要本接口。
 *
 * ⚠️ **不要在 ViewModel 里注入它。** ViewModel 用 `viewModelScope`，它已经绑在
 * Main 上了；真正需要切 IO 的是 Repository（§4.3 的分层图里，
 * 「数据从哪来」只有 Repository 一个决定点）。
 *
 * T-151 的两个 ViewModel 与 `DefaultAuthRepository.restoreSession()` 各自绕开过
 * 一次 —— 后者的注释写着「第一个真正需要注入调度器的测试出现时，该把它建起来
 * 并把这里换过去」。T-153 的 `DefaultCardRepository` 就是那个测试。
 */
interface DispatcherProvider {
    /** 磁盘与网络。Room 的 suspend DAO 自己会切，但我们的映射与过滤不会。 */
    val io: CoroutineDispatcher

    /** 纯 CPU 的活（排序、过滤 200 张卡）。 */
    val default: CoroutineDispatcher
}

/**
 * 生产实现。绑定在 `:app` 的 DI 根上。
 *
 * 它没有状态，所以不需要 `@Singleton` —— 但绑定那一侧给了，
 * 免得每个注入点都造一个新对象（对象本身不贵，贵的是它出现在依赖图里的噪音）。
 */
class DefaultDispatcherProvider : DispatcherProvider {
    override val io: CoroutineDispatcher get() = Dispatchers.IO
    override val default: CoroutineDispatcher get() = Dispatchers.Default
}
