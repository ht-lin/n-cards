package de.ncards.core.common.user

import kotlinx.coroutines.flow.StateFlow

/**
 * 「我是谁」——**离线可读**的当前用户 id（T-153 建立）。
 *
 * ============================================================================
 * ⚠️ 为什么需要一个新端口，而不是问 `AuthRepository`
 * ============================================================================
 * `CardDao.observeWallet(userId, …)` 要一个 `userId`：钱包列表是
 * `cards INNER JOIN card_members ON m.user_id = :userId`，因为 `sort_order` /
 * `is_pinned` / `role` 都是**每成员私有**的（§5.2）。没有这个 id，查询根本没法发。
 *
 * 而在 T-153 之前，全仓库没有任何地方存过它：
 *
 * - `AuthRepository.sessionState` 是 `Unknown` / `SignedIn` / `SignedOut` 三态，
 *   **不带 id** —— 它回答的是「有没有会话」，不是「是谁」。
 * - `AuthRepository.fetchMe()` 带 id，但它是**纯网络**的。拿它喂钱包会直接踩穿
 *   §4.3 铁律一（「UI 永远从 Room 的 `Flow` 读取，绝不直接消费网络响应渲染」）：
 *   地铁里打开 App 就会看到空钱包。
 * - access token 的 `sub` claim 里有 id，但 §7.1 把令牌**在类型层面**锁在
 *   `data:auth` 内部（`AuthRepository` 的类注释：「`Session` 这个类型不出本模块」），
 *   而且解析 JWT 只为读一个 id 是把一个安全边界换成一个字符串切分。
 *
 * ============================================================================
 * ⚠️ 为什么端口在 `core:common`，实现在 `data:auth`
 * ============================================================================
 * 因为 **`:data:card` 不许依赖 `:data:auth`**：`ModuleGraph.kt` 的
 * `DATA_SIBLING_EXEMPTIONS` 是空集，加一条要 PR 级的理由。那条规则的既定出路
 * 写在它自己的注释里 ——「经 `core:*` 的共享类型解决」。
 *
 * 于是形状与 [de.ncards.core.model.settings.AppLanguageStore] 完全同构：
 * **下游声明接口，上游把实现 `@Binds` 进来**，Hilt 在 `:app` 这个 DI 根上接起来。
 * `:data:card` 编译期只看得见本接口。
 *
 * 落在 `core:common` 而不是 `core:model`，是因为签名里有 `StateFlow` ——
 * `core:model` 是 `ncards.jvm.library`，主源集**没有 coroutines**
 * （只有 `testImplementation`）。给领域模型模块加一个协程依赖，
 * 只为放一个端口，不值当；而 `core:common` 本来就要 api 出 coroutines。
 *
 * ============================================================================
 * ⚠️ 实现方的三条不变量
 * ============================================================================
 * 1. **必须与令牌同生共死。** 登录时写、登出时清。留下一个比令牌活得久的 id，
 *    下一个用户登录后会看到上一个用户的卡（本机库还在，JOIN 照样命中）。
 * 2. **必须离线可读**，所以它落在 `SecretStore` 而不是内存或网络。
 * 3. **冷启动时跟着令牌一起恢复**，不额外多开一趟 Keystore ——
 *    读一次要过两趟 Keystore，而 `SessionStore` 完全可能在主线程上被首次注入。
 */
interface CurrentUserIdStore {
    /**
     * 当前用户 id，`null` = 还不知道。
     *
     * ⚠️ `null` 有**两种**含义，调用方要分清：
     *
     * - **还没读过存储**（冷启动的第一帧）—— 与
     *   [de.ncards.data.auth.SessionState.Unknown] 同一时刻，此时 NavHost 还在
     *   splash，钱包根本没被组合。
     * - **读过了，但确实没有**（未登录；或老版本升上来，令牌在而这个 key 还没写过）。
     *
     * 本接口**不区分**它们：区分需要第三态，而调用方拿到第三态之后能做的事
     * 与拿到 `null` 一样（等、或者补一次 `fetchMe()`）。钱包在 `null` 期间显示
     * `Loading`，这对两种含义都是对的答案。
     */
    val userId: StateFlow<String?>
}
