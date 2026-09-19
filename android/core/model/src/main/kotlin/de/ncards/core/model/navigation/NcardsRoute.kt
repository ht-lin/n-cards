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

/**
 * 卡详情（T-154）。
 *
 * 放进本文件的门槛是「另一个模块需要导航到它」——`feature:wallet` 的列表点击要到
 * 这里，而两个 feature 之间禁止互相依赖，所以路由是**值**、由 `:app` 的 NavHost 接线。
 *
 * ⚠️ **全屏条码页不在这里，它不是一个路由。** §10.2 要求它是一个**独立 `Activity`**
 * （「便于设置窗口属性」——亮度、常亮、`FLAG_SECURE` 都是窗口级的），
 * 所以起它的是 `Intent` 而不是 `navController.navigate(…)`。
 *
 * @property cardId 卡的 UUIDv7。
 *   ⚠️ **属性名就是参数 key**：navigation-compose 的类型安全路由把它存进
 *   `NavBackStackEntry.arguments`，`SavedStateHandle` 再按这个名字种进 ViewModel。
 *   全屏页的 Activity 用 `Intent.putExtra` 种同一个 key，于是**同一个 ViewModel
 *   在两个宿主下都取得到 id**。改名会静默拆掉那一半，见 `CardDetailViewModel`。
 */
@Serializable
data class CardDetailRoute(
    val cardId: String,
)

/**
 * 新建一张卡（T-155）。手输录入；扫码与图片录入是 T-156 / T-157。
 *
 * 进入它的有两处，都在 `feature:wallet`：空状态的「添加第一张卡」与列表页的 FAB。
 * 两处共用同一个回调，由 `:app` 的 NavHost 接到这里。
 *
 * ⚠️ **与 [CardEditRoute] 分成两个路由，不是一个 `cardId: String?`。**
 * 可空的类型安全路由参数要自己给 `NavType` 并处理「参数缺席」与「参数是字面量 null」
 * 两种情形，而它们在 `SavedStateHandle` 里长得一样。两个路由则让「新建」与「编辑」
 * 在 NavHost 上一眼可分，ViewModel 侧的判据也变成一句「取不到 id 就是新建」。
 */
@Serializable
data object CardCreateRoute

/**
 * 编辑一张已有的卡（T-155）。
 *
 * 入口只有一处：卡详情页的「Bearbeiten」，而它被 `card.canEdit` gate 住 ——
 * §7.3 要的是 viewer **根本看不到那个节点**，不是看得到但点不动。
 *
 * @property cardId 卡的 UUIDv7。
 *   ⚠️ **属性名就是参数 key**，与 [CardDetailRoute.cardId] 同一条约定：
 *   navigation-compose 把它存进 `NavBackStackEntry.arguments`，
 *   `SavedStateHandle` 再按这个名字种进 ViewModel。改名会静默拆掉编辑模式
 *   （ViewModel 取不到 id → 以为是新建 → 用户点「编辑」却建了一张新卡）。
 *   `CardEditIdKeyTest` 钉住了这个常量。
 */
@Serializable
data class CardEditRoute(
    val cardId: String,
)

/** AGB / Nutzungsbedingungen（§8.6：注册页链接）。 */
@Serializable
data object TermsRoute

/** Datenschutzerklärung（§8.6：首次启动时链接可见）。 */
@Serializable
data object PrivacyRoute
