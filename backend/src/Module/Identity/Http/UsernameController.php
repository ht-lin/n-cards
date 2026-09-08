<?php

declare(strict_types=1);

namespace App\Module\Identity\Http;

use App\Module\Identity\Application\Me\AssignUsernameService;
use App\Module\Identity\Application\Me\UsernamePayload;
use App\Shared\Http\Controller\AbstractApiController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * `POST /v1/me/username` —— **一次性**设定 username（T-107）。
 *
 * ============================================================================
 * ⚠️ 这个文件里**只有一个方法**，而且必须一直如此
 * ============================================================================
 * §3.8 与 §6.2 都逐字写了：username 不可变，而不可变性是靠
 * 「**没有 `PATCH` / `PUT` 对应端点**」保证的 —— 不是靠某处的一个 if。
 * 所以在这个控制器里加第二个路由，等于把那条产品不变量删掉，
 * 而且删得毫无痕迹（测试会全绿：新端点本来就没有用例）。
 *
 * 真要加，先读 §3.8 的「可变性」那一行和 §17.5 的 Q10
 * （一期一律拒绝改名，引导注销重注册，**不开人工通道**）。
 *
 * ============================================================================
 * 需要 Bearer，但调用者必然是 onboarding 未完成的人
 * ============================================================================
 * 继承契约的全局 `security: [bearerAuth]`，所以这条路由**不进**
 * `AuthenticationListener::PUBLIC_ROUTES` —— `AuthenticationCoverageTest`
 * 拿那张表与契约里的 `security: []` 逐条对账。
 *
 * T-108 已落地：`me_username_set` 在
 * {@see \App\Shared\Infrastructure\Http\OnboardingListener::EXEMPT_ROUTES} 里
 * （与 `auth_logout`、`me_get` 并列）。把它从那张表里拿掉，等于让唯一的出口
 * 被拦截器自己堵死，症状是「注册完的用户永远进不了钱包」——
 * 与 `LogoutController` 类注释里那条提醒是同一件事。
 * `tests/Api/OnboardingCoverageTest` 单独钉着这一条。
 *
 * ============================================================================
 * `Idempotency-Key` 是白送的，而且副作用正好是想要的
 * ============================================================================
 * `IdempotencyMiddleware::applies()` 覆盖所有带该头的 `/v1` POST，不需要接线。
 * 于是「客户端没收到响应就重发」拿回的是缓存的 200，而不是第二次真执行时的
 * `409 username_immutable` —— 后者会让用户在一次网络抖动之后看到「这个名字
 * 已经被设定过了」这种没法理解的错误。也不会二次消耗 §7.5 的 10 次预算。
 */
final class UsernameController extends AbstractApiController
{
    public function __construct(private readonly AssignUsernameService $service)
    {
    }

    #[Route('/v1/me/username', name: 'me_username_set', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $payload = UsernamePayload::fromArray($this->decodeBody($request));

        $profile = $this->service->assign($this->authContext($request), $payload);

        // 走到这里 `username` 必然非 null（服务成功返回即意味着刚写进去），
        // 于是响应里的 `onboarding_complete` 恒为 true —— 客户端据此离开设定页。
        return $this->json(['user' => UserBody::of($profile)]);
    }
}
