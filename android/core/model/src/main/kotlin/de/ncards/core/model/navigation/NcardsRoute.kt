package de.ncards.core.model.navigation

import kotlinx.serialization.Serializable

/*
 * **跨 feature** 的导航目的地（§12.3）。T-151 第一次把这条约定落到代码上。
 *
 * ============================================================================
 * 为什么路由住在 core:model
 * ============================================================================
 * §12.3 的第二条规则是「`feature:*` 之间禁止互相依赖」，ModuleGraph.kt 在配置期
 * 强制它，违规文案里逐字写着出路：「跨 feature 导航：**在 core:model 里定义路由，
 * 由 app 的 NavHost 接线**」。
 *
 * 具体到本卡：onboarding 的注册页要链到 AGB / Datenschutz（§8.6），而那两页在
 * feature:legal —— `feature:onboarding → feature:legal` 会让 `./gradlew help`
 * 当场变红。正确形态是两边都只认识本文件里的目的地，由 :app 的 NavHost 接线，
 * feature 的 Composable 只收一个 `onOpenTerms: () -> Unit` 回调。
 *
 * ⚠️ **一个 feature 自己内部的路由不该放进来。** onboarding 的
 * welcome → email → otp 三步是它的私事，那三个路由是 internal 且住在
 * feature:onboarding 里。放进本文件的门槛是「另一个模块需要导航到它」。
 *
 * ============================================================================
 * ⚠️ core:model 是纯 Kotlin（JVM）模块
 * ============================================================================
 * 这里 import 不到 `androidx.navigation.*`，这是刻意的：路由是**值**，
 * 不是导航框架的类型。`NavHost` / `composable<T>()` 那一侧全在 :app。
 */

/**
 * 注册流程的嵌套图：语言/隐私说明 → 邮箱 → 6 位码（§1.4 J1 的前半段）。
 *
 * 它的 `startDestination` 与内部三步都是 `feature:onboarding` 的私事。
 */
@Serializable
data object OnboardingGraph

/**
 * username 设定页。**顶层目的地，不是 [OnboardingGraph] 的子目的地。**
 *
 * ⚠️ 这个区分是必须的，不是风格问题：冷启动时一个**已经登录但
 * `onboarding_complete == false`** 的用户必须能直接落在这一页上（§6.3.1 注 2 ——
 * 他的令牌是好的，只是卡在注册中间态），而嵌套图的 `startDestination` 是固定的，
 * 没法「有时从 welcome 开始、有时从 username 开始」。
 *
 * §3.8：本页**不可跳过、不可返回**。进入它的每一次导航都要清空回退栈。
 */
@Serializable
data object UsernameRoute

/**
 * 钱包（§1.4 J1 的后半段入口）。
 *
 * 本卡里它在 `:app` 下是一个占位实现，由 T-153 原地替换为 `feature:wallet`。
 * 路由名不会变，所以替换不牵动任何导航调用点。
 */
@Serializable
data object WalletRoute

/** AGB / Nutzungsbedingungen（§8.6：注册页链接）。 */
@Serializable
data object TermsRoute

/** Datenschutzerklärung（§8.6：首次启动时链接可见）。 */
@Serializable
data object PrivacyRoute
