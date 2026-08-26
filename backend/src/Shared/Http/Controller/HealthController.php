<?php

declare(strict_types=1);

namespace App\Shared\Http\Controller;

use App\Shared\Application\Health\ReadinessProbe;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * 探活与就绪端点（§6.2、§14.2、T-003 验收标准）。
 *
 * **不在 `/v1` 下**：它们不是产品 API，不受 §13.6 的 API 演进约束，也不出现在
 * docs/api/openapi.yaml 里。调用方是 Docker healthcheck、Caddy、Ansible 部署流程
 * （§14.3 的「健康检查 → 失败自动回滚上一镜像 tag」）。
 *
 * **不暴露内部细节**：响应体只有一个 status 字段。哪个组件挂了、异常消息是什么，
 * 全部只进日志 —— 这两个端点在 staging/prod 上是公网可达的。
 *
 * 路由靠 `#[Route]` 属性自动注册：config/routes.yaml 的 `resource: routing.controllers`
 * 走 AttributeServicesLoader，它按 `routing.controller` **标签**收集，而该标签由
 * `#[Route]` 属性的 autoconfiguration 触发，与类所在目录无关。所以放在 Shared\Http\Controller
 * 下也能被扫到，不需要新增 config/routes/*.yaml。
 *
 * ⚠️ **给 T-004 的记号**：`ClientVersionListener` 要求 `X-Client` header 缺失即 400。
 * 探活调用方（Docker / Caddy / Ansible）不会带这个 header。T-004 实现该监听器时
 * **必须**把 `/health/*` 排除在外，否则本文件的两个端点会在 T-004 合入当天集体变 400，
 * 连带 compose healthcheck 与 §14.3 的部署健康检查一起失效。
 */
final class HealthController
{
    /**
     * 存活探针：进程还在、PHP 还能跑，就是活的。
     *
     * 刻意**不**检查任何外部依赖 —— liveness 失败的语义是「重启我」，
     * 而 PG 挂了重启 app 容器毫无帮助，只会在故障期间制造重启风暴。
     */
    #[Route('/health/live', name: 'health_live', methods: ['GET'])]
    public function live(): JsonResponse
    {
        return $this->json(['status' => 'ok'], Response::HTTP_OK);
    }

    /**
     * 就绪探针：外部依赖也都可用，可以接流量。
     *
     * 依赖不可用时返回 503 —— 这正是 T-003 的验收标准（PG 停掉 → 503）。
     */
    #[Route('/health/ready', name: 'health_ready', methods: ['GET'])]
    public function ready(ReadinessProbe $probe): JsonResponse
    {
        return $probe->isReady()
            ? $this->json(['status' => 'ok'], Response::HTTP_OK)
            : $this->json(['status' => 'unavailable'], Response::HTTP_SERVICE_UNAVAILABLE);
    }

    /**
     * @param array<string, string> $payload
     */
    private function json(array $payload, int $status): JsonResponse
    {
        $response = new JsonResponse($payload, $status);

        // 探活结果绝不能被任何一层缓存 —— 缓存住的 200 会让部署流程在实例已经
        // 不健康之后继续认为它健康。
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
