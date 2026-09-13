package de.ncards.core.common.ui

import androidx.annotation.StringRes

/**
 * 一段还没有被解析成字符串的文案（§10.4 / §12.3 的 `core:common` 交付物）。
 *
 * ============================================================================
 * 它存在是为了让 §10.4 的那条规则**可以被遵守**
 * ============================================================================
 * §10.4 原文：「`ViewModel` **不得** import Android framework 类（`Context`、
 * `Uri` 除外的资源引用一律用资源 id 或 sealed 的 `UiText`）」。
 *
 * 没有这个类型的话，ViewModel 想表达「给用户看这句话」只有两条路，都不合规：
 * 注入 `Context` 去 `getString`（import 了 framework 类，还泄漏了 Context），
 * 或者直接拼字符串（那就绕过了 `strings.xml`，`checkComposeHardcodedText`
 * 与 §11.1 都不答应）。
 *
 * ⚠️ **`@StringRes` 是注解，不是 framework 类。** `androidx.annotation` 是一个
 * 纯注解 artifact，`RUNTIME` 保留都没有 —— 它不给 ViewModel 任何 Android 能力，
 * 所以它不在 §10.4 要挡的那一类里。
 *
 * ============================================================================
 * 与 `feature:onboarding` 现有做法的关系
 * ============================================================================
 * T-151 用的是另一条路：sealed 的错误类型 + 一个 `@Composable` 的
 * `OnboardingError.message()` 扩展在 UI 层做映射（`OnboardingMessages.kt`）。
 * **那条路没有错，而且在那个场景里更好** —— 错误类型本来就要穷举，
 * `when` 少一格就编译失败。
 *
 * 本类型是给另一类场景的：文案**不来自一个封闭的错误集**，而是要带参数
 * （「%d 张卡」）、或者在一个数据类里当字段传（列表项的徽章文字）。
 * 那时候为每一句话开一个 sealed 分支是噪音。
 *
 * 两者并存是有意的，不是没统一。
 */
sealed interface UiText {
    /**
     * 一条 `strings.xml` 里的资源。
     *
     * @param args 格式化参数。⚠️ 它们**也可能是 [UiText]**（嵌套），
     *   所以解析是递归的 —— 见 `core:ui` 的 `UiText.resolve()`。
     */
    data class Resource(
        @param:StringRes val id: Int,
        val args: List<Any> = emptyList(),
    ) : UiText

    /**
     * 一段已经是最终形态的文字。
     *
     * ⚠️ **只给真正不该翻译的东西**：用户自己输入的卡片标题、商家名、username。
     * 拿它来放一句德语硬编码，就是把 §11.1 绕过去了 ——
     * 而 `checkComposeHardcodedText` 只扫 Composable 的字面量，扫不到这里。
     * Review 时看见 `Raw("…")` 里是一句人话，那就是一个 bug。
     */
    data class Raw(
        val value: String,
    ) : UiText

    companion object {
        /** `UiText.of(R.string.x, 3)` 比 `UiText.Resource(R.string.x, listOf(3))` 好读。 */
        fun of(
            @StringRes id: Int,
            vararg args: Any,
        ): UiText = Resource(id, args.toList())
    }
}
