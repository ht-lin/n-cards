package de.ncards.core.network.impl

import okhttp3.HttpUrl.Companion.toHttpUrl
import org.junit.jupiter.api.Assertions.assertThrows
import org.junit.jupiter.api.DisplayName
import org.junit.jupiter.api.Test

/**
 * [NetworkConfig] 的两条 `require`。
 *
 * 它们挡的都是「表现形式离原因很远」的错误：baseUrl 少一个斜杠 → Retrofit 在 DI
 * 图构建时抛异常；`X-Client` 拼错 → 服务端 400，而 `detail` 是英文开发者文案，
 * 看不出是自己拼错的。
 */
@DisplayName("NetworkConfig")
class NetworkConfigTest {
    @Test
    @DisplayName("baseUrl 不以 / 结尾就拒绝构造")
    fun rejectsBaseUrlWithoutTrailingSlash() {
        assertThrows(IllegalArgumentException::class.java) {
            NetworkConfig(
                baseUrl = "https://api.ncards.de/v1".toHttpUrl(),
                clientHeader = VALID_CLIENT,
            )
        }
    }

    @Test
    @DisplayName("合法的 X-Client 形态：契约 pattern 的正反例各一组")
    fun validatesClientHeader() {
        listOf(
            "android/1.4.0 (26)",
            "android/0.1.0 (1)",
            // pattern 里 `\s*` 允许没有空格。
            "android/12.34.56(999999999)",
        ).forEach { header ->
            NetworkConfig(baseUrl = BASE_URL.toHttpUrl(), clientHeader = header)
        }

        listOf(
            // 大写不合法（pattern 是 [a-z]）
            "Android/1.4.0 (26)",
            // 少一段版本号
            "android/1.4 (26)",
            // 版本号不是数字
            "android/1.4.x (26)",
            // 缺 build number
            "android/1.4.0",
            // 前后有空格 —— 后端会先 trim，但正则表达不了那一步，
            // 所以客户端这一侧当作不合法（见 docs/api/README.md 的说明）。
            " android/1.4.0 (26)",
        ).forEach { header ->
            assertThrows(IllegalArgumentException::class.java, {
                NetworkConfig(baseUrl = BASE_URL.toHttpUrl(), clientHeader = header)
            }, "本应被拒绝：「$header」")
        }
    }

    private companion object {
        const val BASE_URL = "https://api.ncards.de/v1/"
        const val VALID_CLIENT = "android/1.4.0 (26)"
    }
}
