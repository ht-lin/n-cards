<?php

declare(strict_types=1);

namespace App\Module\Identity\Http;

use App\Module\Identity\Application\Me\AssignUsernameService;
use App\Module\Identity\Application\Me\UsernamePayload;
use App\Module\Identity\Application\Me\UserProfile;
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
 * ⚠️ 交给 T-108：它的 onboarding 拦截器必须把 `me_username_set` 列进白名单
 * （与 `auth_logout`、`me_get` 并列）。漏了的话唯一的出口被拦截器自己堵死，
 * 而症状是「注册完的用户永远进不了钱包」——
 * 与 `LogoutController` 类注释里那条提醒是同一件事。
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

        return $this->json(['user' => self::body($profile)]);
    }

    /**
     * 契约的 `User` schema。键与顺序与 `OtpVerifyController::body()` 里
     * 那个 `user` 对象**逐字相同** —— 两处描述的是同一个 schema。
     *
     * ⚠️ T-108 的 `GET /v1/me` 与 `PATCH /v1/me` 要复用这一段，别再写第三份。
     *
     * @return array{
     *     id: string,
     *     username: ?string,
     *     locale: string,
     *     onboarding_complete: bool,
     *     created_at: string,
     * }
     */
    private static function body(UserProfile $profile): array
    {
        return [
            'id' => $profile->userId->toString(),
            // 走到这里它必然非 null（服务成功返回即意味着刚写进去），但类型仍是
            // `?string`：契约里 `User.username` 是 `anyOf: [Username, "null"]`，
            // 而 §5.2 的注册中间态让 null 在别的生产者那里是真实存在的值。
            'username' => $profile->username,
            'locale' => $profile->locale,
            'onboarding_complete' => $profile->onboardingComplete,
            // §6.1：时间一律 RFC 3339 UTC。显式转时区而不是信任 ClockInterface
            // 的 UTC 约定 —— 这是响应格式，不该依赖另一个类的注释来成立。
            'created_at' => $profile->createdAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}
