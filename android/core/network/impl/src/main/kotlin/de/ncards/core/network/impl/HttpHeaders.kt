package de.ncards.core.network.impl

/**
 * §6.1 与 T-004 / T-006 定义的自定义 header 名字，一处声明。
 *
 * 它们都已经写进 `docs/api/openapi.yaml` 的 `components.parameters` 与
 * `components.headers`（T-007），但 OpenAPI 的 header **名字**不会被生成器
 * 提取成常量 —— 生成的 `@Header("X-Client")` 是字面量。所以拦截器这一侧要有一份，
 * 而这一份必须只有一处，否则「拼错一个字母 → 后端 400 → 看不出是自己拼错的」
 * 这种事会重复发生。
 */
internal object HttpHeaders {
    /** 所有 `/v1` 请求**必填**（§6.1）。缺失或格式不符即 400。 */
    const val X_CLIENT = "X-Client"

    /** 客户端可选提供，服务端在响应里回显；与 problem body 的 `request_id` 同值。 */
    const val X_REQUEST_ID = "X-Request-Id"

    /** 幂等键（UUID），Redis 存 24h。所有 `POST` 支持（ADR-0003）。 */
    const val IDEMPOTENCY_KEY = "Idempotency-Key"

    /** 命中幂等回放时为 `"true"`。标准里没有，是本项目自定义的（T-004）。 */
    const val IDEMPOTENCY_REPLAYED = "Idempotency-Replayed"

    /** **秒数**，不是 HTTP-date。429 / 503 / 409 `idempotency_in_progress`。 */
    const val RETRY_AFTER = "Retry-After"

    /** 429 时恒为 0；多窗口取最小值（§7.5）。 */
    const val X_RATE_LIMIT_REMAINING = "X-RateLimit-Remaining"
}
