package de.ncards.core.network.api

import de.ncards.core.network.api.infrastructure.CollectionFormats.*
import retrofit2.http.*
import retrofit2.Response
import okhttp3.RequestBody
import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable

import de.ncards.core.network.api.model.MagicLinkConsumption
import de.ncards.core.network.api.model.OtpChallenge
import de.ncards.core.network.api.model.OtpRequest
import de.ncards.core.network.api.model.OtpVerification
import de.ncards.core.network.api.model.Problem
import de.ncards.core.network.api.model.Session
import de.ncards.core.network.api.model.TokenRefresh

interface AuthApi {
    /**
     * POST auth/magic/consume
     * 消费 Magic Link 令牌
     * ⚠️ **必须是 POST**。企业邮件安全网关（Microsoft Defender、Barracuda 等） 会自动 &#x60;GET&#x60; 邮件里的所有链接做扫描——如果 Magic Link 是 &#x60;GET&#x60; 即消费， 用户还没点开就已失效（§7.1）。邮件里的链接指向落地页，&#x60;GET&#x60; 只渲染 「点击继续登录」按钮，实际消费走本端点。  ⚠️ **调用方是 App，不是落地页**（[ADR-0016](https://github.com/ht-lin/n-cards/blob/main/docs/adr/0016-magic-link-delivery-and-landing-page.md)）。 落地页是一份静态 HTML，它的按钮通过 App Links / &#x60;intent://&#x60; 把令牌交给 App， 由 App 带上自己的 &#x60;device&#x60; 发本请求。浏览器构造不出合法的请求体 —— &#x60;device.platform&#x60; 的取值域只有 &#x60;android&#x60;，&#x60;device.id&#x60; 是安装级的客户端生成 UUID。  令牌与 &#x60;POST /auth/otp/request&#x60; 那封信里的 6 位码挂在**同一条**挑战上： 用掉任何一个，另一个立刻 401。重复消费同一个令牌同样 401。  限速（§7.5）：按 IP 60/h。**没有**按挑战的次数上限 —— 令牌是 32 字节 CSPRNG，猜错的令牌找不到任何一行可以累加。 
     * Responses:
     *  - 200: 登录成功
     *  - 400: `validation_failed`（字段校验失败，带 `errors[]`）或 `malformed_request`（body 不是合法 JSON / 不是 JSON 对象 / 为空）。  缺失或格式错误的 `X-Client`、缺失的 `If-Match`、以及任何 offset 风格的 分页参数（`offset` / `page` / `skip` / `per_page` / `start`， `errors[].code = unsupported_parameter`）也都走这里。 
     *  - 401: `token_expired`（静默刷新后重试**一次**）或 `token_invalid`（会话已撤销 → 清空本地会话，跳登录，**不要重试**）。 
     *  - 404: `not_found`。  ⚠️ 对**非成员**访问一张存在的卡，服务端返回的是 `403 not_a_member` 还是 `404 not_found`，取决于该资源是否属于「存在性本身即信息」的一类。 卡走 `403`（成员关系是明确的授权概念）；含 username 的查找走 `404`。 
     *  - 409: `revision_conflict`（乐观锁失败，`current` 带服务端状态 → 走 §5.4.3 冲突解决）、 `id_conflict`（客户端生成的 id 已属于他人 → **重新生成 id 重试**）、 `already_exists`（幂等处理）、`idempotency_in_progress`（带 `Retry-After`，退避重试）、 `full_resync_required`（清库全量重同步）。 
     *  - 413: `payload_too_large`。客户端 bug，上报 Sentry。
     *  - 415: `unsupported_media_type`：`Content-Type` 不是 `application/json`。客户端 bug，上报 Sentry。
     *  - 422: `limit_exceeded`（**系统限额**，§7.5 的第一张表）、`username_invalid`、 `idempotency_key_reused`（**不要重试**，上报 Sentry）。  ⚠️ `422 limit_exceeded` 与限流（`429`）**完全是两回事**：前者是绝对的存量 上限，重试**永远**不会成功，UI 应该显示「额度已满」而不是「稍后重试」。 
     *  - 426: `client_too_old`：低于 `/v1/config` 下发的 `min_supported_client`。客户端显示强制升级墙。
     *  - 429: `rate_limited`：「我判定你超限了」，按 `Retry-After` 退避重试（§7.5）。  ⚠️ **必须**同时带 `Retry-After`（秒数）与 `X-RateLimit-Remaining`（恒为 0）。 与 `503 service_unavailable`（「我**无法判定**」——Redis 不可达且该策略 fail-closed）不是一回事，见 ADR-0005。 
     *  - 500: `internal_error`。`detail` 恒为固定文案，**绝不回显**原始异常消息 （那会泄露主机名、端口、SQL 片段）。客户端提示稍后重试并上报 Sentry。 
     *  - 503: `service_unavailable`：维护中，或限流器**无法判定**（Redis 不可达 + 该策略 fail-closed，ADR-0005）。这是服务端故障，走 §5.4.3 的 outbox 重试策略。 
     *
     * @param xClient **所有 &#x60;/v1&#x60; 请求必填**（§6.1）。缺失或格式不符即 &#x60;400 validation_failed&#x60;。 用于强制升级判定（低于 &#x60;min_supported_client&#x60; → &#x60;426 client_too_old&#x60;）与指标切分。  pattern 与后端 &#x60;App\\Shared\\Domain\\Client\\ClientVersion::PATTERN&#x60; 的**判定结果 逐例一致**，由 &#x60;backend/tests/Api/OpenApiDocumentTest&#x60; 用一张对照表断言 —— 改一处不改另一处，CI 立刻红。 （唯一的差别是后端会先 &#x60;trim()&#x60; 一次，正则表达不了这个。）  ⚠️ &#x60;/health/live&#x60; 与 &#x60;/health/ready&#x60; **不在 &#x60;/v1&#x60; 下**，因此不需要这个头—— 它们的调用方是 Docker healthcheck、Caddy 与 Ansible（§14.2）。 那两个端点刻意不出现在本契约里。 
     * @param magicLinkConsumption 
     * @param idempotencyKey 幂等键（UUID），Redis 存 24h（§6.1 / ADR-0003）。所有 &#x60;POST&#x60; 支持。  同一个 key 配**相同**请求体 → 回放此前的响应，并带上 &#x60;Idempotency-Replayed: true&#x60;。 同一个 key 配**不同**请求体 → &#x60;422 idempotency_key_reused&#x60;， 这是客户端 bug，**不要重试**，上报 Sentry。 前一次请求仍在处理中 → &#x60;409 idempotency_in_progress&#x60;，带 &#x60;Retry-After&#x60;，退避重试。  ⚠️ Redis 不可达时幂等是 **fail-OPEN**（照常执行，不保证幂等）， 而限流是 fail-CLOSED。这个不对称是刻意的，理由见 ADR-0003。  (optional)
     * @return [Session]
     */
    @POST("auth/magic/consume")
    suspend fun consumeMagicLink(@Header("X-Client") xClient: kotlin.String, @Body magicLinkConsumption: MagicLinkConsumption, @Header("Idempotency-Key") idempotencyKey: java.util.UUID? = null): Response<Session>

    /**
     * POST auth/logout
     * 撤销当前会话
     * 这是 auth 组里**唯一需要 Bearer** 的端点。撤销当前 session； access token 不做黑名单（15 分钟窗口可接受，§7.1），但 refresh 立即失效。  &#x60;onboarding_incomplete&#x60; 的用户也可以调用（否则中途放弃注册的人无法登出）。 
     * Responses:
     *  - 204: 已登出（无响应体）
     *  - 400: `validation_failed`（字段校验失败，带 `errors[]`）或 `malformed_request`（body 不是合法 JSON / 不是 JSON 对象 / 为空）。  缺失或格式错误的 `X-Client`、缺失的 `If-Match`、以及任何 offset 风格的 分页参数（`offset` / `page` / `skip` / `per_page` / `start`， `errors[].code = unsupported_parameter`）也都走这里。 
     *  - 401: `token_expired`（静默刷新后重试**一次**）或 `token_invalid`（会话已撤销 → 清空本地会话，跳登录，**不要重试**）。 
     *  - 409: `revision_conflict`（乐观锁失败，`current` 带服务端状态 → 走 §5.4.3 冲突解决）、 `id_conflict`（客户端生成的 id 已属于他人 → **重新生成 id 重试**）、 `already_exists`（幂等处理）、`idempotency_in_progress`（带 `Retry-After`，退避重试）、 `full_resync_required`（清库全量重同步）。 
     *  - 426: `client_too_old`：低于 `/v1/config` 下发的 `min_supported_client`。客户端显示强制升级墙。
     *  - 429: `rate_limited`：「我判定你超限了」，按 `Retry-After` 退避重试（§7.5）。  ⚠️ **必须**同时带 `Retry-After`（秒数）与 `X-RateLimit-Remaining`（恒为 0）。 与 `503 service_unavailable`（「我**无法判定**」——Redis 不可达且该策略 fail-closed）不是一回事，见 ADR-0005。 
     *  - 500: `internal_error`。`detail` 恒为固定文案，**绝不回显**原始异常消息 （那会泄露主机名、端口、SQL 片段）。客户端提示稍后重试并上报 Sentry。 
     *  - 503: `service_unavailable`：维护中，或限流器**无法判定**（Redis 不可达 + 该策略 fail-closed，ADR-0005）。这是服务端故障，走 §5.4.3 的 outbox 重试策略。 
     *
     * @param xClient **所有 &#x60;/v1&#x60; 请求必填**（§6.1）。缺失或格式不符即 &#x60;400 validation_failed&#x60;。 用于强制升级判定（低于 &#x60;min_supported_client&#x60; → &#x60;426 client_too_old&#x60;）与指标切分。  pattern 与后端 &#x60;App\\Shared\\Domain\\Client\\ClientVersion::PATTERN&#x60; 的**判定结果 逐例一致**，由 &#x60;backend/tests/Api/OpenApiDocumentTest&#x60; 用一张对照表断言 —— 改一处不改另一处，CI 立刻红。 （唯一的差别是后端会先 &#x60;trim()&#x60; 一次，正则表达不了这个。）  ⚠️ &#x60;/health/live&#x60; 与 &#x60;/health/ready&#x60; **不在 &#x60;/v1&#x60; 下**，因此不需要这个头—— 它们的调用方是 Docker healthcheck、Caddy 与 Ansible（§14.2）。 那两个端点刻意不出现在本契约里。 
     * @param idempotencyKey 幂等键（UUID），Redis 存 24h（§6.1 / ADR-0003）。所有 &#x60;POST&#x60; 支持。  同一个 key 配**相同**请求体 → 回放此前的响应，并带上 &#x60;Idempotency-Replayed: true&#x60;。 同一个 key 配**不同**请求体 → &#x60;422 idempotency_key_reused&#x60;， 这是客户端 bug，**不要重试**，上报 Sentry。 前一次请求仍在处理中 → &#x60;409 idempotency_in_progress&#x60;，带 &#x60;Retry-After&#x60;，退避重试。  ⚠️ Redis 不可达时幂等是 **fail-OPEN**（照常执行，不保证幂等）， 而限流是 fail-CLOSED。这个不对称是刻意的，理由见 ADR-0003。  (optional)
     * @return [Unit]
     */
    @POST("auth/logout")
    suspend fun logout(@Header("X-Client") xClient: kotlin.String, @Header("Idempotency-Key") idempotencyKey: java.util.UUID? = null): Response<Unit>

    /**
     * POST auth/token/refresh
     * 轮换令牌对
     * 每次刷新签发**新的** refresh token，旧的立即失效（§7.1）。  ⚠️ 收到一个**已被使用过**的 refresh token 会被判定为令牌被窃：撤销该会话 家族的全部令牌、写 &#x60;audit_log(reuse_detected)&#x60;、告警、给用户发安全提醒邮件。 客户端拿到 &#x60;401 token_invalid&#x60; 时必须清空本地会话并跳登录，**不要重试**。  限速（§7.5）：按 session 60/h。 
     * Responses:
     *  - 200: 轮换后的新令牌对
     *  - 400: `validation_failed`（字段校验失败，带 `errors[]`）或 `malformed_request`（body 不是合法 JSON / 不是 JSON 对象 / 为空）。  缺失或格式错误的 `X-Client`、缺失的 `If-Match`、以及任何 offset 风格的 分页参数（`offset` / `page` / `skip` / `per_page` / `start`， `errors[].code = unsupported_parameter`）也都走这里。 
     *  - 401: `token_expired`（静默刷新后重试**一次**）或 `token_invalid`（会话已撤销 → 清空本地会话，跳登录，**不要重试**）。 
     *  - 409: `revision_conflict`（乐观锁失败，`current` 带服务端状态 → 走 §5.4.3 冲突解决）、 `id_conflict`（客户端生成的 id 已属于他人 → **重新生成 id 重试**）、 `already_exists`（幂等处理）、`idempotency_in_progress`（带 `Retry-After`，退避重试）、 `full_resync_required`（清库全量重同步）。 
     *  - 413: `payload_too_large`。客户端 bug，上报 Sentry。
     *  - 415: `unsupported_media_type`：`Content-Type` 不是 `application/json`。客户端 bug，上报 Sentry。
     *  - 422: `limit_exceeded`（**系统限额**，§7.5 的第一张表）、`username_invalid`、 `idempotency_key_reused`（**不要重试**，上报 Sentry）。  ⚠️ `422 limit_exceeded` 与限流（`429`）**完全是两回事**：前者是绝对的存量 上限，重试**永远**不会成功，UI 应该显示「额度已满」而不是「稍后重试」。 
     *  - 426: `client_too_old`：低于 `/v1/config` 下发的 `min_supported_client`。客户端显示强制升级墙。
     *  - 429: `rate_limited`：「我判定你超限了」，按 `Retry-After` 退避重试（§7.5）。  ⚠️ **必须**同时带 `Retry-After`（秒数）与 `X-RateLimit-Remaining`（恒为 0）。 与 `503 service_unavailable`（「我**无法判定**」——Redis 不可达且该策略 fail-closed）不是一回事，见 ADR-0005。 
     *  - 500: `internal_error`。`detail` 恒为固定文案，**绝不回显**原始异常消息 （那会泄露主机名、端口、SQL 片段）。客户端提示稍后重试并上报 Sentry。 
     *  - 503: `service_unavailable`：维护中，或限流器**无法判定**（Redis 不可达 + 该策略 fail-closed，ADR-0005）。这是服务端故障，走 §5.4.3 的 outbox 重试策略。 
     *
     * @param xClient **所有 &#x60;/v1&#x60; 请求必填**（§6.1）。缺失或格式不符即 &#x60;400 validation_failed&#x60;。 用于强制升级判定（低于 &#x60;min_supported_client&#x60; → &#x60;426 client_too_old&#x60;）与指标切分。  pattern 与后端 &#x60;App\\Shared\\Domain\\Client\\ClientVersion::PATTERN&#x60; 的**判定结果 逐例一致**，由 &#x60;backend/tests/Api/OpenApiDocumentTest&#x60; 用一张对照表断言 —— 改一处不改另一处，CI 立刻红。 （唯一的差别是后端会先 &#x60;trim()&#x60; 一次，正则表达不了这个。）  ⚠️ &#x60;/health/live&#x60; 与 &#x60;/health/ready&#x60; **不在 &#x60;/v1&#x60; 下**，因此不需要这个头—— 它们的调用方是 Docker healthcheck、Caddy 与 Ansible（§14.2）。 那两个端点刻意不出现在本契约里。 
     * @param tokenRefresh 
     * @param idempotencyKey 幂等键（UUID），Redis 存 24h（§6.1 / ADR-0003）。所有 &#x60;POST&#x60; 支持。  同一个 key 配**相同**请求体 → 回放此前的响应，并带上 &#x60;Idempotency-Replayed: true&#x60;。 同一个 key 配**不同**请求体 → &#x60;422 idempotency_key_reused&#x60;， 这是客户端 bug，**不要重试**，上报 Sentry。 前一次请求仍在处理中 → &#x60;409 idempotency_in_progress&#x60;，带 &#x60;Retry-After&#x60;，退避重试。  ⚠️ Redis 不可达时幂等是 **fail-OPEN**（照常执行，不保证幂等）， 而限流是 fail-CLOSED。这个不对称是刻意的，理由见 ADR-0003。  (optional)
     * @return [Session]
     */
    @POST("auth/token/refresh")
    suspend fun refreshToken(@Header("X-Client") xClient: kotlin.String, @Body tokenRefresh: TokenRefresh, @Header("Idempotency-Key") idempotencyKey: java.util.UUID? = null): Response<Session>

    /**
     * POST auth/otp/request
     * 请求登录用的一次性验证码
     * **恒返回 202**，无论该邮箱是否已注册（§3.8 防账号枚举）。  服务端在这条路径上**不查 &#x60;users&#x60;** —— 它在结构上就不知道邮箱注册过没有， 因此对任意地址都生成并发送一个真实的验证码（[ADR-0014](https://github.com/ht-lin/n-cards/blob/main/docs/adr/0014-otp-always-sends-a-code.md)）。 这也是**唯一的注册路径**：首次 &#x60;POST /auth/otp/verify&#x60; 成功即创建 &#x60;users&#x60; 行。  客户端**不能**从本接口的响应推断账号是否存在，也**不应该**尝试 —— 「这个邮箱要走登录还是注册」对客户端是同一个流程（邮箱 → 验证码 → username）。  发出去的那封信里同时有**两样东西**：6 位码，以及一个免输码的 Magic Link （[ADR-0016](https://github.com/ht-lin/n-cards/blob/main/docs/adr/0016-magic-link-delivery-and-landing-page.md)）。 两者挂在**同一条**挑战上、共用一个 &#x60;consumed_at&#x60;，所以它们是同一次登录的 两个入口而不是两次机会 —— 用掉任何一个，另一个立刻失效。 链接的消费走 &#x60;POST /auth/magic/consume&#x60;。  限速（§7.5）：按 &#x60;email_hash&#x60; 1/min、5/h、10/day；按 IP 20/h。 这三个窗口是本端点唯一的滥用闸门 —— 它挡的是「用 N-Cards 的域名给别人的 收件箱发信」，所以 429 时**不要**自动重试，照 &#x60;Retry-After&#x60; 退避。 
     * Responses:
     *  - 202: 挑战已创建（无论邮箱是否存在）
     *  - 400: `validation_failed`（字段校验失败，带 `errors[]`）或 `malformed_request`（body 不是合法 JSON / 不是 JSON 对象 / 为空）。  缺失或格式错误的 `X-Client`、缺失的 `If-Match`、以及任何 offset 风格的 分页参数（`offset` / `page` / `skip` / `per_page` / `start`， `errors[].code = unsupported_parameter`）也都走这里。 
     *  - 409: `revision_conflict`（乐观锁失败，`current` 带服务端状态 → 走 §5.4.3 冲突解决）、 `id_conflict`（客户端生成的 id 已属于他人 → **重新生成 id 重试**）、 `already_exists`（幂等处理）、`idempotency_in_progress`（带 `Retry-After`，退避重试）、 `full_resync_required`（清库全量重同步）。 
     *  - 413: `payload_too_large`。客户端 bug，上报 Sentry。
     *  - 415: `unsupported_media_type`：`Content-Type` 不是 `application/json`。客户端 bug，上报 Sentry。
     *  - 422: `limit_exceeded`（**系统限额**，§7.5 的第一张表）、`username_invalid`、 `idempotency_key_reused`（**不要重试**，上报 Sentry）。  ⚠️ `422 limit_exceeded` 与限流（`429`）**完全是两回事**：前者是绝对的存量 上限，重试**永远**不会成功，UI 应该显示「额度已满」而不是「稍后重试」。 
     *  - 426: `client_too_old`：低于 `/v1/config` 下发的 `min_supported_client`。客户端显示强制升级墙。
     *  - 429: `rate_limited`：「我判定你超限了」，按 `Retry-After` 退避重试（§7.5）。  ⚠️ **必须**同时带 `Retry-After`（秒数）与 `X-RateLimit-Remaining`（恒为 0）。 与 `503 service_unavailable`（「我**无法判定**」——Redis 不可达且该策略 fail-closed）不是一回事，见 ADR-0005。 
     *  - 500: `internal_error`。`detail` 恒为固定文案，**绝不回显**原始异常消息 （那会泄露主机名、端口、SQL 片段）。客户端提示稍后重试并上报 Sentry。 
     *  - 503: `service_unavailable`：维护中，或限流器**无法判定**（Redis 不可达 + 该策略 fail-closed，ADR-0005）。这是服务端故障，走 §5.4.3 的 outbox 重试策略。 
     *
     * @param xClient **所有 &#x60;/v1&#x60; 请求必填**（§6.1）。缺失或格式不符即 &#x60;400 validation_failed&#x60;。 用于强制升级判定（低于 &#x60;min_supported_client&#x60; → &#x60;426 client_too_old&#x60;）与指标切分。  pattern 与后端 &#x60;App\\Shared\\Domain\\Client\\ClientVersion::PATTERN&#x60; 的**判定结果 逐例一致**，由 &#x60;backend/tests/Api/OpenApiDocumentTest&#x60; 用一张对照表断言 —— 改一处不改另一处，CI 立刻红。 （唯一的差别是后端会先 &#x60;trim()&#x60; 一次，正则表达不了这个。）  ⚠️ &#x60;/health/live&#x60; 与 &#x60;/health/ready&#x60; **不在 &#x60;/v1&#x60; 下**，因此不需要这个头—— 它们的调用方是 Docker healthcheck、Caddy 与 Ansible（§14.2）。 那两个端点刻意不出现在本契约里。 
     * @param otpRequest 
     * @param idempotencyKey 幂等键（UUID），Redis 存 24h（§6.1 / ADR-0003）。所有 &#x60;POST&#x60; 支持。  同一个 key 配**相同**请求体 → 回放此前的响应，并带上 &#x60;Idempotency-Replayed: true&#x60;。 同一个 key 配**不同**请求体 → &#x60;422 idempotency_key_reused&#x60;， 这是客户端 bug，**不要重试**，上报 Sentry。 前一次请求仍在处理中 → &#x60;409 idempotency_in_progress&#x60;，带 &#x60;Retry-After&#x60;，退避重试。  ⚠️ Redis 不可达时幂等是 **fail-OPEN**（照常执行，不保证幂等）， 而限流是 fail-CLOSED。这个不对称是刻意的，理由见 ADR-0003。  (optional)
     * @return [OtpChallenge]
     */
    @POST("auth/otp/request")
    suspend fun requestOtp(@Header("X-Client") xClient: kotlin.String, @Body otpRequest: OtpRequest, @Header("Idempotency-Key") idempotencyKey: java.util.UUID? = null): Response<OtpChallenge>

    /**
     * POST auth/otp/verify
     * 校验验证码，换取令牌对
     * 首次验证成功即注册（创建 &#x60;users&#x60; 行）。此时 &#x60;username&#x60; 仍为 &#x60;null&#x60;， 用户处于 &#x60;onboarding_incomplete&#x60; 状态：除 &#x60;GET /me&#x60;、&#x60;POST /me/username&#x60;、 &#x60;POST /auth/logout&#x60; 外，所有 &#x60;/v1&#x60; 端点对其返回 &#x60;403 username_required&#x60;。 客户端据 &#x60;user.onboarding_complete&#x60; 路由到 username 设定页，**该页不可跳过**（§5.2）。  限速（§7.5）：按 &#x60;challenge_id&#x60; 5 次总计；按 IP 60/h。 
     * Responses:
     *  - 200: 登录成功
     *  - 400: `validation_failed`（字段校验失败，带 `errors[]`）或 `malformed_request`（body 不是合法 JSON / 不是 JSON 对象 / 为空）。  缺失或格式错误的 `X-Client`、缺失的 `If-Match`、以及任何 offset 风格的 分页参数（`offset` / `page` / `skip` / `per_page` / `start`， `errors[].code = unsupported_parameter`）也都走这里。 
     *  - 401: `token_expired`（静默刷新后重试**一次**）或 `token_invalid`（会话已撤销 → 清空本地会话，跳登录，**不要重试**）。 
     *  - 404: `not_found`。  ⚠️ 对**非成员**访问一张存在的卡，服务端返回的是 `403 not_a_member` 还是 `404 not_found`，取决于该资源是否属于「存在性本身即信息」的一类。 卡走 `403`（成员关系是明确的授权概念）；含 username 的查找走 `404`。 
     *  - 409: `revision_conflict`（乐观锁失败，`current` 带服务端状态 → 走 §5.4.3 冲突解决）、 `id_conflict`（客户端生成的 id 已属于他人 → **重新生成 id 重试**）、 `already_exists`（幂等处理）、`idempotency_in_progress`（带 `Retry-After`，退避重试）、 `full_resync_required`（清库全量重同步）。 
     *  - 413: `payload_too_large`。客户端 bug，上报 Sentry。
     *  - 415: `unsupported_media_type`：`Content-Type` 不是 `application/json`。客户端 bug，上报 Sentry。
     *  - 422: `limit_exceeded`（**系统限额**，§7.5 的第一张表）、`username_invalid`、 `idempotency_key_reused`（**不要重试**，上报 Sentry）。  ⚠️ `422 limit_exceeded` 与限流（`429`）**完全是两回事**：前者是绝对的存量 上限，重试**永远**不会成功，UI 应该显示「额度已满」而不是「稍后重试」。 
     *  - 426: `client_too_old`：低于 `/v1/config` 下发的 `min_supported_client`。客户端显示强制升级墙。
     *  - 429: `rate_limited`：「我判定你超限了」，按 `Retry-After` 退避重试（§7.5）。  ⚠️ **必须**同时带 `Retry-After`（秒数）与 `X-RateLimit-Remaining`（恒为 0）。 与 `503 service_unavailable`（「我**无法判定**」——Redis 不可达且该策略 fail-closed）不是一回事，见 ADR-0005。 
     *  - 500: `internal_error`。`detail` 恒为固定文案，**绝不回显**原始异常消息 （那会泄露主机名、端口、SQL 片段）。客户端提示稍后重试并上报 Sentry。 
     *  - 503: `service_unavailable`：维护中，或限流器**无法判定**（Redis 不可达 + 该策略 fail-closed，ADR-0005）。这是服务端故障，走 §5.4.3 的 outbox 重试策略。 
     *
     * @param xClient **所有 &#x60;/v1&#x60; 请求必填**（§6.1）。缺失或格式不符即 &#x60;400 validation_failed&#x60;。 用于强制升级判定（低于 &#x60;min_supported_client&#x60; → &#x60;426 client_too_old&#x60;）与指标切分。  pattern 与后端 &#x60;App\\Shared\\Domain\\Client\\ClientVersion::PATTERN&#x60; 的**判定结果 逐例一致**，由 &#x60;backend/tests/Api/OpenApiDocumentTest&#x60; 用一张对照表断言 —— 改一处不改另一处，CI 立刻红。 （唯一的差别是后端会先 &#x60;trim()&#x60; 一次，正则表达不了这个。）  ⚠️ &#x60;/health/live&#x60; 与 &#x60;/health/ready&#x60; **不在 &#x60;/v1&#x60; 下**，因此不需要这个头—— 它们的调用方是 Docker healthcheck、Caddy 与 Ansible（§14.2）。 那两个端点刻意不出现在本契约里。 
     * @param otpVerification 
     * @param idempotencyKey 幂等键（UUID），Redis 存 24h（§6.1 / ADR-0003）。所有 &#x60;POST&#x60; 支持。  同一个 key 配**相同**请求体 → 回放此前的响应，并带上 &#x60;Idempotency-Replayed: true&#x60;。 同一个 key 配**不同**请求体 → &#x60;422 idempotency_key_reused&#x60;， 这是客户端 bug，**不要重试**，上报 Sentry。 前一次请求仍在处理中 → &#x60;409 idempotency_in_progress&#x60;，带 &#x60;Retry-After&#x60;，退避重试。  ⚠️ Redis 不可达时幂等是 **fail-OPEN**（照常执行，不保证幂等）， 而限流是 fail-CLOSED。这个不对称是刻意的，理由见 ADR-0003。  (optional)
     * @return [Session]
     */
    @POST("auth/otp/verify")
    suspend fun verifyOtp(@Header("X-Client") xClient: kotlin.String, @Body otpVerification: OtpVerification, @Header("Idempotency-Key") idempotencyKey: java.util.UUID? = null): Response<Session>

}
