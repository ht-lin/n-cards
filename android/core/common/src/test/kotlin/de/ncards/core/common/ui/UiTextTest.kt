package de.ncards.core.common.ui

import org.junit.jupiter.api.Assertions.assertEquals
import org.junit.jupiter.api.Assertions.assertNotEquals
import org.junit.jupiter.api.DisplayName
import org.junit.jupiter.api.Test

@DisplayName("UiText")
class UiTextTest {
    @Test
    @DisplayName("of 把 vararg 收进 args")
    fun ofCollectsVarargs() {
        val text = UiText.of(RES_ID, 3, "REWE")

        assertEquals(UiText.Resource(RES_ID, listOf(3, "REWE")), text)
    }

    @Test
    @DisplayName("of 不带参数时 args 是空列表，不是 null")
    fun ofWithoutArgsYieldsEmptyList() {
        assertEquals(emptyList<Any>(), (UiText.of(RES_ID) as UiText.Resource).args)
    }

    /**
     * 两个同 id 同参的 `Resource` 必须相等 —— 它会被放进 `UiState` 的
     * `data class` 里，而 Compose 靠 `equals` 决定跳不跳过重组。
     * 不相等的话，每次 `_state.update { it.copy(...) }` 都会让整屏重组。
     */
    @Test
    @DisplayName("Resource 是值语义（Compose 的跳过靠它）")
    fun resourceHasValueSemantics() {
        assertEquals(UiText.of(RES_ID, 1), UiText.of(RES_ID, 1))
    }

    /**
     * `Raw` 原样保留，一个字符都不动。
     *
     * 它装的是用户自己输入的卡片标题与商家名 —— 那些字符串**不该被翻译、
     * 也不该被 trim 或规范化**：用户把卡叫成「 REWE 」，那两个空格是他的事。
     */
    @Test
    @DisplayName("Raw 原样保留内容")
    fun rawKeepsItsValueVerbatim() {
        assertEquals(" REWE ", UiText.Raw(" REWE ").value)
    }

    @Test
    @DisplayName("Raw 与 Resource 即使内容相仿也不相等")
    fun rawNeverEqualsResource() {
        val raw: UiText = UiText.Raw("REWE")
        val resource: UiText = UiText.of(RES_ID)

        assertNotEquals(raw, resource)
    }

    private companion object {
        /** 一个假的资源 id。本模块没有 `res/`，所以不能引真的 `R.string.*`。 */
        const val RES_ID = 0x7F010001
    }
}
