package de.ncards.core.model.id

import org.junit.jupiter.api.Assertions.assertEquals
import org.junit.jupiter.api.Assertions.assertTrue
import org.junit.jupiter.api.DisplayName
import org.junit.jupiter.api.Nested
import org.junit.jupiter.api.Test
import java.util.UUID
import kotlin.random.Random

@DisplayName("UuidV7Generator")
class UuidV7GeneratorTest {
    @Nested
    @DisplayName("位布局（RFC 9562）")
    inner class BitLayout {
        /**
         * 版本位写错的症状是**没有症状** —— 库照样解析，服务端照样接受，
         * 而「时间有序」这个我们花了整个类去换的性质悄悄没了。
         */
        @Test
        @DisplayName("版本位恒为 7")
        fun versionIsAlwaysSeven() {
            repeat(SAMPLES) { seed ->
                val id = generator(seed = seed).newId()

                assertEquals(7, UUID.fromString(id).version(), "id = $id")
            }
        }

        /**
         * 这一条守的是 `newId()` 里那句「先清掉最高两位再或上 0b10」。
         * 直接 `or` 的话，随机数最高两位本来是 `11` 时就仍然是 `11` ——
         * 大约四分之一的 id 变体非法，而它们看起来与合法的一模一样。
         */
        @Test
        @DisplayName("变体位恒为 0b10（约四分之一的随机数会踩到这条）")
        fun variantIsAlwaysRfc4122() {
            repeat(SAMPLES) { seed ->
                val id = generator(seed = seed).newId()

                assertEquals(2, UUID.fromString(id).variant(), "id = $id")
            }
        }

        @Test
        @DisplayName("前 48 位解回给定的毫秒时间戳")
        fun timestampPrefixDecodesBack() {
            listOf(0L, 1L, 1_764_547_200_000L, 0x0000_FFFF_FFFF_FFFFL).forEach { millis ->
                val id = generator(now = millis).newId()

                val decoded = UUID.fromString(id).mostSignificantBits ushr 16
                assertEquals(millis, decoded, "millis = $millis, id = $id")
            }
        }
    }

    @Nested
    @DisplayName("时间有序")
    inner class TimeOrdered {
        /**
         * 「时间有序」这四个字的**可执行**含义：按时间生成的 id，
         * 字符串字典序与时间序一致。服务端索引受益的正是这条。
         */
        @Test
        @DisplayName("时间递增 ⇒ 字符串字典序递增")
        fun lexicographicOrderFollowsTime() {
            val millis = listOf(1L, 1_000L, 1_764_547_200_000L, 1_764_547_200_001L)

            val ids = millis.map { generator(now = it).newId() }

            assertEquals(ids.sorted(), ids, "字典序与时间序不一致：$ids")
        }
    }

    @Nested
    @DisplayName("形态")
    inner class Shape {
        @Test
        @DisplayName("36 字符、小写、五段连字符形态")
        fun canonicalTextualForm() {
            repeat(SAMPLES) { seed ->
                val id = generator(seed = seed).newId()

                assertEquals(36, id.length, "id = $id")
                assertEquals(id.lowercase(), id, "契约里的 uuid 是小写")
                assertTrue(id matches CANONICAL, "id = $id")
            }
        }

        /** 它要能原样进 `cards.id`（TEXT）并被服务端当 `format: uuid` 收下。 */
        @Test
        @DisplayName("java.util.UUID 往返不丢字符")
        fun roundTripsThroughUuid() {
            repeat(SAMPLES) { seed ->
                val id = generator(seed = seed).newId()

                assertEquals(id, UUID.fromString(id).toString())
            }
        }

        @Test
        @DisplayName("同一毫秒内连续生成不重复（随机位在起作用）")
        fun distinctWithinTheSameMillisecond() {
            val fixedClock = UuidV7Generator(now = { 1_764_547_200_000L }, random = Random(7))

            val ids = List(SAMPLES) { fixedClock.newId() }

            assertEquals(ids.size, ids.toSet().size, "同毫秒内撞了：$ids")
        }
    }

    private fun generator(
        now: Long = 1_764_547_200_000L,
        seed: Int = 0,
    ) = UuidV7Generator(now = { now }, random = Random(seed))

    private companion object {
        const val SAMPLES = 64
        val CANONICAL = Regex("[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}")
    }
}
