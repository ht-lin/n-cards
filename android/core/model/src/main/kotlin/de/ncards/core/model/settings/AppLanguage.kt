package de.ncards.core.model.settings

/**
 * 界面与验证码邮件的语言。**值域恰好两项**（§11.1）。
 *
 * 与契约的 `locale` 枚举（`de` / `en`）一一对应，但**不是**那个生成的类型：
 * `core:model` 是纯 Kotlin 模块，看不见 `core:network:api`，而且语言选择在
 * 注册流程里比一次 API 调用活得久（它先决定界面语言，再决定那封信的语言）。
 * 映射到 `OtpRequest.Locale` 由 `feature:onboarding` 做。
 *
 * ⚠️ **德语是默认值**，不是英语（§11.1：`values/` 即德语）。[fromTag] 的兜底
 * 因此是 [GERMAN] —— 一个未知 locale 的设备应当看到德语，
 * 而不是「英语兜底」。
 */
enum class AppLanguage(
    /** BCP 47 语言标签，与 `values-en/` 这类资源目录限定符同源。 */
    val tag: String,
) {
    GERMAN("de"),
    ENGLISH("en"),
    ;

    companion object {
        /** 认不出来就是德语 —— 见类注释。 */
        fun fromTag(tag: String?): AppLanguage =
            entries.firstOrNull { it.tag.equals(tag?.take(2), ignoreCase = true) } ?: GERMAN
    }
}

/**
 * 读写「App 用哪种语言」的端口。
 *
 * ============================================================================
 * ⚠️ 为什么是一个接口，而实现在 `:app`
 * ============================================================================
 * 真正的实现要调 `AppCompatDelegate.setApplicationLocales(...)`，而那是
 * androidx 的类型 —— `core:model` 连 `android.*` 都 import 不到（那是
 * `ncards.jvm.library` 刻意造成的结构约束），`feature:*` 也不该认识 appcompat：
 * 语言是**整个应用**的属性，改它会重建 Activity，那是 `:app` 的事。
 *
 * 于是形状与 T-150 的认证插槽、后端 ADR-0018 的 `OnboardingStatusInterface`
 * 相同：声明在下游看得见的地方，实现从上游绑进来。
 *
 * 顺带的好处是 `OnboardingViewModel` 可以注入它而不违反 §10.4
 * 「`ViewModel` **不得** import Android framework 类」。
 */
interface AppLanguageStore {
    /** 当前生效的语言。App 没有显式设定过时，由系统语言推导（见 [AppLanguage.fromTag]）。 */
    fun current(): AppLanguage

    /**
     * 切换语言。
     *
     * ⚠️ 这会让 Activity 重建（API < 33 是 `recreate()`，API ≥ 33 由系统重启），
     * 所以调用点必须是「此时丢掉界面状态不心疼」的地方 —— 本卡里就是 onboarding
     * 的第一屏，那时用户还什么都没输入。
     */
    fun select(language: AppLanguage)
}
