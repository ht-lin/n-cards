<?php

declare(strict_types=1);

namespace App\Module\Identity\Http;

use App\Module\Identity\Application\Otp\OtpChallengeIssued;
use App\Module\Identity\Application\Otp\OtpRequestPayload;
use App\Module\Identity\Application\Otp\RequestOtpService;
use App\Shared\Http\Controller\AbstractApiController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * `POST /v1/auth/otp/request` —— 仓库里第一个真实的产品 API 端点（T-103）。
 *
 * ============================================================================
 * 契约先行的第一次真正生效
 * ============================================================================
 * `docs/api/openapi.yaml` 里的 `requestOtp` 早在 T-007 就写好了，但在此之前
 * `/v1` 下只有 `when@test` 的探针，于是
 * `OpenApiDocumentTest::testEveryProductApiRouteIsDeclaredInTheContract()`
 * 这条 §13.1 的强制点一直空过。从本类落地起它开始咬：加一条 `/v1` 路由却忘了
 * 改契约的 PR 当场红。
 *
 * ⚠️ 路径落差是**正常**的：契约的 `servers[].url` 带 `/v1`，所以 `paths` 下写的是
 * `/auth/otp/request`，而路由必须写全 `/v1/auth/otp/request`
 * （`ApiSurface::V1_PREFIX` 决定了四个横切监听器只对 `/v1/` 生效）。
 * 校验器的 PathFinder 自动消化这个落差。
 *
 * ============================================================================
 * 这个类刻意很薄
 * ============================================================================
 * 防枚举的全部逻辑在 {@see RequestOtpService}。这里只做四件事：解请求体、
 * 校验、调用、组响应。原因是 deptrac：`Identity.Http` **看不到** `Identity.Domain`，
 * 所以任何碰实体的代码放这儿都编译不过 —— 这个约束正好把「控制器不许有业务」
 * 从约定变成了机械强制。
 *
 * 路由靠 `#[Route]` 属性自动注册（`config/routes.yaml` 只有
 * `resource: routing.controllers`，按 `routing.controller` 标签收集，与类所在目录无关，
 * 机制见 `HealthController` 的类注释）—— **不需要新增任何 routes 配置**。
 */
final class OtpRequestController extends AbstractApiController
{
    public function __construct(private readonly RequestOtpService $service)
    {
    }

    /**
     * **恒返回 202**，无论邮箱是否已注册（§3.8）。
     *
     * 这个方法里没有任何 `if` 是有意的：一旦控制器开始按「用户存不存在」分支，
     * 就迟早会有人在某条分支上多返回一个字段。存在性判断只发生在 Service 内部，
     * 而它对外只有一种返回类型。
     *
     * ⚠️ 剩下的一切都由 T-004 的监听器链免费提供，**不要**在这里重复实现：
     * `X-Client` 必填与 426（ClientVersionListener）、`Idempotency-Key` 重放
     * （IdempotencyMiddleware，2xx 存 24h）、`X-Request-Id` 透传（RequestIdListener）、
     * 以及把 `RateLimitExceeded` 渲染成 429 + `Retry-After` + `X-RateLimit-Remaining`
     * （ApiProblemExceptionListener）。Service 抛出的限流异常**不要 catch**。
     */
    #[Route('/v1/auth/otp/request', name: 'auth_otp_request', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $payload = OtpRequestPayload::fromArray($this->decodeBody($request));

        // IP 以裸字符串传进 Application：那一层的 deptrac 允许列表里没有
        // Framework.Http，拿不到 Request。可信度由 framework.trusted_proxies
        // 保证（回归测试 tests/Api/TrustedProxyTest）—— 那条没了的话，
        // §7.5 的「IP 20/h」会把全世界算作一个 IP。
        $issued = $this->service->request($payload, $request->getClientIp());

        return $this->json(self::body($issued), Response::HTTP_ACCEPTED);
    }

    /**
     * @return array{challenge_id: string, expires_at: string, resend_after_seconds: int}
     */
    private static function body(OtpChallengeIssued $issued): array
    {
        return [
            'challenge_id' => $issued->challengeId->toString(),
            // §6.1：时间一律 RFC 3339 UTC。显式转时区而不是信任 ClockInterface
            // 的 UTC 约定 —— 这是响应格式，不该依赖另一个类的注释来成立。
            'expires_at' => $issued->expiresAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            'resend_after_seconds' => $issued->resendAfterSeconds,
        ];
    }
}
