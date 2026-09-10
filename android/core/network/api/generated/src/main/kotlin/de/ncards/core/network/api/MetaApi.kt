package de.ncards.core.network.api

import de.ncards.core.network.api.infrastructure.CollectionFormats.*
import retrofit2.http.*
import retrofit2.Response
import okhttp3.RequestBody
import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable

import de.ncards.core.network.api.model.ClientConfig
import de.ncards.core.network.api.model.Problem

interface MetaApi {
    /**
     * GET config
     * 客户端运行时配置
     * 强制升级基线、维护窗口公告与 feature flag（§3.10、§6.2、§9.2）。 **无需 Bearer**，客户端在**每次启动时**拉一次。  ⚠️ **一个过旧的客户端在这里拿到的是 &#x60;426&#x60;，不是配置。** &#x60;X-Client&#x60; 的强制 升级判定对 &#x60;/v1/_*&#x60; 一视同仁，本端点不例外。这不是缺陷：&#x60;426&#x60; 本身就是 强制升级墙的信号（T-158 的拉取逻辑正是「调本端点 → &#x60;426&#x60; → 弹墙」）， 客户端不需要先读到 &#x60;latest_client&#x60; 才知道该弹墙。&#x60;latest_client&#x60; 的消费者 是**仍在支持范围内**的客户端——它用来做「有新版可用」的软提示。  ⚠️ &#x60;maintenance.message_key&#x60; 是**本地化键，不是文案**。面向用户的德语文案 由客户端生成（§11.1）；窗口时间本身固定为每周二 03:00–04:00 CET（§9.2）， 所以文案里可以写死它，本端点不下发时间戳。  ⚠️ &#x60;feature_flags&#x60; 里**永远不会出现 §7.5 的限额数字**。那些是服务端强制的 绝对上限，客户端持有一份可能过期的副本只会制造「本地预校验说没满、服务端 说满了」这种无法由客户端判断对错的矛盾（T-111 移交笔记）。  本端点是整个 &#x60;/v1&#x60; 里**唯一可缓存**的响应（&#x60;Cache-Control: public, max-age&#x3D;60&#x60;， §7.4 的 &#x60;no-store&#x60; 在此例外：它不含任何个人数据）。响应带 &#x60;Vary: X-Client&#x60; —— 响应**体**与该头无关，但**状态码**与它强相关（过旧 → 426），少了 &#x60;Vary&#x60; 的共享缓存会把 200 喂给旧客户端，升级墙就再也不出现了。 
     * Responses:
     *  - 200: 当前配置
     *  - 400: `validation_failed`（字段校验失败，带 `errors[]`）或 `malformed_request`（body 不是合法 JSON / 不是 JSON 对象 / 为空）。  缺失或格式错误的 `X-Client`、缺失的 `If-Match`、以及任何 offset 风格的 分页参数（`offset` / `page` / `skip` / `per_page` / `start`， `errors[].code = unsupported_parameter`）也都走这里。 
     *  - 426: `client_too_old`：低于 `/v1/config` 下发的 `min_supported_client`。客户端显示强制升级墙。
     *  - 429: `rate_limited`：「我判定你超限了」，按 `Retry-After` 退避重试（§7.5）。  ⚠️ **必须**同时带 `Retry-After`（秒数）与 `X-RateLimit-Remaining`（恒为 0）。 与 `503 service_unavailable`（「我**无法判定**」——Redis 不可达且该策略 fail-closed）不是一回事，见 ADR-0005。 
     *  - 500: `internal_error`。`detail` 恒为固定文案，**绝不回显**原始异常消息 （那会泄露主机名、端口、SQL 片段）。客户端提示稍后重试并上报 Sentry。 
     *  - 503: `service_unavailable`：维护中，或限流器**无法判定**（Redis 不可达 + 该策略 fail-closed，ADR-0005）。这是服务端故障，走 §5.4.3 的 outbox 重试策略。 
     *
     * @param xClient **所有 &#x60;/v1&#x60; 请求必填**（§6.1）。缺失或格式不符即 &#x60;400 validation_failed&#x60;。 用于强制升级判定（低于 &#x60;min_supported_client&#x60; → &#x60;426 client_too_old&#x60;）与指标切分。  pattern 与后端 &#x60;App\\Shared\\Domain\\Client\\ClientVersion::PATTERN&#x60; 的**判定结果 逐例一致**，由 &#x60;backend/tests/Api/OpenApiDocumentTest&#x60; 用一张对照表断言 —— 改一处不改另一处，CI 立刻红。 （唯一的差别是后端会先 &#x60;trim()&#x60; 一次，正则表达不了这个。）  ⚠️ &#x60;/health/live&#x60; 与 &#x60;/health/ready&#x60; **不在 &#x60;/v1&#x60; 下**，因此不需要这个头—— 它们的调用方是 Docker healthcheck、Caddy 与 Ansible（§14.2）。 那两个端点刻意不出现在本契约里。 
     * @return [ClientConfig]
     */
    @GET("config")
    suspend fun getConfig(@Header("X-Client") xClient: kotlin.String): Response<ClientConfig>

}
