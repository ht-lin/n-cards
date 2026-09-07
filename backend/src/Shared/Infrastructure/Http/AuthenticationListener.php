<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

use App\Shared\Application\Token\AccessTokenVerifierInterface;
use App\Shared\Domain\Error\DomainException;
use App\Shared\Domain\Error\ErrorCode;
use App\Shared\Domain\Http\ApiSurface;
use App\Shared\Domain\Http\AuthContext;
use App\Shared\Domain\Http\RequestAttributes;
use App\Shared\Domain\Time\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * `Authorization: Bearer <jwt>` 的强制（§7.1，T-105）。
 *
 * 契约里全局 `security: [bearerAuth]`，只有四个端点用 `security: []` 覆盖掉。
 * 这个监听器就是那句话的唯一强制点。
 *
 * ============================================================================
 * ⚠️⚠️ 白名单按**路由名**，不是路径前缀
 * ============================================================================
 * 这是本类最容易写错、且写错了没有任何症状的一处。
 *
 * 免鉴权的四个端点里有三个在 `/v1/auth/` 下，于是「前缀 `/v1/auth/` 一律放行」
 * 看起来完全等价 —— 但 `POST /v1/auth/logout` **也**在那个前缀下，
 * 而它是 auth 组里**唯一需要 Bearer** 的端点（契约 `:323` 逐字写了这句）。
 * 用前缀会把它一起放过去，后果是任何人都能撤销任何会话，
 * 而所有测试照常绿（logout 本来就返回 204，没人认证也 204）。
 *
 * 所以白名单是一张**逐条列出的路由名**，且由
 * `tests/Api/AuthenticationCoverageTest` 拿它与契约里 `security: []` 的集合对账 ——
 * 两边不一致就红。新增免鉴权端点要同时改三处：契约、这张表、那个测试。
 *
 * ============================================================================
 * ⚠️ 优先级 16：晚于路由，早于限流
 * ============================================================================
 * - 晚于 `RouterListener`(32)：白名单按路由名匹配，得先有 `_route`。
 *   顺带也让 `POST /v1/typo` 直接 404 而不是先要一个 token ——
 *   对一个打错路径的客户端，「你没登录」是最没用的错误信息。
 * - 晚于 `ClientVersionListener`(40)：一个即将因缺 `X-Client` 而 400 的请求同理。
 * - **早于 `RateLimitListener`(12)**：这条是承重的。那个监听器要按
 *   `user:<uuid>` 限流（§7.5「全部写接口 user 300/min」），而它的主体
 *   由 {@see AuthenticatedRateLimitSubjectResolver} 从本监听器写下的属性里取。
 *   反过来排的话，每个请求都会回落到 IP 维度 —— 而那正是 NAT 后面
 *   一整栋楼共用一个配额的形状，且看起来完全像是「限流生效了」。
 *
 * 这个数字由 `tests/Integration/Shared/Http/ListenerOrderTest` 钉死。
 *
 * ============================================================================
 * 只验令牌，**不查库**
 * ============================================================================
 * §7.1：服务端不做 access token 黑名单，15 分钟窗口可接受。
 * 在这里补一句「顺便查 sessions 看有没有被撤销」会：
 *   1. 给每个带 Bearer 的请求加一趟 DB 往返（§9.1 的性能预算里没有这一笔）；
 *   2. 把一条写进规格并已在 ADR-0015 记录的决定悄悄反悔掉。
 * 需要「此刻是否有效」的端点自己查表 —— 见 `DeviceController` 与刷新路径。
 */
#[AsEventListener(event: KernelEvents::REQUEST, method: 'onRequest', priority: self::PRIORITY)]
final readonly class AuthenticationListener
{
    /** 见类注释「优先级 16」。由 ListenerOrderTest 钉死。 */
    public const PRIORITY = 16;

    public const HEADER = 'Authorization';

    /** RFC 6750 §2.1 的 scheme，大小写不敏感地匹配（`bearer` 也算）。 */
    private const SCHEME = 'bearer';

    /**
     * 免鉴权的路由名，**与契约里带 `security: []` 的操作一一对应**。
     *
     * ⚠️ 这里是路由名（`#[Route(name: ...)]`），不是路径。
     * 见类注释：用路径前缀会把 `auth_logout` 一起放过去。
     *
     * ⚠️ T-106 落地 `POST /v1/auth/magic/consume` 时要往这里加一行
     * （契约里它已经是 `security: []`）。`AuthenticationCoverageTest`
     * 只对**已注册的**路由对账，所以在那之前这里少一行不会红。
     */
    public const PUBLIC_ROUTES = [
        'auth_otp_request',
        'auth_otp_verify',
        'auth_token_refresh',
    ];

    /**
     * 全部拒绝情形共用的文案 —— **与验签器抛的那一句逐字相同**。
     *
     * ⚠️ 不细分「没带 header」/「不是 Bearer」/「验签不过」。
     * 前两种对一个正常客户端不会发生（它总是带的），而对一个探测者，
     * 可辨认的差异是免费的信息。
     *
     * ⚠️ 常量本体在 {@see AccessTokenVerifierInterface::REJECTED} 上，
     * 不是这里各写一份 —— 各写一份的话，本类的「没带 header」与验签器的
     * 「token 不对」会给出两句不同的文案，而这两段注释都说过要避免那件事。
     * 那不是今天的洞，是一条会随时间长歪的裂缝。
     */
    private const REJECTED = AccessTokenVerifierInterface::REJECTED;

    /** @var list<string> {@see PUBLIC_ROUTES} 加上仅测试环境的那些 */
    private array $publicRoutes;

    /**
     * @param list<string> $testOnlyPublicRoutes `%ncards.auth.public_routes.test_only%`，**生产恒为空**
     *
     * ⚠️ 这个参数存在的唯一原因是 `tests/Fixture/Http/ProbeApiController` ——
     * T-004 交付的那组 `/v1/_probe/*` 夹具路由，横切层的契约测试
     * （幂等、限流、problem+json、trusted proxy）全跑在它们上面，
     * 而那些测试与认证无关，不该被迫先造一枚 token。
     *
     * 做成配置而不是把 `probe_*` 直接写进 {@see PUBLIC_ROUTES}，
     * 套路与 `rate_limiter.yaml` 的 `ncards.rate_limits.test_only` 逐字相同：
     * 生产代码里不该出现只有测试才用的路由名，否则某天有人往 PUBLIC_ROUTES 里
     * 加一行 `probe_` 开头的东西时，没有任何机制拦得住它进生产。
     */
    public function __construct(
        private AccessTokenVerifierInterface $verifier,
        private ClockInterface $clock,
        #[Autowire('%ncards.auth.public_routes.test_only%')]
        array $testOnlyPublicRoutes = [],
    ) {
        $this->publicRoutes = [...self::PUBLIC_ROUTES, ...$testOnlyPublicRoutes];
    }

    /**
     * @throws DomainException `token_invalid` / `token_expired`（401）
     */
    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        // ⚠️ 这两行的顺序与形状在五个横切监听器里是一致的。照抄，别自创。
        if (!ApiSurface::isProductApiPath($request->getPathInfo())) {
            return;
        }

        if ($this->isPublic($request)) {
            return;
        }

        $token = self::bearerToken($request);

        if (null === $token) {
            throw new DomainException(ErrorCode::TokenInvalid, self::REJECTED);
        }

        // 验签器抛 token_invalid / token_expired（401）与 CryptoUnavailable（503）。
        // 三者都**不 catch** —— 尤其是 503：把「Vault 挂了」压成 401 会让全体
        // 客户端在一次 Vault 故障中清空本地会话、退回登录页，而登录本身也是坏的。
        $claims = $this->verifier->verify($token, $this->clock->now());

        $request->attributes->set(
            RequestAttributes::AUTH_CONTEXT,
            new AuthContext($claims->subject, $claims->sessionId, $claims->deviceId),
        );
    }

    /**
     * 本次请求认证出的身份，没有则 null。
     *
     * 形状与 {@see ClientVersionListener::readFrom()} 一致 —— 两个解析器与
     * `AbstractApiController` 都从这里读，键名不散落。
     */
    public static function readFrom(Request $request): ?AuthContext
    {
        $context = $request->attributes->get(RequestAttributes::AUTH_CONTEXT);

        return $context instanceof AuthContext ? $context : null;
    }

    private function isPublic(Request $request): bool
    {
        $route = $request->attributes->get('_route');

        return \is_string($route) && \in_array($route, $this->publicRoutes, true);
    }

    /**
     * `Authorization: Bearer <token>` → `<token>`，形状不对则 null。
     *
     * ⚠️ scheme 用 `strcasecmp` 比：RFC 7235 §2.1 规定 scheme 大小写不敏感，
     * 而 OkHttp / Retrofit 侧发出的恒是 `Bearer`。收得严一点没有安全收益，
     * 只会制造一类「curl 能过、某个客户端不能过」的幽灵故障。
     *
     * 反过来 token 部分**逐字**取，不 trim 内部空白 —— base64url 里没有空格，
     * 一个带空格的 token 就是畸形的。
     */
    private static function bearerToken(Request $request): ?string
    {
        $header = $request->headers->get(self::HEADER);

        if (null === $header) {
            return null;
        }

        // 恰好切成两段：多一个空格就是畸形，不要用 explode 后取 [1] 那种写法
        // （`Bearer a b` 会静默地变成 `a`）。
        $parts = explode(' ', trim($header));

        if (2 !== \count($parts) || 0 !== strcasecmp($parts[0], self::SCHEME) || '' === $parts[1]) {
            return null;
        }

        return $parts[1];
    }
}
