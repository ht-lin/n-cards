package de.ncards.core.network.impl

import de.ncards.core.network.api.infrastructure.Serializer
import de.ncards.core.network.api.model.BarcodeFormat
import de.ncards.core.network.api.model.Card
import de.ncards.core.network.api.model.CardPage
import de.ncards.core.network.api.model.CardRole
import kotlinx.serialization.json.Json
import org.junit.jupiter.api.Assertions.assertEquals
import org.junit.jupiter.api.Assertions.assertNull
import org.junit.jupiter.api.DisplayName
import org.junit.jupiter.api.Test
import java.util.UUID

/**
 * `NetworkModule.provideJson()` 的配置能不能真的解出契约里的响应。
 *
 * 这个测试存在的理由是**生成产物里那些 `@Contextual`**：`java.util.UUID`、
 * `java.time.LocalDate`、`java.time.OffsetDateTime`、`java.net.URI` 四类没有内建
 * 序列化器，靠 `Serializer.kotlinxSerializationAdapters` 登记。少登记一个，编译期
 * 一切正常，运行时第一次解响应才报
 * `Serializer for class 'UUID' is not found` —— 那时候离改动已经很远了。
 *
 * §13.6 的两条前向兼容也在这里验：未知字段要忽略，未知枚举值要落到兜底分支。
 * 那两条是「服务端可以演进」这件事的全部前提。
 */
@DisplayName("网络层的 Json 配置")
class NcardsJsonTest {
    // 与 NetworkModule.provideJson() 逐字一致。抽不出来共用是有意的 ——
    // 那边是 @Provides，把它变成 public 常量只为测试引用，等于为测试改生产 API。
    private val json = Json {
        ignoreUnknownKeys = true
        explicitNulls = false
        serializersModule = Serializer.kotlinxSerializationAdapters
    }

    @Test
    @DisplayName("Card 的 UUID / 日期 / 时间 / 枚举全部解得出来")
    fun decodesCard() {
        val card = json.decodeFromString<Card>(CARD_JSON)

        assertEquals(UUID.fromString("0192f3a1-0000-7000-8000-000000000001"), card.id)
        assertEquals(BarcodeFormat.EAN_13, card.barcodeFormat)
        assertEquals(CardRole.owner, card.myRole)
        assertEquals(2026, card.updatedAt.year)
        assertEquals(2027, card.expiresOn?.year)
    }

    @Test
    @DisplayName("服务端多发一个字段时不崩（§3.10：老客户端会长期存在）")
    fun ignoresUnknownFields() {
        val withFutureField = CARD_JSON.dropLast(1) + ""","loyalty_tier":"gold","extra":{"a":1}}"""

        val card = json.decodeFromString<Card>(withFutureField)

        assertEquals("PAYBACK", card.title)
    }

    @Test
    @DisplayName("未知枚举值落到生成器的兜底分支，不抛异常（§13.6 允许新增枚举值）")
    fun unknownEnumValueFallsBack() {
        val withNewFormat = CARD_JSON.replace("\"EAN_13\"", "\"GS1_DATABAR\"")

        val card = json.decodeFromString<Card>(withNewFormat)

        assertEquals(BarcodeFormat.unknown_default_open_api, card.barcodeFormat)
    }

    @Test
    @DisplayName("列表信封 {items, next_cursor, has_more}：has_more=false 时 next_cursor 为 null")
    fun decodesListEnvelope() {
        val page = json.decodeFromString<CardPage>(
            """{"items":[$CARD_JSON],"next_cursor":null,"has_more":false}""",
        )

        assertEquals(1, page.items.size)
        assertNull(page.nextCursor)
        assertEquals(false, page.hasMore)
    }

    private companion object {
        val CARD_JSON =
            """
            {
              "id": "0192f3a1-0000-7000-8000-000000000001",
              "title": "PAYBACK",
              "color": "#0046ff",
              "barcode_format": "EAN_13",
              "barcode_value": "4012345678901",
              "revision": 3,
              "updated_at": "2026-08-30T09:15:00Z",
              "expires_on": "2027-01-31",
              "owner_id": "0192f3a1-0000-7000-8000-0000000000ff",
              "my_role": "owner"
            }
            """.trimIndent()
    }
}
