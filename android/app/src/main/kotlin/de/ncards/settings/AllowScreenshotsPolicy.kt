package de.ncards.settings

import de.ncards.core.common.settings.ScreenshotPolicy
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import javax.inject.Inject
import javax.inject.Singleton

/**
 * [ScreenshotPolicy] 的当前实现：**恒为「允许」**。
 *
 * §7.3 的 SHOULD 是「全屏条码页提供设置项『允许截屏』（**默认允许**；
 * 关闭时加 `FLAG_SECURE`）」。T-154 落的是**默认值那一半**：
 * 开关 UI 与持久化归设置页那一卡，那时 `core:datastore` 与 `feature:settings`
 * 才会有内容（今天两个都是空壳，`libs.versions.toml` 里连 DataStore 都没有）。
 *
 * ⚠️ 今天的行为与「根本没做这个功能」**完全一致**，所以延后不让用户少任何东西。
 * 现在就把它做成一个端口，换来的是 `FLAG_SECURE` 的接线此刻就是对的
 * （它有一条不直观的约束：必须在窗口内容确定之前设好），设置页那一卡
 * 只换掉本类，`FullscreenBarcodeActivity` 一行都不用动。
 *
 * ⚠️ 顺带记一笔：§7.3 的另一条 SHOULD「应用切到后台时对最近任务快照打码」
 * 靠的是同一个 `FLAG_SECURE`，所以它今天也是关着的。那条归 M4（§7.3 清单）。
 */
@Singleton
class AllowScreenshotsPolicy
    @Inject
    constructor() : ScreenshotPolicy {
        private val allowed = MutableStateFlow(DEFAULT_ALLOW_SCREENSHOTS)

        override val allowScreenshots: StateFlow<Boolean> = allowed.asStateFlow()

        private companion object {
            /** §7.3 逐字：「**默认允许**」。 */
            const val DEFAULT_ALLOW_SCREENSHOTS = true
        }
    }
