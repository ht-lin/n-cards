<?php

declare(strict_types=1);

namespace App\Shared\Http\Controller;

use App\Shared\Application\Config\ClientConfig;
use App\Shared\Application\Config\ClientConfigProvider;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * `GET /v1/config` —— 强制升级基线、维护公告与 feature flag（§3.10、§6.2、§9.2，T-112）。
 *
 * ⚠️ `config/services.yaml` 与 `tests/Fixture/Http/ProbeApiController` 的注释里
 * 都写着「第一个真实端点 `/v1/config` 属 T-112」。那是 T-004 当时的判断，**已经
 * 过时**：T-103/104（auth）、T-108（me）、T-109/110（cards）都先落地了。
 * 本类是 `Shared\Http` 下的第一个产品端点，不是第一个 `/v1` 端点。
 *
 * ============================================================================
 * ⚠️⚠️ 一个过旧的客户端在这里拿到的是 426，不是配置 —— 这是对的
 * ============================================================================
 * {@see \App\Shared\Infrastructure\Http\ClientVersionListener} priority 40、早于路由，
 * 按 {@see \App\Shared\Domain\Http\ApiSurface::isProductApiPath()} 判断，对 `/v1/*`
 * 一视同仁。于是「最需要这份配置的那个客户端恰好读不到它」。
 *
 * 看起来像缺陷，其实是 T-158 任务卡逐字描述的机制：
 *
 * > 启动时拉 `GET /v1/config`；`426 client_too_old` → 全屏强制升级墙。
 *
 * **426 本身就是信号。** 客户端拿到它直接弹墙、跳商店，不需要先知道该升到哪个版本。
 * `latest_client` 的消费者是**仍在支持范围内**的客户端 —— 它做的是「有新版可用」
 * 这种软提示。
 *
 * 所以这里**刻意不做**两件事：
 *   - 不给本路由开 `ClientVersionListener` 的豁免口子。开了之后，「所有 `/v1`
 *     一律受强制升级约束」这条规则就有了第一个例外，而下一个例外会更容易加；
 *   - 不往 426 的 problem body 上挂版本号扩展成员。§6.1 的 problem 形状是全站共用的，
 *     为一个端点加字段会让 `ProblemDetailsContractTest` 对「problem 长什么样」
 *     这个问题失去判别力。
 *
 * ============================================================================
 * 为什么在 Shared\Http\Controller 而不是某个模块
 * ============================================================================
 * 先例是 {@see HealthController}。这个端点不属于任何限界上下文 —— 它下发的是
 * 部署配置，不是领域数据。deptrac 里 `Shared.Http` 的允许列表是
 * `[Shared.Domain, Shared.Application, Framework.Core, Framework.Http]`，
 * 而本端点只需要 `Shared.Application` 的一个 provider，零配置改动。
 *
 * 与 `HealthController` 的区别是本类**继承** {@see AbstractApiController}：
 * 那个基类是 `/v1` 专用的（它的 `clientVersion()` / `authContext()` 在非 `/v1`
 * 上会抛 `LogicException`），而 `/health/*` 不在 `/v1` 下，所以那个类是裸的。
 *
 * ============================================================================
 * 免鉴权：要同时改两处
 * ============================================================================
 * `config_get` 在 {@see \App\Shared\Infrastructure\Http\AuthenticationListener::PUBLIC_ROUTES}
 * 里，契约侧对应的是操作上的 `security: []`。两者由 `tests/Api/AuthenticationCoverageTest`
 * 逐条对账。
 *
 * 不需要动 `OnboardingListener::EXEMPT_ROUTES`：那个拦截器只在请求带着
 * `AuthContext` 时才判定，而公开路由永远不带 —— 结构上就在它之外。
 */
final class ConfigController extends AbstractApiController
{
    public function __construct(private readonly ClientConfigProvider $provider)
    {
    }

    #[Route('/v1/config', name: 'config_get', methods: ['GET'])]
    public function get(): JsonResponse
    {
        return $this->json(
            self::body($this->provider->current()),
            headers: [
                // ⚠️ 本仓库唯一一处允许缓存的 `/v1` 响应。§7.4 的 `no-store` 默认值
                // 是因为「每个 /v1 响应都是用户特定的」，而这一个不是：它不含任何
                // 个人数据，对所有调用者同值，而客户端**每次启动**都会拉一次。
                // `AbstractApiController::json()` 的 $noStore 注释里点名的例外就是它。
                'Cache-Control' => 'public, max-age=60',

                // ⚠️⚠️ 承重，不是装饰。响应**体**与 `X-Client` 无关，但**状态码**
                // 与它强相关（过旧 → 426，见类注释）。少了这一行，一个只按 URL
                // 做键的共享缓存会把缓存下来的 200 喂给旧客户端 —— 强制升级墙
                // 就再也不出现了，而且没有任何错误可查。
                //
                // 426 那一侧不用操心：ApiProblemFactory 给所有 problem 响应都打了
                // `no-store`，它们进不了任何缓存。
                'Vary' => 'X-Client',
            ],
            noStore: false,
        );
    }

    /**
     * 契约 `components/schemas/ClientConfig` 的线上形状。
     *
     * @return array<string, mixed>
     */
    private static function body(ClientConfig $config): array
    {
        return [
            'min_supported_client' => (string) $config->minSupportedClient,
            'latest_client' => (string) $config->latestClient,
            'maintenance' => [
                'active' => $config->maintenance->active,
                'message_key' => $config->maintenance->messageKey?->value,
                'retry_after' => $config->maintenance->retryAfter,
            ],
            // ⚠️ `(object) []` 而不是 `[]`：PHP 的空数组会被 json_encode 编成 `[]`，
            // 而契约说这里是一个对象。客户端（Kotlin，`Map<String, …>`）对着 `[]`
            // 会反序列化失败 —— 而这个分支在服务端这侧完全没有症状。
            // `ConfigEndpointTest` 断言的是**原始 JSON 子串**，因为 json_decode
            // 之后 `[]` 与 `{}` 在 PHP 里分不出来。
            //
            // M1 恒为空：没有任何 feature flag 的消费者。§13.6 允许后续新增字段。
            // ⚠️ 这里**永远不要**下发 §7.5 的限额数字（T-111 移交笔记）：那是服务端
            // 强制的绝对上限，客户端持有一份可能过期的副本只会制造「本地预校验说
            // 没满、服务端说满了」这种它无法判断对错的矛盾。
            'feature_flags' => (object) [],
        ];
    }
}
