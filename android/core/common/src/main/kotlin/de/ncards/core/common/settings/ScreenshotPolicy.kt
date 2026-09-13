package de.ncards.core.common.settings

import kotlinx.coroutines.flow.StateFlow

/**
 * 全屏条码页允不允许截屏（§7.3 的 SHOULD 清单，T-154）。
 *
 * 规格原文：「全屏条码页提供设置项『允许截屏』（**默认允许**；关闭时加 `FLAG_SECURE`）」。
 *
 * ============================================================================
 * ⚠️ 今天它恒为 `true` —— 这是一个**端口**，不是一个功能
 * ============================================================================
 * 真正的开关 UI 与持久化归设置页那一卡：`core:datastore` 与 `feature:settings`
 * 现在都还是空壳，`gradle/libs.versions.toml` 里连 DataStore 都还没有条目。
 * 为一个布尔值立起一个模块，与它换来的东西不成比例。
 *
 * 那为什么现在就要这个接口？因为**默认值与「没有这个功能」在行为上完全一致**，
 * 所以延后不会让用户今天少任何东西；而 `FLAG_SECURE` 的**接线**必须现在就是对的 ——
 * 它有一条不直观的约束（见下），在 Activity 里补一次比将来重新发现一次便宜。
 * 设置页那一卡只换一个实现类，Activity 一行都不用动。
 *
 * ⚠️ **`FLAG_SECURE` 必须在窗口内容确定之前设好。** 运行中翻转它需要重建窗口，
 * 所以实现方**不要**假设消费方会跟着这条流实时切换 —— 全屏条码页读的是
 * 进入那一刻的值。这也是它是 `StateFlow`（有当前值）而不是 `Flow` 的原因。
 *
 * ============================================================================
 * 为什么端口在 `core:common`，实现在 `:app`
 * ============================================================================
 * 形状与 `core:model` 的 `AppLanguageStore` 和
 * [de.ncards.core.common.user.CurrentUserIdStore] 完全同构：**下游声明接口，
 * 上游把实现 `@Binds` 进来**，Hilt 在 `:app` 这个 DI 根上接起来。
 *
 * 落在 `core:common` 而不是 `core:model`：签名里有 `StateFlow`，
 * 而 `core:model` 是 `ncards.jvm.library`，主源集没有协程。
 * 与 `CurrentUserIdStore` 当初的取舍逐字相同。
 */
interface ScreenshotPolicy {
    /** `true` = 允许截屏（默认）。`false` = 全屏条码页加 `FLAG_SECURE`。 */
    val allowScreenshots: StateFlow<Boolean>
}
