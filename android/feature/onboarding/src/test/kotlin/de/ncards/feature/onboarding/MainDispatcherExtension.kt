package de.ncards.feature.onboarding

import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.test.StandardTestDispatcher
import kotlinx.coroutines.test.TestDispatcher
import kotlinx.coroutines.test.resetMain
import kotlinx.coroutines.test.setMain
import org.junit.jupiter.api.extension.AfterEachCallback
import org.junit.jupiter.api.extension.BeforeEachCallback
import org.junit.jupiter.api.extension.ExtensionContext

/**
 * 把 `Dispatchers.Main` 换成一个 [TestDispatcher]。
 *
 * ⚠️ 没有它，任何 `viewModelScope.launch { … }` 都会在测试里抛
 * 「Module with the Main dispatcher had failed to initialize」——
 * `viewModelScope` 用的是 `Dispatchers.Main.immediate`，而 JVM 单测里没有主线程。
 *
 * 用 `StandardTestDispatcher` 而不是 `UnconfinedTestDispatcher`：本模块有一个
 * **真的靠时间推进**的东西（重发倒计时），而它要能被
 * `advanceTimeBy` 精确控制。`runTest` 会复用这里设进去的调度器的
 * `TestCoroutineScheduler`，所以两边共用同一条虚拟时间轴。
 *
 * ⚠️ 这是仓库里第一个 ViewModel 测试（§13.4 点名的 Turbine 至今零使用）。
 * 第三个模块需要同样的东西时，该把它提升到 `:core:testing` 而不是抄第三份 ——
 * 与 T-150 对 `FakeSecretStore` 的处置同一条。
 */
@OptIn(ExperimentalCoroutinesApi::class)
class MainDispatcherExtension(
    private val dispatcher: TestDispatcher = StandardTestDispatcher(),
) : BeforeEachCallback,
    AfterEachCallback {
    override fun beforeEach(context: ExtensionContext) {
        Dispatchers.setMain(dispatcher)
    }

    override fun afterEach(context: ExtensionContext) {
        Dispatchers.resetMain()
    }
}
