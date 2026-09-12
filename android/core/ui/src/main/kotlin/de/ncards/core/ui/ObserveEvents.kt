package de.ncards.core.ui

import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.compose.LocalLifecycleOwner
import androidx.lifecycle.repeatOnLifecycle
import kotlinx.coroutines.flow.Flow

/**
 * 消费 ViewModel 的一次性事件流（§10.4：`Channel` + `receiveAsFlow()`，
 * **不放进** `StateFlow`）。
 *
 * ⚠️ 是 `RESUMED` 而不是 `STARTED`。
 *
 * 事件多半是导航。在 `STARTED` 上收的话，一个已经被盖住、但还没 `STOP` 的页面
 * （比如它上面弹了一个对话框，或者正在做退出动画）仍然会收到事件并发起导航 ——
 * 于是用户看到的是「我明明已经离开那一屏了，它又把我带到别处去」。
 * `RESUMED` 的语义是「这一屏正在前台且可交互」，那才是能代表用户发起导航的时刻。
 *
 * ⚠️ `repeatOnLifecycle` 在降到 `RESUMED` 以下时**取消**收集，回来时重新开始。
 * 中间发生的事件不会丢：`Channel` 会缓冲它们（`Channel.BUFFERED`），
 * 回到前台时按序补发。这正是用 `Channel` 而不是 `SharedFlow` 的理由 ——
 * 后者在没有订阅者时会把事件丢掉。
 *
 * ============================================================================
 * 它为什么在 `core:ui`
 * ============================================================================
 * T-151 把它写成 `OnboardingNavigation.kt` 里的一个 `private` 函数。
 * T-153 的 `feature:wallet` 需要一模一样的东西 —— 照
 * `android/README.md` 的规则（「第三个消费者出现时就该把它们建起来，
 * 而不是抄第三份」），提上来。
 */
@Composable
fun <T> ObserveEvents(
    events: Flow<T>,
    onEvent: (T) -> Unit,
) {
    val lifecycleOwner = LocalLifecycleOwner.current

    LaunchedEffect(events, lifecycleOwner) {
        lifecycleOwner.repeatOnLifecycle(Lifecycle.State.RESUMED) {
            events.collect(onEvent)
        }
    }
}
