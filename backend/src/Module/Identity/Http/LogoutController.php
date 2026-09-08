<?php

declare(strict_types=1);

namespace App\Module\Identity\Http;

use App\Module\Identity\Application\Session\LogoutService;
use App\Shared\Http\Controller\AbstractApiController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * `POST /v1/auth/logout` —— 撤销当前会话（T-105）。
 *
 * ⚠️ 这是 auth 组里**唯一需要 Bearer** 的端点（契约逐字写了这句）。
 * 它因此**不在** `AuthenticationListener::PUBLIC_ROUTES` 里 ——
 * 那张表按路由名匹配而不是路径前缀，正是为了不让 `/v1/auth/` 这个前缀
 * 把它一起放过去。放过去的后果是任何人都能撤销任何会话，
 * 而所有测试照常绿（没认证也返回 204）。
 *
 * ⚠️ `onboarding_incomplete` 的用户**也**能调用（契约里写明了）。
 * `auth_logout` 因此在
 * {@see \App\Shared\Infrastructure\Http\OnboardingListener::EXEMPT_ROUTES} 里（T-108）——
 * 拿掉它，中途放弃注册的人就再也登不出去，而 username 不可变、
 * 也没有第二条自助路径（ADR-0017 的 Consequences）。
 *
 * 204 无响应体：没有什么可以告诉客户端的，而「撤销了几条」是服务端的内部事实。
 */
final class LogoutController extends AbstractApiController
{
    public function __construct(private readonly LogoutService $service)
    {
    }

    #[Route('/v1/auth/logout', name: 'auth_logout', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        // sid 来自验过签的 access token，不是请求体 —— 调用方无法登出别人的会话。
        $this->service->logout($this->authContext($request)->sessionId);

        return $this->noContent();
    }
}
