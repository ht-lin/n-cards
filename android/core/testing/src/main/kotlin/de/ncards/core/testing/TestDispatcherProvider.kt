package de.ncards.core.testing

import de.ncards.core.common.dispatcher.DispatcherProvider
import kotlinx.coroutines.CoroutineDispatcher
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.test.TestDispatcher
import kotlinx.coroutines.test.UnconfinedTestDispatcher

/**
 * 把 [DispatcherProvider] 的两格都指到同一个 [TestDispatcher]。
 *
 * ⚠️ 这正是 `DispatcherProvider` 存在的理由：`Dispatchers.setMain` 换得掉 Main，
 * 换不掉 `Dispatchers.IO` —— 一个 `withContext(Dispatchers.IO)` 里的协程会跑到
 * 真线程池上，`runTest` 的虚拟时间管不到它，于是测试只能靠 `delay` 碰运气。
 * 把 IO 也注入进来之后，Repository 的每一步都在同一条虚拟时间轴上。
 *
 * 默认给 [UnconfinedTestDispatcher]：Repository 的测试关心的是**结果**
 * （映射对不对、过滤对不对、outbox 有没有入队），不是 Loading 与 Content 的先后。
 * 需要顺序断言时传一个 `StandardTestDispatcher` 进来。
 *
 * 与 [MainDispatcherExtension] 共用一条时间轴的写法：
 * ```
 * val dispatchers = TestDispatcherProvider(mainDispatcher.dispatcher)
 * ```
 */
@OptIn(ExperimentalCoroutinesApi::class)
class TestDispatcherProvider(
    private val dispatcher: TestDispatcher = UnconfinedTestDispatcher(),
) : DispatcherProvider {
    override val io: CoroutineDispatcher get() = dispatcher
    override val default: CoroutineDispatcher get() = dispatcher
}
