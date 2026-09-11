package de.ncards.settings

import androidx.appcompat.app.AppCompatDelegate
import androidx.core.os.LocaleListCompat
import de.ncards.core.model.settings.AppLanguage
import de.ncards.core.model.settings.AppLanguageStore
import javax.inject.Inject
import javax.inject.Singleton

/**
 * [AppLanguageStore] 的实现。**只能住在 `:app`。**
 *
 * 两个原因，都不是风格问题：
 * - 它要 `AppCompatDelegate`，而 appcompat 是全仓库只给 `:app` 的依赖
 *   （见 `libs.versions.toml` 的 `androidxAppcompat`）；
 * - 切语言会重建 Activity，那是「整个应用」层面的事，不该由某一个 feature 决定。
 *
 * 形状与 T-150 把 `Authenticator` 从 `data:auth` 绑进 `core:network:impl` 的
 * 插槽是同一条依赖倒置：声明在下游看得见的地方，实现从上游绑进来。
 *
 * ⚠️ 持久化**不在这里**。API < 33 由 appcompat 的
 * `AppLocalesMetadataHolderService` + `autoStoreLocales=true` 存
 * （见 `AndroidManifest.xml` 的注释），API ≥ 33 由系统存。
 * 自己再存一份的下场是两份状态各说各话。
 */
@Singleton
internal class AppCompatLanguageStore
    @Inject
    constructor() : AppLanguageStore {
        /**
         * 用户显式设过就用那个；没设过时 `getApplicationLocales()` 是空列表，
         * 落到系统语言 —— 而系统语言不是 de/en 时
         * [AppLanguage.fromTag] 兜底成德语（§11.1：德语是默认，不是英语兜底）。
         */
        override fun current(): AppLanguage {
            val explicit = AppCompatDelegate.getApplicationLocales()
            val tag =
                if (explicit.isEmpty) {
                    LocaleListCompat.getAdjustedDefault().get(0)?.language
                } else {
                    explicit.get(0)?.language
                }
            return AppLanguage.fromTag(tag)
        }

        override fun select(language: AppLanguage) {
            if (language == current()) return
            AppCompatDelegate.setApplicationLocales(LocaleListCompat.forLanguageTags(language.tag))
        }
    }
