package de.ncards.core.network.api

import de.ncards.core.network.api.infrastructure.CollectionFormats.*
import retrofit2.http.*
import retrofit2.Response
import okhttp3.RequestBody
import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable

import de.ncards.core.network.api.model.DeviceList
import de.ncards.core.network.api.model.Problem
import de.ncards.core.network.api.model.PushTokenUpdate

interface MeApi {
    /**
     * GET me/devices
     * 我的设备列表
     * 只返回**未撤销**的设备，按 &#x60;last_seen_at&#x60; 倒序。  &#x60;is_current&#x60; 标记发起本次请求的那台设备 —— 它由 access token 的 &#x60;did&#x60; 比对得出，是**请求**的属性而不是设备行的属性（同一台设备在另一个请求里 就不是当前的了）。  **不分页**：§7.5 没有给设备数设限额，但真实上限是个位数。  ⚠️ 响应里**没有** &#x60;push_token&#x60;。它是一个发给第三方（FCM / Google Ireland） 的标识符，ROPA §8.2 登记的用途只有投递推送；回显它不服务于任何用例 （客户端自己刚上报过），却会让它多出现在一处响应体里。 &#x60;push_token_updated_at&#x60; 有 —— 客户端要靠它判断自己上报的那个还新不新鲜。 
     * Responses:
     *  - 200: 设备列表
     *  - 401: `token_expired`（静默刷新后重试**一次**）或 `token_invalid`（会话已撤销 → 清空本地会话，跳登录，**不要重试**）。 
     *  - 403: `insufficient_role`（viewer 试图改卡/删卡/邀请——**同时上报 Sentry**， 正常 UI 不应产生此请求）、`not_a_member`（从本地删除该卡）、 `username_required`（跳 username 设定页）、`not_friends`。 
     *  - 426: `client_too_old`：低于 `/v1/config` 下发的 `min_supported_client`。客户端显示强制升级墙。
     *  - 429: `rate_limited`：「我判定你超限了」，按 `Retry-After` 退避重试（§7.5）。  ⚠️ **必须**同时带 `Retry-After`（秒数）与 `X-RateLimit-Remaining`（恒为 0）。 与 `503 service_unavailable`（「我**无法判定**」——Redis 不可达且该策略 fail-closed）不是一回事，见 ADR-0005。 
     *  - 500: `internal_error`。`detail` 恒为固定文案，**绝不回显**原始异常消息 （那会泄露主机名、端口、SQL 片段）。客户端提示稍后重试并上报 Sentry。 
     *  - 503: `service_unavailable`：维护中，或限流器**无法判定**（Redis 不可达 + 该策略 fail-closed，ADR-0005）。这是服务端故障，走 §5.4.3 的 outbox 重试策略。 
     *
     * @param xClient **所有 &#x60;/v1&#x60; 请求必填**（§6.1）。缺失或格式不符即 &#x60;400 validation_failed&#x60;。 用于强制升级判定（低于 &#x60;min_supported_client&#x60; → &#x60;426 client_too_old&#x60;）与指标切分。  pattern 与后端 &#x60;App\\Shared\\Domain\\Client\\ClientVersion::PATTERN&#x60; 的**判定结果 逐例一致**，由 &#x60;backend/tests/Api/OpenApiDocumentTest&#x60; 用一张对照表断言 —— 改一处不改另一处，CI 立刻红。 （唯一的差别是后端会先 &#x60;trim()&#x60; 一次，正则表达不了这个。）  ⚠️ &#x60;/health/live&#x60; 与 &#x60;/health/ready&#x60; **不在 &#x60;/v1&#x60; 下**，因此不需要这个头—— 它们的调用方是 Docker healthcheck、Caddy 与 Ansible（§14.2）。 那两个端点刻意不出现在本契约里。 
     * @return [DeviceList]
     */
    @GET("me/devices")
    suspend fun listDevices(@Header("X-Client") xClient: kotlin.String): Response<DeviceList>

    /**
     * DELETE me/devices/{deviceId}
     * 远程登出某设备
     * 撤销这台设备**并且**撤销它的全部会话（&#x60;revoked_reason &#x3D; user_revoked&#x60;）。  ⚠️ 两件事都做才有意义：只撤设备的话，那台机器手里的 refresh token 仍然有效 90 天。撤销后该设备的下一次 &#x60;POST /auth/token/refresh&#x60; 立刻 401。  ⚠️ **已签发的 access token 不会失效**（§7.1 不做黑名单）， 所以被踢的设备最长还能读 15 分钟。这是被接受的窗口，见 ADR-0015。  撤销同时清空 &#x60;push_token&#x60;（ROPA §8.2：设备撤销后即删）。  允许删自己当前这台，效果等同登出。**幂等**：重复删仍然 204， 且不会重置「什么时候被踢下线的」这个时间戳。  那台设备之后仍然可以用邮箱验证码重新登录（&#x60;devices.id&#x60; 会被复活并按 新设备处理，含提醒信）—— 远程登出不是封禁。 
     * Responses:
     *  - 204: 已撤销（无响应体）
     *  - 401: `token_expired`（静默刷新后重试**一次**）或 `token_invalid`（会话已撤销 → 清空本地会话，跳登录，**不要重试**）。 
     *  - 403: `insufficient_role`（viewer 试图改卡/删卡/邀请——**同时上报 Sentry**， 正常 UI 不应产生此请求）、`not_a_member`（从本地删除该卡）、 `username_required`（跳 username 设定页）、`not_friends`。 
     *  - 404: `not_found`。  ⚠️ 对**非成员**访问一张存在的卡，服务端返回的是 `403 not_a_member` 还是 `404 not_found`，取决于该资源是否属于「存在性本身即信息」的一类。 卡走 `403`（成员关系是明确的授权概念）；含 username 的查找走 `404`。 
     *  - 409: `revision_conflict`（乐观锁失败，`current` 带服务端状态 → 走 §5.4.3 冲突解决）、 `id_conflict`（客户端生成的 id 已属于他人 → **重新生成 id 重试**）、 `already_exists`（幂等处理）、`idempotency_in_progress`（带 `Retry-After`，退避重试）、 `full_resync_required`（清库全量重同步）。 
     *  - 426: `client_too_old`：低于 `/v1/config` 下发的 `min_supported_client`。客户端显示强制升级墙。
     *  - 429: `rate_limited`：「我判定你超限了」，按 `Retry-After` 退避重试（§7.5）。  ⚠️ **必须**同时带 `Retry-After`（秒数）与 `X-RateLimit-Remaining`（恒为 0）。 与 `503 service_unavailable`（「我**无法判定**」——Redis 不可达且该策略 fail-closed）不是一回事，见 ADR-0005。 
     *  - 500: `internal_error`。`detail` 恒为固定文案，**绝不回显**原始异常消息 （那会泄露主机名、端口、SQL 片段）。客户端提示稍后重试并上报 Sentry。 
     *  - 503: `service_unavailable`：维护中，或限流器**无法判定**（Redis 不可达 + 该策略 fail-closed，ADR-0005）。这是服务端故障，走 §5.4.3 的 outbox 重试策略。 
     *
     * @param xClient **所有 &#x60;/v1&#x60; 请求必填**（§6.1）。缺失或格式不符即 &#x60;400 validation_failed&#x60;。 用于强制升级判定（低于 &#x60;min_supported_client&#x60; → &#x60;426 client_too_old&#x60;）与指标切分。  pattern 与后端 &#x60;App\\Shared\\Domain\\Client\\ClientVersion::PATTERN&#x60; 的**判定结果 逐例一致**，由 &#x60;backend/tests/Api/OpenApiDocumentTest&#x60; 用一张对照表断言 —— 改一处不改另一处，CI 立刻红。 （唯一的差别是后端会先 &#x60;trim()&#x60; 一次，正则表达不了这个。）  ⚠️ &#x60;/health/live&#x60; 与 &#x60;/health/ready&#x60; **不在 &#x60;/v1&#x60; 下**，因此不需要这个头—— 它们的调用方是 Docker healthcheck、Caddy 与 Ansible（§14.2）。 那两个端点刻意不出现在本契约里。 
     * @param deviceId 设备 id（客户端生成，安装级唯一，§5.2）。  ⚠️ 它**不是凭据** —— 服务端校验这台设备属于当前用户，不属于则 &#x60;404&#x60; （不是 &#x60;403&#x60;，理由见 &#x60;/me/devices&#x60; 那一组的说明）。 格式非法同样返回 &#x60;404&#x60; 而不是 &#x60;422&#x60;，出于同一个理由。 
     * @param idempotencyKey 幂等键（UUID），Redis 存 24h（§6.1 / ADR-0003）。所有 &#x60;POST&#x60; 支持。  同一个 key 配**相同**请求体 → 回放此前的响应，并带上 &#x60;Idempotency-Replayed: true&#x60;。 同一个 key 配**不同**请求体 → &#x60;422 idempotency_key_reused&#x60;， 这是客户端 bug，**不要重试**，上报 Sentry。 前一次请求仍在处理中 → &#x60;409 idempotency_in_progress&#x60;，带 &#x60;Retry-After&#x60;，退避重试。  ⚠️ Redis 不可达时幂等是 **fail-OPEN**（照常执行，不保证幂等）， 而限流是 fail-CLOSED。这个不对称是刻意的，理由见 ADR-0003。  (optional)
     * @return [Unit]
     */
    @DELETE("me/devices/{deviceId}")
    suspend fun revokeDevice(@Header("X-Client") xClient: kotlin.String, @Path("deviceId") deviceId: java.util.UUID, @Header("Idempotency-Key") idempotencyKey: java.util.UUID? = null): Response<Unit>

    /**
     * PUT me/devices/{deviceId}/push-token
     * 更新 FCM token
     * 客户端在每次启动与每次 FCM 令牌轮换后上报。  &#x60;push_token: null&#x60; 表示**清除**（用户关掉了通知权限）—— 它与「省略该字段」不同，后者是 &#x60;400 validation_failed&#x60;。 空串同样是 &#x60;400&#x60;：想清除就发 &#x60;null&#x60;，静默地把空串当成清除 会让客户端的 bug 一直活着，而它的另一面是「明明设置了却收不到推送」。  已撤销的设备返回 &#x60;404&#x60; —— 允许它继续上报的话，一台被远程登出的设备 可以把自己留在推送目标里，而用户以为它已经被踢掉了。  ⚠️ M1 阶段服务端**只落库，不发推送**：FCM 整条链路归 T-306（M3）。 这个端点先存在，是为了 T-306 上线当天不至于一个令牌都没有。 
     * Responses:
     *  - 204: 已更新（无响应体）
     *  - 400: `validation_failed`（字段校验失败，带 `errors[]`）或 `malformed_request`（body 不是合法 JSON / 不是 JSON 对象 / 为空）。  缺失或格式错误的 `X-Client`、缺失的 `If-Match`、以及任何 offset 风格的 分页参数（`offset` / `page` / `skip` / `per_page` / `start`， `errors[].code = unsupported_parameter`）也都走这里。 
     *  - 401: `token_expired`（静默刷新后重试**一次**）或 `token_invalid`（会话已撤销 → 清空本地会话，跳登录，**不要重试**）。 
     *  - 403: `insufficient_role`（viewer 试图改卡/删卡/邀请——**同时上报 Sentry**， 正常 UI 不应产生此请求）、`not_a_member`（从本地删除该卡）、 `username_required`（跳 username 设定页）、`not_friends`。 
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
     * @param deviceId 设备 id（客户端生成，安装级唯一，§5.2）。  ⚠️ 它**不是凭据** —— 服务端校验这台设备属于当前用户，不属于则 &#x60;404&#x60; （不是 &#x60;403&#x60;，理由见 &#x60;/me/devices&#x60; 那一组的说明）。 格式非法同样返回 &#x60;404&#x60; 而不是 &#x60;422&#x60;，出于同一个理由。 
     * @param pushTokenUpdate 
     * @param idempotencyKey 幂等键（UUID），Redis 存 24h（§6.1 / ADR-0003）。所有 &#x60;POST&#x60; 支持。  同一个 key 配**相同**请求体 → 回放此前的响应，并带上 &#x60;Idempotency-Replayed: true&#x60;。 同一个 key 配**不同**请求体 → &#x60;422 idempotency_key_reused&#x60;， 这是客户端 bug，**不要重试**，上报 Sentry。 前一次请求仍在处理中 → &#x60;409 idempotency_in_progress&#x60;，带 &#x60;Retry-After&#x60;，退避重试。  ⚠️ Redis 不可达时幂等是 **fail-OPEN**（照常执行，不保证幂等）， 而限流是 fail-CLOSED。这个不对称是刻意的，理由见 ADR-0003。  (optional)
     * @return [Unit]
     */
    @PUT("me/devices/{deviceId}/push-token")
    suspend fun updatePushToken(@Header("X-Client") xClient: kotlin.String, @Path("deviceId") deviceId: java.util.UUID, @Body pushTokenUpdate: PushTokenUpdate, @Header("Idempotency-Key") idempotencyKey: java.util.UUID? = null): Response<Unit>

}
