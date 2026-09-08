<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

use App\Shared\Application\Onboarding\OnboardingStatusInterface;
use App\Shared\Application\Token\AccessTokenVerifierInterface;
use App\Shared\Domain\Error\DomainException;
use App\Shared\Domain\Error\ErrorCode;
use App\Shared\Domain\Http\ApiSurface;
use App\Shared\Domain\Onboarding\OnboardingState;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * §5.2 那个「注册中间态」的围栏（T-108）。
 *
 * `username IS NULL` 的用户处于 `onboarding_incomplete`：除
 * {@see EXEMPT_ROUTES} 那三条之外，**所有** `/v1` 端点对他返回
 * `403 username_required`。规格在三处写了同一句话（§5.2、§6.3.1 注 2、
 * 契约 `User.onboarding_complete` 的 description），而这个类是它唯一的强制点。
 *
 * ============================================================================
 * ⚠️⚠️ 为什么必须是**一个**监听器，不能逐端点判断
 * ============================================================================
 * 任务卡逐字写着「不得逐端点判断（漏一个就是权限洞）」。这不是风格问题：
 * 逐端点的写法里，「忘了加那一行」的症状是**那个端点照常工作** ——
 * 没有任何测试会红，因为它本来就该返回 200。而这里的默认值是反过来的：
 * 新端点**自动**受管，要豁免必须主动来改 {@see EXEMPT_ROUTES}，
 * 且 `tests/Api/OnboardingCoverageTest` 会把每一条已注册路由都打一遍。
 *
 * ============================================================================
 * ⚠️⚠️ 白名单按**路由名**，不是路径前缀
 * ============================================================================
 * 与 {@see AuthenticationListener} 同一个坑，但这里更容易踩：三条豁免路由里
 * 有两条在 `/v1/me` 下，于是「前缀 `/v1/me` 一律放行」看起来很顺手 ——
 * 而 `PATCH /v1/me`、`GET /v1/me/devices`、`DELETE /v1/me/devices/{id}`、
 * `PUT /v1/me/devices/{id}/push-token` **全都**在那个前缀下，
 * 它们恰恰是这个监听器要挡的东西。用前缀会把整个设备管理面连同 `PATCH /me`
 * 一起放过去，而所有测试照常绿（那些端点本来就返回 200/204）。
 *
 * ============================================================================
 * ⚠️ 漏掉 `me_username_set` 会把唯一的出口堵死
 * ============================================================================
 * `POST /v1/me/username` 是这个状态**唯一**的出口（§5.2）。它不在表里的话，
 * 注册完的用户永远进不了钱包；而 `auth_logout` 也漏了的话，他连退出都做不到 ——
 * username 不可变、且没有第二条自助路径（ADR-0017 的 Consequences 一节）。
 * `UsernameController` 与 `LogoutController` 的类注释从 T-105 / T-107 起
 * 就在提前提醒这两条。
 *
 * ============================================================================
 * `null === $auth` 即放行 —— 这一条同时兑现三件事
 * ============================================================================
 * {@see AuthenticationListener} 对免鉴权路由是**在写 `AUTH_CONTEXT` 之前**返回的，
 * 所以一条免鉴权路由永远不会带着身份走到这里。于是这一行同时放过了：
 *
 *  1. 契约里四个 `security: []` 的公开端点（否则登录本身就不可能）；
 *  2. `tests/Fixture/Http/ProbeApiController` 的 `/v1/_probe/*` —— 它们在
 *     `%ncards.auth.public_routes.test_only%` 里，因此**不需要**在这里再开一个
 *     测试专用的配置口子（那正是 `ncards_auth.yaml` 存在的理由，而这里的豁免
 *     是结构性的，不是配置出来的）；
 *  3. `POST /v1/auth/token/refresh` —— §5.2 的三条清单里没有它，但它免鉴权。
 *     挡住它的话，一个卡在 onboarding 的用户会在 15 分钟后连 token 都换不了，
 *     于是连那三条豁免路由也够不着。
 *
 * ============================================================================
 * ⚠️ 优先级 10：晚于限流，早于幂等
 * ============================================================================
 * - **晚于 `AuthenticationListener`(16)**：豁免按路由名匹配要先有 `_route`，
 *   而状态判定要先有 `AuthContext`。两者都由它写下。
 * - **晚于 `RateLimitListener`(12)**：本类每个受管请求**查一次库**。
 *   那两个刻意排在限流前面的检查（`/v1/typo` 的 404、缺 `X-Client` 的 400）
 *   不碰任何后端，本类碰。排在限流前面等于给一个持 token 的客户端开一条
 *   不受 §7.5「user 300/min」约束的 DB 往返 —— 一个 403 重试循环就成了
 *   对 Postgres 的放大器。403 计入配额也是对的：那是客户端 bug 或滥用。
 * - **早于 `IdempotencyMiddleware`(8)**：一个注定 403 的 `POST` 不该白烧一个
 *   幂等键，更不该拿走一把 60 秒的在途锁、让客户端下一次重试撞上
 *   `409 idempotency_in_progress`。理由与那个类「必须晚于 RouterListener，
 *   否则 `POST /v1/typo` 会白烧一个键」逐字同源。
 *
 * 这个数字由 `tests/Integration/Shared/Http/ListenerOrderTest` 钉死。
 *
 * ============================================================================
 * 被否掉的替代方案：在 JWT 里加一个 `onb` claim
 * ============================================================================
 * 那样就不用查库了。但 access token 有 15 分钟寿命，于是**刚设完 username 的人
 * 会继续吃最长 15 分钟的 403** —— 除非设定成功后强制轮换令牌，也就是把
 * onboarding 的出口挂到令牌轮换上。而且 §7.1 把 claim 集钉成了恰好
 * `sub sid did jti iat exp`，加一个要改 ADR-0015 与两端。
 * 一次主键查找便宜得多，见 {@see \App\Module\Identity\Infrastructure\Onboarding\UserOnboardingStatus}。
 */
#[AsEventListener(event: KernelEvents::REQUEST, method: 'onRequest', priority: self::PRIORITY)]
final readonly class OnboardingListener
{
    /** 见类注释「优先级 10」。由 ListenerOrderTest 钉死。 */
    public const PRIORITY = 10;

    /**
     * onboarding 未完成时仍然可达的路由，**§5.2 逐字的三条**。
     *
     * ⚠️ 这里是路由名（`#[Route(name: ...)]`），不是路径。见类注释。
     *
     * ⚠️ 这张表**只能变短，不能随手变长**。多一行意味着一个未完成注册的用户
     * 能碰到那个端点，而 §5.2 的清单是产品决定不是实现细节 ——
     * 真要加，先改规格与契约，`OnboardingCoverageTest` 会盯着这三者一致。
     */
    public const EXEMPT_ROUTES = [
        'me_get',
        'me_username_set',
        'auth_logout',
    ];

    /**
     * 403 的 detail。
     *
     * ⚠️ 不写「请先设定 username」这类祈使句：§6.1 的 `detail` 是给开发者与
     * 日志看的英文说明，面向用户的德语文案由客户端按 `code` 本地化生成（§11.1）。
     */
    private const DETAIL = 'A username must be set before this endpoint can be used.';

    public function __construct(private OnboardingStatusInterface $status)
    {
    }

    /**
     * @throws DomainException `username_required`（403）/ `token_invalid`（401）
     */
    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        // ⚠️ 这两行的顺序与形状在六个横切监听器里是一致的。照抄，别自创。
        if (!ApiSurface::isProductApiPath($request->getPathInfo())) {
            return;
        }

        if (self::isExempt($request)) {
            return;
        }

        $auth = AuthenticationListener::readFrom($request);

        if (null === $auth) {
            return;
        }

        match ($this->status->stateOf($auth->userId)) {
            OnboardingState::Complete => null,
            OnboardingState::Incomplete => throw new DomainException(ErrorCode::UsernameRequired, self::DETAIL),
            // 签名验过了，但 `users` 那一行已经不在（删号 / T-113 的清理）。
            // **不能**返回 403：那会把客户端指向 `POST /me/username`（就在上面
            // 那张豁免表里），而那条路径的查找同样落空、抛 500 —— 客户端于是
            // 在 403 与 500 之间打转，且两个码都不会让它清掉本地会话。
            // 401 + 与验签器逐字相同的文案则让 T-150 的 Authenticator 去静默
            // 刷新，刷新同样失败（`sessions` 随外键一起没了），于是它清会话跳
            // 登录 —— 对一个已删除的账号，那正是想要的终局。
            // 复用同一句文案的另一半理由同 ADR-0015 决策 6：让「用户已删」与
            // 「token 是编的」对探测者不可区分。
            OnboardingState::UserUnknown => throw new DomainException(ErrorCode::TokenInvalid, AccessTokenVerifierInterface::REJECTED),
        };
    }

    private static function isExempt(Request $request): bool
    {
        $route = $request->attributes->get('_route');

        return \is_string($route) && \in_array($route, self::EXEMPT_ROUTES, true);
    }
}
