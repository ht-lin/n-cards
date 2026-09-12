package de.ncards.core.model.card

import org.junit.jupiter.api.Assertions.assertEquals
import org.junit.jupiter.api.Assertions.assertSame
import org.junit.jupiter.api.DisplayName
import org.junit.jupiter.api.Test

@DisplayName("CardColor")
class CardColorTest {
    @Test
    @DisplayName("wireName 全部是 snake_case 且互不重复")
    fun wireNamesAreUniqueSnakeCase() {
        val names = CardColor.entries.map(CardColor::wireName)

        assertEquals(names.size, names.toSet().size, "wireName 撞了：$names")
        names.forEach { name ->
            assertEquals(
                name,
                name.lowercase(),
                "契约里的调色板键是小写 snake_case（例：blue_600）",
            )
        }
    }

    @Test
    @DisplayName("fromWire 能还原每一个常量")
    fun fromWireRoundTripsEveryConstant() {
        CardColor.entries.forEach { color ->
            assertSame(color, CardColor.fromWire(color.wireName))
        }
    }

    /**
     * 这条守的是 §13.6：服务端**允许**新增枚举值，老客户端不得因此崩。
     *
     * 一个装了新版本的设备发来 `mint_600`，本版本要把它画成默认色，
     * 而不是抛异常、也不是画一个透明的洞。
     */
    @Test
    @DisplayName("认不出来的键兜底到 DEFAULT，不抛异常")
    fun unknownWireNameFallsBackToDefault() {
        assertSame(CardColor.DEFAULT, CardColor.fromWire("mint_600"))
        assertSame(CardColor.DEFAULT, CardColor.fromWire(""))
        assertSame(CardColor.DEFAULT, CardColor.fromWire(null))
    }

    /**
     * DEFAULT 必须是 `entries` 的第一个。
     *
     * T-155 的颜色选择器按 `entries` 顺序排，用户看到的第一格与「什么都不选」
     * 拿到的必须是同一个色 —— 否则会出现「我明明没选，怎么是绿的」。
     */
    @Test
    @DisplayName("DEFAULT 是 entries 的第一格")
    fun defaultIsTheFirstEntry() {
        assertSame(CardColor.entries.first(), CardColor.DEFAULT)
    }
}
