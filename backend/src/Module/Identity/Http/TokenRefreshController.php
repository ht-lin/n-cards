<?php

declare(strict_types=1);

namespace App\Module\Identity\Http;

use App\Module\Identity\Application\Session\RefreshTokenPayload;
use App\Module\Identity\Application\Session\RefreshTokenService;
use App\Module\Identity\Application\Session\SessionIssued;
use App\Shared\Http\Controller\AbstractApiController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * `POST /v1/auth/token/refresh` —— 用 refresh token 换一对新令牌（T-105）。
 *
 * 薄到只有解体、调用、组响应三步，理由与 {@see OtpVerifyController} 逐字相同。
 *
 * ⚠️ **无 Bearer**（契约里 `security: []`）。凭据是请求体里那个不透明的 refresh token
 * 本身，所以路由名列在 `AuthenticationListener::PUBLIC_ROUTES` 里 ——
 * 那张表与契约里带 `security: []` 的操作由 `tests/Api/AuthenticationCoverageTest` 对账。
 *
 * ============================================================================
 * ⚠️⚠️ `Idempotency-Key` 在这个端点上是**安全机制**，不是便利功能
 * ============================================================================
 * 服务端轮换完、响应在路上丢了，客户端拿旧令牌重试 —— 那会命中 §7.1 的重放检测，
 * 整条会话被撤销、用户收到一封「你的令牌可能被窃」的邮件，而实际上什么都没发生。
 *
 * `IdempotencyMiddleware` 回放此前存下的 200（**同一对**新令牌），这条误报路径
 * 才被堵上。契约因此在这个端点上列了 `Idempotency-Key`，而客户端（T-150）
 * 必须真的带上它，并用 Mutex 把并发刷新串行化（「并发 5 个 401 只触发一次刷新」）。
 *
 * 幂等作用域在这里回落到 IP（没有 Bearer 就没有 AuthContext），
 * 对一个来自单一设备的刷新请求足够 —— 见 `AuthenticatedIdempotencyScopeResolver`。
 *
 * ⚠️ 剩下的一切由 T-004 的监听器链免费提供，**不要**在这里重复实现：
 * `X-Client` 必填与 426、`X-Request-Id` 透传、429 + `Retry-After`。
 * Service 抛出的 401 / 429 / 503 一律**不要 catch** —— 尤其是 503：
 * 把「Vault 挂了」压成 401 会让全体客户端在一次故障里清空会话、退回登录页。
 */
final class TokenRefreshController extends AbstractApiController
{
    public function __construct(private readonly RefreshTokenService $service)
    {
    }

    #[Route('/v1/auth/token/refresh', name: 'auth_token_refresh', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $payload = RefreshTokenPayload::fromArray($this->decodeBody($request));

        // IP 只用于**告警日志**里的一行上下文（重放事件），不参与任何判定 ——
        // 与 OTP 那两个端点不同，这里没有 IP 维度的限流。
        // 可信度由 framework.trusted_proxies 保证（回归测试 tests/Api/TrustedProxyTest）。
        $issued = $this->service->refresh($payload, $request->getClientIp());

        return $this->json(self::body($issued));
    }

    /**
     * 与 {@see OtpVerifyController::body()} 逐字相同的形状 —— 契约里两者
     * 是**同一个** `Session` schema。
     *
     * ⚠️ 两处重复是刻意的：把它抽成一个共享的 presenter 会让「refresh 的响应体
     * 跟着 verify 一起变」，而契约演进（§13.6）恰恰要求两者能各自独立地加字段。
     * 真要收敛的话，收敛点应该是契约与 `OpenApiContractHarnessTest`，不是 PHP。
     *
     * @return array{
     *     access_token: string,
     *     expires_in: int,
     *     refresh_token: string,
     *     user: array{id: string, username: ?string, locale: string, onboarding_complete: bool, created_at: string},
     * }
     */
    private static function body(SessionIssued $issued): array
    {
        return [
            'access_token' => $issued->accessToken,
            'expires_in' => $issued->expiresInSeconds,
            'refresh_token' => $issued->refreshToken,
            'user' => [
                'id' => $issued->userId->toString(),
                'username' => $issued->username,
                'locale' => $issued->locale,
                'onboarding_complete' => $issued->onboardingComplete,
                'created_at' => $issued->userCreatedAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            ],
        ];
    }
}
