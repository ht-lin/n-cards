package de.ncards.core.network.api

import de.ncards.core.network.api.infrastructure.CollectionFormats.*
import retrofit2.http.*
import retrofit2.Response
import okhttp3.RequestBody
import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable

import de.ncards.core.network.api.model.Card
import de.ncards.core.network.api.model.CardCreate
import de.ncards.core.network.api.model.CardPage
import de.ncards.core.network.api.model.CardPlacement
import de.ncards.core.network.api.model.CardUpdate
import de.ncards.core.network.api.model.Problem

interface CardsApi {
    /**
     * POST cards
     * 创建一张卡
     * ⚠️ &#x60;id&#x60; 由**客户端生成**（UUIDv7）。这是离线优先的前提：用户在超市地下层 没网时加的卡，本地就已经有了最终 id，联网后原样上行——不需要「本地临时 id → 服务端真 id」的重映射（§5.4.3）。  因此本端点**天然幂等**：同一个 &#x60;id&#x60; 重复提交返回同一张卡。若该 &#x60;id&#x60; 已属于 **别人**，返回 &#x60;409 id_conflict&#x60;，客户端必须重新生成 id 重试。  ⚠️ **两个成功状态码，客户端必须能区分**（§5.4.3）：  | 码 | 含义 | |---|---| | &#x60;201&#x60; | 真的建了一张新卡，带 &#x60;Location&#x60; | | &#x60;200&#x60; | 该 &#x60;id&#x60; 已存在且属于你 —— **请求体被整体忽略**，返回服务端现有的那一张 |  &#x60;200&#x60; 这一路**不做任何修改**：改卡走 &#x60;PATCH&#x60;，它有 &#x60;If-Match&#x60; 保护， 而重放一个建卡请求没有。也就是说一个迟到的离线 outbox 条目不会把 用户后来改过的标题覆盖回去。  与 &#x60;Idempotency-Key&#x60;（ADR-0003）是**两层**、互不冲突：那一层按请求指纹回放 整个响应并带 &#x60;Idempotency-Replayed: true&#x60;；这一层是资源本身的天然幂等， 不带该头，且在没有 &#x60;Idempotency-Key&#x60; 时同样成立。  限额（§7.5）：每用户 500 张卡，超出 &#x60;422 limit_exceeded&#x60;。 共享给我的卡**不计入**我的额度（Q11）。 
     * Responses:
     *  - 201: 已创建
     *  - 200: 该 `id` 已存在且属于调用者 —— **没有创建任何东西**，返回服务端现有的 那张卡，请求体被整体忽略（§5.4.3 的天然幂等）。 
     *  - 400: `validation_failed`（字段校验失败，带 `errors[]`）或 `malformed_request`（body 不是合法 JSON / 不是 JSON 对象 / 为空）。  缺失或格式错误的 `X-Client`、缺失的 `If-Match`、以及任何 offset 风格的 分页参数（`offset` / `page` / `skip` / `per_page` / `start`， `errors[].code = unsupported_parameter`）也都走这里。 
     *  - 401: `token_expired`（静默刷新后重试**一次**）或 `token_invalid`（会话已撤销 → 清空本地会话，跳登录，**不要重试**）。 
     *  - 403: `insufficient_role`（viewer 试图改卡/删卡/邀请——**同时上报 Sentry**， 正常 UI 不应产生此请求）、`not_a_member`（从本地删除该卡）、 `username_required`（跳 username 设定页）、`not_friends`。  ⚠️ `username_required` 不是某几个端点的特例，而是**整个 `/v1` 面**的规则： `username IS NULL` 的用户除 `GET /me`、`POST /me/username`、 `POST /auth/logout` 外，调任何操作都得到它（§5.2，由单一 Kernel 监听器 强制）。所以每个操作的 `403` 都可能是它 —— 客户端的处置固定为 「跳 username 设定页」，不需要按端点分支。 
     *  - 409: `revision_conflict`（乐观锁失败，`current` 带服务端状态 → 走 §5.4.3 冲突解决）、 `id_conflict`（客户端生成的 id 已属于他人 → **重新生成 id 重试**）、 `already_exists`（幂等处理）、`idempotency_in_progress`（带 `Retry-After`，退避重试）、 `full_resync_required`（清库全量重同步）、 `username_taken` / `username_immutable`（见 `/me/username` 与 `PATCH /me`）。 
     *  - 413: `payload_too_large`。客户端 bug，上报 Sentry。
     *  - 415: `unsupported_media_type`：`Content-Type` 不是 `application/json`。客户端 bug，上报 Sentry。
     *  - 422: `limit_exceeded`（**系统限额**，§7.5 的第一张表）、`username_invalid`、 `idempotency_key_reused`（**不要重试**，上报 Sentry）。  ⚠️ `422 limit_exceeded` 与限流（`429`）**完全是两回事**：前者是绝对的存量 上限，重试**永远**不会成功，UI 应该显示「额度已满」而不是「稍后重试」。 
     *  - 426: `client_too_old`：低于 `/v1/config` 下发的 `min_supported_client`。客户端显示强制升级墙。
     *  - 429: `rate_limited`：「我判定你超限了」，按 `Retry-After` 退避重试（§7.5）。  ⚠️ **必须**同时带 `Retry-After`（秒数）与 `X-RateLimit-Remaining`（恒为 0）。 与 `503 service_unavailable`（「我**无法判定**」——Redis 不可达且该策略 fail-closed）不是一回事，见 ADR-0005。 
     *  - 500: `internal_error`。`detail` 恒为固定文案，**绝不回显**原始异常消息 （那会泄露主机名、端口、SQL 片段）。客户端提示稍后重试并上报 Sentry。 
     *  - 503: `service_unavailable`：维护中，或限流器**无法判定**（Redis 不可达 + 该策略 fail-closed，ADR-0005）。这是服务端故障，走 §5.4.3 的 outbox 重试策略。 
     *
     * @param xClient **所有 &#x60;/v1&#x60; 请求必填**（§6.1）。缺失或格式不符即 &#x60;400 validation_failed&#x60;。 用于强制升级判定（低于 &#x60;min_supported_client&#x60; → &#x60;426 client_too_old&#x60;）与指标切分。  pattern 与后端 &#x60;App\\Shared\\Domain\\Client\\ClientVersion::PATTERN&#x60; 的**判定结果 逐例一致**，由 &#x60;backend/tests/Api/OpenApiDocumentTest&#x60; 用一张对照表断言 —— 改一处不改另一处，CI 立刻红。 （唯一的差别是后端会先 &#x60;trim()&#x60; 一次，正则表达不了这个。）  ⚠️ &#x60;/health/live&#x60; 与 &#x60;/health/ready&#x60; **不在 &#x60;/v1&#x60; 下**，因此不需要这个头—— 它们的调用方是 Docker healthcheck、Caddy 与 Ansible（§14.2）。 那两个端点刻意不出现在本契约里。 
     * @param cardCreate 
     * @param idempotencyKey 幂等键（UUID），Redis 存 24h（§6.1 / ADR-0003）。所有 &#x60;POST&#x60; 支持。  同一个 key 配**相同**请求体 → 回放此前的响应，并带上 &#x60;Idempotency-Replayed: true&#x60;。 同一个 key 配**不同**请求体 → &#x60;422 idempotency_key_reused&#x60;， 这是客户端 bug，**不要重试**，上报 Sentry。 前一次请求仍在处理中 → &#x60;409 idempotency_in_progress&#x60;，带 &#x60;Retry-After&#x60;，退避重试。  ⚠️ Redis 不可达时幂等是 **fail-OPEN**（照常执行，不保证幂等）， 而限流是 fail-CLOSED。这个不对称是刻意的，理由见 ADR-0003。  (optional)
     * @return [Card]
     */
    @POST("cards")
    suspend fun createCard(@Header("X-Client") xClient: kotlin.String, @Body cardCreate: CardCreate, @Header("Idempotency-Key") idempotencyKey: java.util.UUID? = null): Response<Card>

    /**
     * DELETE cards/{cardId}
     * 删除一张卡
     * **仅 owner**。软删（写 &#x60;deleted_at&#x60;），90 天后硬删。会使**全部 viewer** 失去该卡——他们的 &#x60;/sync&#x60; 会收到墓碑记录（§5.2）。  ⚠️ 墓碑的 audience 取**删除前**的成员快照，否则被删的成员永远收不到通知。 
     * Responses:
     *  - 204: 已删除（无响应体）
     *  - 400: `validation_failed`（字段校验失败，带 `errors[]`）或 `malformed_request`（body 不是合法 JSON / 不是 JSON 对象 / 为空）。  缺失或格式错误的 `X-Client`、缺失的 `If-Match`、以及任何 offset 风格的 分页参数（`offset` / `page` / `skip` / `per_page` / `start`， `errors[].code = unsupported_parameter`）也都走这里。 
     *  - 401: `token_expired`（静默刷新后重试**一次**）或 `token_invalid`（会话已撤销 → 清空本地会话，跳登录，**不要重试**）。 
     *  - 403: `insufficient_role`（viewer 试图改卡/删卡/邀请——**同时上报 Sentry**， 正常 UI 不应产生此请求）、`not_a_member`（从本地删除该卡）、 `username_required`（跳 username 设定页）、`not_friends`。  ⚠️ `username_required` 不是某几个端点的特例，而是**整个 `/v1` 面**的规则： `username IS NULL` 的用户除 `GET /me`、`POST /me/username`、 `POST /auth/logout` 外，调任何操作都得到它（§5.2，由单一 Kernel 监听器 强制）。所以每个操作的 `403` 都可能是它 —— 客户端的处置固定为 「跳 username 设定页」，不需要按端点分支。 
     *  - 404: `not_found`。  ⚠️ 对**非成员**访问一张存在的卡，服务端返回的是 `403 not_a_member` 还是 `404 not_found`，取决于该资源是否属于「存在性本身即信息」的一类。 卡走 `403`（成员关系是明确的授权概念）；含 username 的查找走 `404`。 
     *  - 426: `client_too_old`：低于 `/v1/config` 下发的 `min_supported_client`。客户端显示强制升级墙。
     *  - 429: `rate_limited`：「我判定你超限了」，按 `Retry-After` 退避重试（§7.5）。  ⚠️ **必须**同时带 `Retry-After`（秒数）与 `X-RateLimit-Remaining`（恒为 0）。 与 `503 service_unavailable`（「我**无法判定**」——Redis 不可达且该策略 fail-closed）不是一回事，见 ADR-0005。 
     *  - 500: `internal_error`。`detail` 恒为固定文案，**绝不回显**原始异常消息 （那会泄露主机名、端口、SQL 片段）。客户端提示稍后重试并上报 Sentry。 
     *  - 503: `service_unavailable`：维护中，或限流器**无法判定**（Redis 不可达 + 该策略 fail-closed，ADR-0005）。这是服务端故障，走 §5.4.3 的 outbox 重试策略。 
     *
     * @param xClient **所有 &#x60;/v1&#x60; 请求必填**（§6.1）。缺失或格式不符即 &#x60;400 validation_failed&#x60;。 用于强制升级判定（低于 &#x60;min_supported_client&#x60; → &#x60;426 client_too_old&#x60;）与指标切分。  pattern 与后端 &#x60;App\\Shared\\Domain\\Client\\ClientVersion::PATTERN&#x60; 的**判定结果 逐例一致**，由 &#x60;backend/tests/Api/OpenApiDocumentTest&#x60; 用一张对照表断言 —— 改一处不改另一处，CI 立刻红。 （唯一的差别是后端会先 &#x60;trim()&#x60; 一次，正则表达不了这个。）  ⚠️ &#x60;/health/live&#x60; 与 &#x60;/health/ready&#x60; **不在 &#x60;/v1&#x60; 下**，因此不需要这个头—— 它们的调用方是 Docker healthcheck、Caddy 与 Ansible（§14.2）。 那两个端点刻意不出现在本契约里。 
     * @param cardId 卡 id（UUIDv7，客户端生成）
     * @return [Unit]
     */
    @DELETE("cards/{cardId}")
    suspend fun deleteCard(@Header("X-Client") xClient: kotlin.String, @Path("cardId") cardId: java.util.UUID): Response<Unit>

    /**
     * GET cards/{cardId}
     * 取单张卡
     * owner 与 viewer 都能读。非成员 → &#x60;403 not_a_member&#x60;，客户端应据此 **从本地删除该卡**（说明共享已被撤销，§6.1）。  响应随调用者角色裁剪：viewer 拿到的 &#x60;Card&#x60; 没有 &#x60;member_count&#x60;（§5.2 / C11）。 
     * Responses:
     *  - 200: 卡
     *  - 400: `validation_failed`（字段校验失败，带 `errors[]`）或 `malformed_request`（body 不是合法 JSON / 不是 JSON 对象 / 为空）。  缺失或格式错误的 `X-Client`、缺失的 `If-Match`、以及任何 offset 风格的 分页参数（`offset` / `page` / `skip` / `per_page` / `start`， `errors[].code = unsupported_parameter`）也都走这里。 
     *  - 401: `token_expired`（静默刷新后重试**一次**）或 `token_invalid`（会话已撤销 → 清空本地会话，跳登录，**不要重试**）。 
     *  - 403: `insufficient_role`（viewer 试图改卡/删卡/邀请——**同时上报 Sentry**， 正常 UI 不应产生此请求）、`not_a_member`（从本地删除该卡）、 `username_required`（跳 username 设定页）、`not_friends`。  ⚠️ `username_required` 不是某几个端点的特例，而是**整个 `/v1` 面**的规则： `username IS NULL` 的用户除 `GET /me`、`POST /me/username`、 `POST /auth/logout` 外，调任何操作都得到它（§5.2，由单一 Kernel 监听器 强制）。所以每个操作的 `403` 都可能是它 —— 客户端的处置固定为 「跳 username 设定页」，不需要按端点分支。 
     *  - 404: `not_found`。  ⚠️ 对**非成员**访问一张存在的卡，服务端返回的是 `403 not_a_member` 还是 `404 not_found`，取决于该资源是否属于「存在性本身即信息」的一类。 卡走 `403`（成员关系是明确的授权概念）；含 username 的查找走 `404`。 
     *  - 426: `client_too_old`：低于 `/v1/config` 下发的 `min_supported_client`。客户端显示强制升级墙。
     *  - 429: `rate_limited`：「我判定你超限了」，按 `Retry-After` 退避重试（§7.5）。  ⚠️ **必须**同时带 `Retry-After`（秒数）与 `X-RateLimit-Remaining`（恒为 0）。 与 `503 service_unavailable`（「我**无法判定**」——Redis 不可达且该策略 fail-closed）不是一回事，见 ADR-0005。 
     *  - 500: `internal_error`。`detail` 恒为固定文案，**绝不回显**原始异常消息 （那会泄露主机名、端口、SQL 片段）。客户端提示稍后重试并上报 Sentry。 
     *  - 503: `service_unavailable`：维护中，或限流器**无法判定**（Redis 不可达 + 该策略 fail-closed，ADR-0005）。这是服务端故障，走 §5.4.3 的 outbox 重试策略。 
     *
     * @param xClient **所有 &#x60;/v1&#x60; 请求必填**（§6.1）。缺失或格式不符即 &#x60;400 validation_failed&#x60;。 用于强制升级判定（低于 &#x60;min_supported_client&#x60; → &#x60;426 client_too_old&#x60;）与指标切分。  pattern 与后端 &#x60;App\\Shared\\Domain\\Client\\ClientVersion::PATTERN&#x60; 的**判定结果 逐例一致**，由 &#x60;backend/tests/Api/OpenApiDocumentTest&#x60; 用一张对照表断言 —— 改一处不改另一处，CI 立刻红。 （唯一的差别是后端会先 &#x60;trim()&#x60; 一次，正则表达不了这个。）  ⚠️ &#x60;/health/live&#x60; 与 &#x60;/health/ready&#x60; **不在 &#x60;/v1&#x60; 下**，因此不需要这个头—— 它们的调用方是 Docker healthcheck、Caddy 与 Ansible（§14.2）。 那两个端点刻意不出现在本契约里。 
     * @param cardId 卡 id（UUIDv7，客户端生成）
     * @return [Card]
     */
    @GET("cards/{cardId}")
    suspend fun getCard(@Header("X-Client") xClient: kotlin.String, @Path("cardId") cardId: java.util.UUID): Response<Card>

    /**
     * GET cards
     * 列出我能看到的全部卡
     * 首次登录用；日常增量走 &#x60;GET /sync&#x60;（T-202）。包含我 own 的卡与别人共享给我的卡。 游标分页，**没有** offset。 
     * Responses:
     *  - 200: 一页卡
     *  - 400: `validation_failed`（字段校验失败，带 `errors[]`）或 `malformed_request`（body 不是合法 JSON / 不是 JSON 对象 / 为空）。  缺失或格式错误的 `X-Client`、缺失的 `If-Match`、以及任何 offset 风格的 分页参数（`offset` / `page` / `skip` / `per_page` / `start`， `errors[].code = unsupported_parameter`）也都走这里。 
     *  - 401: `token_expired`（静默刷新后重试**一次**）或 `token_invalid`（会话已撤销 → 清空本地会话，跳登录，**不要重试**）。 
     *  - 403: `insufficient_role`（viewer 试图改卡/删卡/邀请——**同时上报 Sentry**， 正常 UI 不应产生此请求）、`not_a_member`（从本地删除该卡）、 `username_required`（跳 username 设定页）、`not_friends`。  ⚠️ `username_required` 不是某几个端点的特例，而是**整个 `/v1` 面**的规则： `username IS NULL` 的用户除 `GET /me`、`POST /me/username`、 `POST /auth/logout` 外，调任何操作都得到它（§5.2，由单一 Kernel 监听器 强制）。所以每个操作的 `403` 都可能是它 —— 客户端的处置固定为 「跳 username 设定页」，不需要按端点分支。 
     *  - 426: `client_too_old`：低于 `/v1/config` 下发的 `min_supported_client`。客户端显示强制升级墙。
     *  - 429: `rate_limited`：「我判定你超限了」，按 `Retry-After` 退避重试（§7.5）。  ⚠️ **必须**同时带 `Retry-After`（秒数）与 `X-RateLimit-Remaining`（恒为 0）。 与 `503 service_unavailable`（「我**无法判定**」——Redis 不可达且该策略 fail-closed）不是一回事，见 ADR-0005。 
     *  - 500: `internal_error`。`detail` 恒为固定文案，**绝不回显**原始异常消息 （那会泄露主机名、端口、SQL 片段）。客户端提示稍后重试并上报 Sentry。 
     *  - 503: `service_unavailable`：维护中，或限流器**无法判定**（Redis 不可达 + 该策略 fail-closed，ADR-0005）。这是服务端故障，走 §5.4.3 的 outbox 重试策略。 
     *
     * @param xClient **所有 &#x60;/v1&#x60; 请求必填**（§6.1）。缺失或格式不符即 &#x60;400 validation_failed&#x60;。 用于强制升级判定（低于 &#x60;min_supported_client&#x60; → &#x60;426 client_too_old&#x60;）与指标切分。  pattern 与后端 &#x60;App\\Shared\\Domain\\Client\\ClientVersion::PATTERN&#x60; 的**判定结果 逐例一致**，由 &#x60;backend/tests/Api/OpenApiDocumentTest&#x60; 用一张对照表断言 —— 改一处不改另一处，CI 立刻红。 （唯一的差别是后端会先 &#x60;trim()&#x60; 一次，正则表达不了这个。）  ⚠️ &#x60;/health/live&#x60; 与 &#x60;/health/ready&#x60; **不在 &#x60;/v1&#x60; 下**，因此不需要这个头—— 它们的调用方是 Docker healthcheck、Caddy 与 Ansible（§14.2）。 那两个端点刻意不出现在本契约里。 
     * @param cursor 不透明游标，取自上一页响应的 &#x60;next_cursor&#x60;。**客户端不得解析它的内容。** 省略即取第一页。无法解码 → &#x60;400 validation_failed&#x60; （&#x60;/sync&#x60; 例外，那里是 &#x60;409 full_resync_required&#x60;，见 §5.4.1）。  (optional)
     * @param limit 每页条数。默认 50，**上限 200**。  超过上限**静默夹取**到 200，不报错——这是合理的请求，而且夹取意味着将来 抬高上限仍然向后兼容（§13.6 允许放宽校验）。客户端总能靠 &#x60;has_more&#x60; 察觉。 但 &#x60;limit&#x3D;abc&#x60; / &#x60;limit&#x3D;0&#x60; / &#x60;limit&#x3D;-1&#x60; 是客户端 bug，一律 &#x60;400&#x60;。  与后端 &#x60;App\\Shared\\Http\\Pagination\\CursorPaginator&#x60; 的 &#x60;DEFAULT_LIMIT&#x60; / &#x60;MAX_LIMIT&#x60; 一致。  (optional, default to 50)
     * @return [CardPage]
     */
    @GET("cards")
    suspend fun listCards(@Header("X-Client") xClient: kotlin.String, @Query("cursor") cursor: kotlin.String? = null, @Query("limit") limit: kotlin.Int? = 50): Response<CardPage>

    /**
     * PATCH cards/{cardId}
     * 修改一张卡（乐观锁）
     * **仅 owner**。viewer 调用返回 &#x60;403 insufficient_role&#x60;，且客户端应同时上报 Sentry——正常 UI 不应产生这个请求（§6.1）。  &#x60;If-Match&#x60; 带当前 &#x60;revision&#x60;，不匹配返回 &#x60;409 revision_conflict&#x60;， problem body 的 &#x60;current&#x60; 成员携带服务端当前状态，客户端据此走 §5.4.3 的 冲突解决。  &#x60;sort_order&#x60; / &#x60;is_pinned&#x60; **不在这里改**——它们是每成员私有的，走 &#x60;PUT /cards/{cardId}/placement&#x60;。 
     * Responses:
     *  - 200: 已更新，`revision` 已递增
     *  - 400: `validation_failed`（字段校验失败，带 `errors[]`）或 `malformed_request`（body 不是合法 JSON / 不是 JSON 对象 / 为空）。  缺失或格式错误的 `X-Client`、缺失的 `If-Match`、以及任何 offset 风格的 分页参数（`offset` / `page` / `skip` / `per_page` / `start`， `errors[].code = unsupported_parameter`）也都走这里。 
     *  - 401: `token_expired`（静默刷新后重试**一次**）或 `token_invalid`（会话已撤销 → 清空本地会话，跳登录，**不要重试**）。 
     *  - 403: `insufficient_role`（viewer 试图改卡/删卡/邀请——**同时上报 Sentry**， 正常 UI 不应产生此请求）、`not_a_member`（从本地删除该卡）、 `username_required`（跳 username 设定页）、`not_friends`。  ⚠️ `username_required` 不是某几个端点的特例，而是**整个 `/v1` 面**的规则： `username IS NULL` 的用户除 `GET /me`、`POST /me/username`、 `POST /auth/logout` 外，调任何操作都得到它（§5.2，由单一 Kernel 监听器 强制）。所以每个操作的 `403` 都可能是它 —— 客户端的处置固定为 「跳 username 设定页」，不需要按端点分支。 
     *  - 404: `not_found`。  ⚠️ 对**非成员**访问一张存在的卡，服务端返回的是 `403 not_a_member` 还是 `404 not_found`，取决于该资源是否属于「存在性本身即信息」的一类。 卡走 `403`（成员关系是明确的授权概念）；含 username 的查找走 `404`。 
     *  - 409: `revision_conflict`（乐观锁失败，`current` 带服务端状态 → 走 §5.4.3 冲突解决）、 `id_conflict`（客户端生成的 id 已属于他人 → **重新生成 id 重试**）、 `already_exists`（幂等处理）、`idempotency_in_progress`（带 `Retry-After`，退避重试）、 `full_resync_required`（清库全量重同步）、 `username_taken` / `username_immutable`（见 `/me/username` 与 `PATCH /me`）。 
     *  - 413: `payload_too_large`。客户端 bug，上报 Sentry。
     *  - 415: `unsupported_media_type`：`Content-Type` 不是 `application/json`。客户端 bug，上报 Sentry。
     *  - 422: `limit_exceeded`（**系统限额**，§7.5 的第一张表）、`username_invalid`、 `idempotency_key_reused`（**不要重试**，上报 Sentry）。  ⚠️ `422 limit_exceeded` 与限流（`429`）**完全是两回事**：前者是绝对的存量 上限，重试**永远**不会成功，UI 应该显示「额度已满」而不是「稍后重试」。 
     *  - 426: `client_too_old`：低于 `/v1/config` 下发的 `min_supported_client`。客户端显示强制升级墙。
     *  - 429: `rate_limited`：「我判定你超限了」，按 `Retry-After` 退避重试（§7.5）。  ⚠️ **必须**同时带 `Retry-After`（秒数）与 `X-RateLimit-Remaining`（恒为 0）。 与 `503 service_unavailable`（「我**无法判定**」——Redis 不可达且该策略 fail-closed）不是一回事，见 ADR-0005。 
     *  - 500: `internal_error`。`detail` 恒为固定文案，**绝不回显**原始异常消息 （那会泄露主机名、端口、SQL 片段）。客户端提示稍后重试并上报 Sentry。 
     *  - 503: `service_unavailable`：维护中，或限流器**无法判定**（Redis 不可达 + 该策略 fail-closed，ADR-0005）。这是服务端故障，走 §5.4.3 的 outbox 重试策略。 
     *
     * @param xClient **所有 &#x60;/v1&#x60; 请求必填**（§6.1）。缺失或格式不符即 &#x60;400 validation_failed&#x60;。 用于强制升级判定（低于 &#x60;min_supported_client&#x60; → &#x60;426 client_too_old&#x60;）与指标切分。  pattern 与后端 &#x60;App\\Shared\\Domain\\Client\\ClientVersion::PATTERN&#x60; 的**判定结果 逐例一致**，由 &#x60;backend/tests/Api/OpenApiDocumentTest&#x60; 用一张对照表断言 —— 改一处不改另一处，CI 立刻红。 （唯一的差别是后端会先 &#x60;trim()&#x60; 一次，正则表达不了这个。）  ⚠️ &#x60;/health/live&#x60; 与 &#x60;/health/ready&#x60; **不在 &#x60;/v1&#x60; 下**，因此不需要这个头—— 它们的调用方是 Docker healthcheck、Caddy 与 Ansible（§14.2）。 那两个端点刻意不出现在本契约里。 
     * @param cardId 卡 id（UUIDv7，客户端生成）
     * @param ifMatch 当前 &#x60;revision&#x60; 的字符串形式，带引号，如 &#x60;\&quot;7\&quot;&#x60;（§17.2）。 不匹配 → &#x60;409 revision_conflict&#x60;，problem body 的 &#x60;current&#x60; 带服务端当前状态。 
     * @param cardUpdate 
     * @return [Card]
     */
    @PATCH("cards/{cardId}")
    suspend fun updateCard(@Header("X-Client") xClient: kotlin.String, @Path("cardId") cardId: java.util.UUID, @Header("If-Match") ifMatch: kotlin.String, @Body cardUpdate: CardUpdate): Response<Card>

    /**
     * PUT cards/{cardId}/placement
     * 设置我自己对该卡的排序与置顶
     * &#x60;sort_order&#x60; / &#x60;is_pinned&#x60; 存在 &#x60;card_members&#x60; 上，是**每成员私有**的 （§5.2）——同一张共享卡，Anna 置顶、Bob 不置顶，互不影响。  因此本端点： - **owner 与 viewer 都能调用**。这是 viewer 唯一的上行写入端点。 - **不走 revision 锁**，不需要 &#x60;If-Match&#x60;，也不会递增卡的 &#x60;revision&#x60;——   它改的根本不是卡本身。 
     * Responses:
     *  - 200: 已更新（返回的是**调用者视角**的卡）
     *  - 400: `validation_failed`（字段校验失败，带 `errors[]`）或 `malformed_request`（body 不是合法 JSON / 不是 JSON 对象 / 为空）。  缺失或格式错误的 `X-Client`、缺失的 `If-Match`、以及任何 offset 风格的 分页参数（`offset` / `page` / `skip` / `per_page` / `start`， `errors[].code = unsupported_parameter`）也都走这里。 
     *  - 401: `token_expired`（静默刷新后重试**一次**）或 `token_invalid`（会话已撤销 → 清空本地会话，跳登录，**不要重试**）。 
     *  - 403: `insufficient_role`（viewer 试图改卡/删卡/邀请——**同时上报 Sentry**， 正常 UI 不应产生此请求）、`not_a_member`（从本地删除该卡）、 `username_required`（跳 username 设定页）、`not_friends`。  ⚠️ `username_required` 不是某几个端点的特例，而是**整个 `/v1` 面**的规则： `username IS NULL` 的用户除 `GET /me`、`POST /me/username`、 `POST /auth/logout` 外，调任何操作都得到它（§5.2，由单一 Kernel 监听器 强制）。所以每个操作的 `403` 都可能是它 —— 客户端的处置固定为 「跳 username 设定页」，不需要按端点分支。 
     *  - 404: `not_found`。  ⚠️ 对**非成员**访问一张存在的卡，服务端返回的是 `403 not_a_member` 还是 `404 not_found`，取决于该资源是否属于「存在性本身即信息」的一类。 卡走 `403`（成员关系是明确的授权概念）；含 username 的查找走 `404`。 
     *  - 413: `payload_too_large`。客户端 bug，上报 Sentry。
     *  - 415: `unsupported_media_type`：`Content-Type` 不是 `application/json`。客户端 bug，上报 Sentry。
     *  - 422: `limit_exceeded`（**系统限额**，§7.5 的第一张表）、`username_invalid`、 `idempotency_key_reused`（**不要重试**，上报 Sentry）。  ⚠️ `422 limit_exceeded` 与限流（`429`）**完全是两回事**：前者是绝对的存量 上限，重试**永远**不会成功，UI 应该显示「额度已满」而不是「稍后重试」。 
     *  - 426: `client_too_old`：低于 `/v1/config` 下发的 `min_supported_client`。客户端显示强制升级墙。
     *  - 429: `rate_limited`：「我判定你超限了」，按 `Retry-After` 退避重试（§7.5）。  ⚠️ **必须**同时带 `Retry-After`（秒数）与 `X-RateLimit-Remaining`（恒为 0）。 与 `503 service_unavailable`（「我**无法判定**」——Redis 不可达且该策略 fail-closed）不是一回事，见 ADR-0005。 
     *  - 500: `internal_error`。`detail` 恒为固定文案，**绝不回显**原始异常消息 （那会泄露主机名、端口、SQL 片段）。客户端提示稍后重试并上报 Sentry。 
     *  - 503: `service_unavailable`：维护中，或限流器**无法判定**（Redis 不可达 + 该策略 fail-closed，ADR-0005）。这是服务端故障，走 §5.4.3 的 outbox 重试策略。 
     *
     * @param xClient **所有 &#x60;/v1&#x60; 请求必填**（§6.1）。缺失或格式不符即 &#x60;400 validation_failed&#x60;。 用于强制升级判定（低于 &#x60;min_supported_client&#x60; → &#x60;426 client_too_old&#x60;）与指标切分。  pattern 与后端 &#x60;App\\Shared\\Domain\\Client\\ClientVersion::PATTERN&#x60; 的**判定结果 逐例一致**，由 &#x60;backend/tests/Api/OpenApiDocumentTest&#x60; 用一张对照表断言 —— 改一处不改另一处，CI 立刻红。 （唯一的差别是后端会先 &#x60;trim()&#x60; 一次，正则表达不了这个。）  ⚠️ &#x60;/health/live&#x60; 与 &#x60;/health/ready&#x60; **不在 &#x60;/v1&#x60; 下**，因此不需要这个头—— 它们的调用方是 Docker healthcheck、Caddy 与 Ansible（§14.2）。 那两个端点刻意不出现在本契约里。 
     * @param cardId 卡 id（UUIDv7，客户端生成）
     * @param cardPlacement 
     * @return [Card]
     */
    @PUT("cards/{cardId}/placement")
    suspend fun updateCardPlacement(@Header("X-Client") xClient: kotlin.String, @Path("cardId") cardId: java.util.UUID, @Body cardPlacement: CardPlacement): Response<Card>

}
