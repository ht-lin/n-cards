package de.ncards.data.auth

import de.ncards.core.network.api.model.Session
import de.ncards.core.network.api.model.User
import java.time.OffsetDateTime
import java.util.UUID

/** 各用例共用的假数据。形状照契约的 example（`docs/api/openapi.yaml`）。 */
internal object AuthFixtures {
    val USER_ID: UUID = UUID.fromString("0192f3a1-b2c3-7d4e-8f01-00000000a11a")

    fun user(
        username: String? = "anna_b",
        onboardingComplete: Boolean = true,
    ): User =
        User(
            id = USER_ID,
            username = username,
            locale = User.Locale.de,
            onboardingComplete = onboardingComplete,
            createdAt = OffsetDateTime.parse("2026-08-29T10:30:12Z"),
        )

    fun session(
        accessToken: String = "access-1",
        refreshToken: String = "refresh-1",
    ): Session =
        Session(
            accessToken = accessToken,
            expiresIn = ACCESS_TTL_SECONDS,
            refreshToken = refreshToken,
            user = user(),
        )

    /** §7.1：15 分钟。 */
    const val ACCESS_TTL_SECONDS = 900

    /** 一个 `Session` 的 JSON 响应体，给 MockWebServer 用。 */
    fun sessionJson(
        accessToken: String,
        refreshToken: String,
    ): String =
        """
        {
          "access_token": "$accessToken",
          "expires_in": $ACCESS_TTL_SECONDS,
          "refresh_token": "$refreshToken",
          "user": $USER_JSON
        }
        """.trimIndent()

    /** `GET /v1/me` 的 `UserEnvelope`。 */
    fun userEnvelopeJson(): String = """{ "user": $USER_JSON }"""

    private val USER_JSON =
        """
        {
          "id": "$USER_ID",
          "username": "anna_b",
          "locale": "de",
          "onboarding_complete": true,
          "created_at": "2026-08-29T10:30:12Z"
        }
        """.trimIndent()

    /**
     * §6.1 的 problem+json。`code` 是客户端唯一可以分支的字段 ——
     * `detail` 存在只是为了让报文形状真实，没有任何代码读它。
     */
    fun problemJson(
        status: Int,
        code: String,
    ): String =
        """
        {
          "type": "https://api.n-cards.de/problems/${code.replace('_', '-')}",
          "title": "$code",
          "status": $status,
          "code": "$code",
          "detail": "Developer-facing English text that the client must never parse.",
          "instance": "/v1/cards",
          "request_id": "0192f3a1-b2c3-7d4e-8f01-23456789abcd"
        }
        """.trimIndent()
}
