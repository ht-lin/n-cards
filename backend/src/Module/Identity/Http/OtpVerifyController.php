<?php

declare(strict_types=1);

namespace App\Module\Identity\Http;

use App\Module\Identity\Application\Otp\OtpVerificationPayload;
use App\Module\Identity\Application\Otp\VerifyOtpService;
use App\Module\Identity\Application\Session\SessionIssued;
use App\Shared\Http\Controller\AbstractApiController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * `POST /v1/auth/otp/verify` —— 用 6 位码换令牌对，首次成功即注册（T-104）。
 *
 * 薄到只有解体、调用、组响应三步，理由与 {@see OtpRequestController} 逐字相同
 * （deptrac 里 `Identity.Http` 看不到 `Identity.Domain`，碰实体的代码放这儿编译不过）。
 *
 * ⚠️ 剩下的一切由 T-004 的监听器链免费提供，**不要**在这里重复实现：
 * `X-Client` 必填与 426、`Idempotency-Key` 重放（2xx 存 24h）、`X-Request-Id` 透传、
 * 以及把 `RateLimitExceeded` 渲染成 429 + `Retry-After` + `X-RateLimit-Remaining`。
 * Service 抛出的 401 / 409 / 429 一律**不要 catch**。
 *
 * ============================================================================
 * ⚠️ 幂等重放会把同一对令牌再发一次，这是对的
 * ============================================================================
 * 带同一个 `Idempotency-Key` 重试，`IdempotencyMiddleware` 会回放此前存下的 200，
 * 于是客户端拿到**同一个** access token 与 refresh token。
 *
 * 看起来像「令牌泄露面变大了」，其实相反：没有它的话，一次网络抖动导致的重试
 * 会创建第二条 session、第二个 refresh token，而客户端只会保存最后拿到的那个 ——
 * 前一条 session 就成了一条谁也管不着、要活满 90 天的孤儿（§7.1 的滑动有效期）。
 * 设备管理页上还会多出一行来路不明的记录。
 *
 * 真正保护令牌的是响应上的 `Cache-Control: no-store`（`AbstractApiController::json()`
 * 默认加）与 §7.3 的客户端存储要求，不是「每次重试都换一套新的」。
 */
final class OtpVerifyController extends AbstractApiController
{
    public function __construct(private readonly VerifyOtpService $service)
    {
    }

    #[Route('/v1/auth/otp/verify', name: 'auth_otp_verify', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $payload = OtpVerificationPayload::fromArray($this->decodeBody($request));

        // IP 以裸字符串传进 Application，可信度由 framework.trusted_proxies 保证
        // （回归测试 tests/Api/TrustedProxyTest）—— 那条没了的话，§7.5 的
        // 「IP 60/h」会把全世界算作一个 IP，等于对登录端点的拒绝服务。
        $issued = $this->service->verify($payload, $request->getClientIp());

        return $this->json(self::body($issued));
    }

    /**
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
                // §5.2：注册未完成时为 null。契约里 User.username 是
                // `anyOf: [Username, "null"]`，**不是**省略该键 ——
                // 省略会让 Android 侧的 kotlinx.serialization 走默认值分支。
                'username' => $issued->username,
                'locale' => $issued->locale,
                'onboarding_complete' => $issued->onboardingComplete,
                // §6.1：时间一律 RFC 3339 UTC。显式转时区而不是信任 ClockInterface
                // 的 UTC 约定 —— 这是响应格式，不该依赖另一个类的注释来成立。
                'created_at' => $issued->userCreatedAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            ],
        ];
    }
}
