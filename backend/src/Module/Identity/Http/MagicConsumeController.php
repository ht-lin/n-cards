<?php

declare(strict_types=1);

namespace App\Module\Identity\Http;

use App\Module\Identity\Application\Magic\ConsumeMagicLinkService;
use App\Module\Identity\Application\Magic\MagicLinkConsumptionPayload;
use App\Module\Identity\Application\Session\SessionIssued;
use App\Shared\Http\Controller\AbstractApiController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * `POST /v1/auth/magic/consume` —— 用邮件里那个一次性令牌换令牌对（T-106）。
 *
 * 薄到只有解体、调用、组响应三步，理由与 {@see OtpVerifyController} 逐字相同。
 *
 * ============================================================================
 * ⚠️ 为什么只有 POST，没有 GET
 * ============================================================================
 * §7.1：企业邮件安全网关（Microsoft Defender、Barracuda 等）会**自动 GET**
 * 邮件里的每一个链接做扫描。若 `GET` 即消费，令牌在用户看到那封信之前就已失效。
 *
 * 所以信里的链接指向的**不是**本端点，而是 `https://app.n-cards.de/l/magic/<token>`
 * 的落地页 —— 一份静态 HTML（`infra/caddy/site/l/magic/index.html`），
 * 由 Caddy 直接吐出，后端在那个路径上**没有任何代码**。
 * 「GET 不消费」因此是结构保证，不是靠这里少写一个方法。
 * `tests/Api/RouteInventoryTest` 断言后端不注册任何 `/l/` 下的路由。
 *
 * 落地页上那个按钮把令牌交给 App（App Links / `intent://`），
 * 由 App 发本请求 —— 契约的 `device.platform` 只有 `android` 一个取值，
 * 浏览器构造不出合法的请求体，见 {@see MagicLinkConsumptionPayload} 的类注释。
 *
 * ⚠️ 剩下的一切由 T-004 的监听器链免费提供，**不要**在这里重复实现：
 * `X-Client` 必填与 426、`Idempotency-Key` 重放（2xx 存 24h）、`X-Request-Id` 透传、
 * 以及把 `RateLimitExceeded` 渲染成 429 + `Retry-After` + `X-RateLimit-Remaining`。
 * Service 抛出的 401 / 409 / 429 一律**不要 catch**。
 *
 * 幂等重放会把同一对令牌再发一次，理由与 {@see OtpVerifyController} 的
 * 类注释最后一节逐字相同（而且在这里更要紧：一次网络抖动导致的重试若创建了
 * 第二条 session，客户端只会留下最后那个，前一条要活满 90 天）。
 */
final class MagicConsumeController extends AbstractApiController
{
    public function __construct(private readonly ConsumeMagicLinkService $service)
    {
    }

    #[Route('/v1/auth/magic/consume', name: 'auth_magic_consume', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $payload = MagicLinkConsumptionPayload::fromArray($this->decodeBody($request));

        // IP 以裸字符串传进 Application，可信度由 framework.trusted_proxies 保证
        // （回归测试 tests/Api/TrustedProxyTest）—— 那条没了的话，§7.5 的
        // 「IP 60/h」会把全世界算作一个 IP，等于对登录端点的拒绝服务。
        $issued = $this->service->consume($payload, $request->getClientIp());

        return $this->json(self::body($issued));
    }

    /**
     * 与 {@see OtpVerifyController::body()} / {@see TokenRefreshController::body()}
     * 逐字相同的形状 —— 契约里三者是**同一个** `Session` schema。
     *
     * ⚠️ 三处重复是刻意的，理由写在 {@see TokenRefreshController::body()} 上：
     * 抽成共享 presenter 会让三个端点的响应体被焊死在一起，而 §13.6 的契约演进
     * 恰恰要求它们能各自独立地加字段。收敛点是契约与契约测试，不是 PHP。
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
                // §5.2：注册未完成时为 null。契约里 User.username 是
                // `anyOf: [Username, "null"]`，**不是**省略该键。
                'username' => $issued->username,
                'locale' => $issued->locale,
                'onboarding_complete' => $issued->onboardingComplete,
                // §6.1：时间一律 RFC 3339 UTC。
                'created_at' => $issued->userCreatedAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            ],
        ];
    }
}
