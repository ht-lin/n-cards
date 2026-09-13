package de.ncards.core.testing

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
 * 用 `StandardTestDispatcher` 而不是 `UnconfinedTestDispatcher`：
 * `runTest` 会复用这里设进去的调度器的 `TestCoroutineScheduler`，
 * 于是两边共用同一条虚拟时间轴，`advanceTimeBy` / `runCurrent` 才管得到
 * ViewModel 里启动的协程。`Unconfined` 会让协程在 launch 当场跑完，
 * 那样就测不出「Loading 先出现、Content 后到」这类**顺序**断言 ——
 * 而 T-153 的 `WalletUiState` 四态迁移恰恰全是顺序断言。
 *
 * ============================================================================
 * 它为什么在 `core:testing`
 * ============================================================================
 * T-151 把它写在 `feature:onboarding` 的 test 源集里，并在注释里留了话：
 * 「第三个模块需要同样的东西时，该把它提升到 `:core:testing` 而不是抄第三份」。
 * T-153 的 `data:card` 与 `feature:wallet` 就是第二、第三个。
 *
 * ⚠️ `androidTest` 源集**看不见**本模块（它是 `testImplementation`）。
 * 仪器测试里不需要它 —— 那里有真的主线程。
 */
@OptIn(ExperimentalCoroutinesApi::class)
class MainDispatcherExtension(
    val dispatcher: TestDispatcher = StandardTestDispatcher(),
) : BeforeEachCallback,
    AfterEachCallback {
    override fun beforeEach(context: ExtensionContext) {
        Dispatchers.setMain(dispatcher)
    }

    override fun afterEach(context: ExtensionContext) {
        Dispatchers.resetMain()
    }
}
