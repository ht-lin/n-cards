package de.ncards.core.model.settings

import org.junit.jupiter.api.Assertions.assertEquals
import org.junit.jupiter.api.DisplayName
import org.junit.jupiter.api.Test

@DisplayName("AppLanguage")
class AppLanguageTest {
    @Test
    @DisplayName("认得出 de / en，大小写与地区后缀都不影响")
    fun resolvesKnownTags() {
        assertEquals(AppLanguage.GERMAN, AppLanguage.fromTag("de"))
        assertEquals(AppLanguage.GERMAN, AppLanguage.fromTag("de-DE"))
        assertEquals(AppLanguage.GERMAN, AppLanguage.fromTag("DE"))
        assertEquals(AppLanguage.ENGLISH, AppLanguage.fromTag("en"))
        assertEquals(AppLanguage.ENGLISH, AppLanguage.fromTag("en-GB"))
    }

    /**
     * ⚠️ §11.1：德语是**默认**语言。兜底成英语就是把「英语兜底」搬回来了 ——
     * 那正是 `values/` 放德语要避免的东西。
     */
    @Test
    @DisplayName("认不出来的语言兜底成德语，不是英语")
    fun fallsBackToGerman() {
        assertEquals(AppLanguage.GERMAN, AppLanguage.fromTag(null))
        assertEquals(AppLanguage.GERMAN, AppLanguage.fromTag(""))
        assertEquals(AppLanguage.GERMAN, AppLanguage.fromTag("tr"))
        assertEquals(AppLanguage.GERMAN, AppLanguage.fromTag("fr-FR"))
    }
}
