package de.ncards.core.network.impl

import okhttp3.HttpUrl

/**
 * 网络层需要而本模块自己给不出的三个值。由 `:app` 在 DI 根提供。
 *
 * 为什么不用 `BuildConfig`：`ncards.android.library` 里 `buildFeatures.buildConfig = false`
 * 是写死的（注释原文：「免得有人为了塞一个常量把它打开，然后 20 个模块各生成一个空类」）。
 * 而且 library 的 `BuildConfig` 里本来也拿不到 app 的 `versionName` / `versionCode` ——
 * `X-Client` 要的恰恰是那两个值。
 *
 * 好处不止是绕开限制：测试里换一个指向 MockWebServer 的实例就够了，
 * staging / prod 的切换是 `:app` 的 buildType 的事，本模块一行都不用改。
 */
data class NetworkConfig(
    /**
     * 必须**带 `/v1/` 且以 `/` 结尾**。
     *
     * 两条都不是可选的：
     * - 契约的 `servers[].url` 已经含 `/v1`，`paths` 下写的是 `/cards`
     *   （见 `docs/api/openapi.yaml` 文件头），所以生成的 `@GET("cards")` 是相对路径。
     * - Retrofit 的 `baseUrl` 不以 `/` 结尾会直接抛
     *   `baseUrl must end in /`，而那条异常出现在 DI 图构建时，离真正的原因很远。
     */
    val baseUrl: HttpUrl,
    /**
     * `X-Client` 的值，形如 `android/1.4.0 (26)`（§6.1）。
     *
     * 缺失或格式不符，后端 `ClientVersionListener` 当场 `400`；低于
     * `min_supported_client` 则 `426 client_too_old` → 强制升级墙（T-158）。
     */
    val clientHeader: String,
    /**
     * 是否装 OkHttp 的日志拦截器。**release 必须是 false**（§7.3：release 构建不得
     * 输出任何日志）。`:app` 用 `BuildConfig.DEBUG` 给。
     */
    val enableHttpLogging: Boolean = false,
) {
    init {
        require(baseUrl.encodedPath.endsWith("/")) {
            "baseUrl 必须以 / 结尾（Retrofit 的硬要求），当前是 $baseUrl"
        }
        require(CLIENT_HEADER_PATTERN.matches(clientHeader)) {
            "X-Client 的格式不符合契约（§6.1），当前是「$clientHeader」，期望形如 android/1.4.0 (26)"
        }
    }

    companion object {
        /**
         * 与 `docs/api/openapi.yaml` 的 `components/parameters/XClient` 逐字一致。
         *
         * ⚠️ 这里是**副本**，真相源是契约（以及后端的
         * `App\Shared\Domain\Client\ClientVersion::PATTERN`，两者已由
         * `backend/tests/Api/OpenApiDocumentTest` 用对照表钉死）。
         * 放一份在这里只为把「拼错了的 header」挡在发出去之前 —— 否则它表现为
         * 一个来路不明的 400，而 `detail` 是英文开发者文案，看不出是自己拼错的。
         */
        val CLIENT_HEADER_PATTERN = Regex("""^[a-z]{1,16}/\d{1,4}\.\d{1,4}\.\d{1,4}\s*\(\d{1,9}\)$""")
    }
}
