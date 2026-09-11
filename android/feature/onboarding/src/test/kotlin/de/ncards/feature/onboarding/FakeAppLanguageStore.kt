package de.ncards.feature.onboarding

import de.ncards.core.model.settings.AppLanguage
import de.ncards.core.model.settings.AppLanguageStore

/**
 * [AppLanguageStore] 的测试替身。
 *
 * 真实现（`:app` 的 `AppCompatLanguageStore`）会重建 Activity，在 JVM 单测里
 * 既跑不起来也没有意义 —— 这里只要记住「ViewModel 有没有把选择传下去」。
 */
internal class FakeAppLanguageStore(
    private var language: AppLanguage = AppLanguage.GERMAN,
) : AppLanguageStore {
    val selections = mutableListOf<AppLanguage>()

    override fun current(): AppLanguage = language

    override fun select(language: AppLanguage) {
        this.language = language
        selections += language
    }
}
