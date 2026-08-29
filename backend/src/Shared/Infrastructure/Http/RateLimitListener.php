<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

use App\Shared\Application\RateLimit\RateLimiterInterface;
use App\Shared\Application\RateLimit\RateLimitSubjectResolverInterface;
use App\Shared\Domain\Error\RateLimitExceeded;
use App\Shared\Domain\Http\ApiSurface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * §7.5 的「**全部写接口** | user | 300/min」。
 *
 * ============================================================================
 * 为什么这一条要用监听器，别的不要
 * ============================================================================
 * §7.5 速率表里的其余每一行都绑在**具体端点**上，而且它们的维度
 * （email_hash、challenge_id、session、device）只有在请求体解析、
 * 甚至认证完成之后才知道 —— 那些必须由端点自己调
 * {@see RateLimiterInterface::consumeAll()}，在 Application 层，
 * 拿到主体之后。硬塞进一个全局监听器就意味着这一层要解析请求体、
 * 要调 Vault 算 email_hash，那是把三个模块的职责挪进 Shared 内核。
 *
 * 只有「全部写接口」这一条是真正横切的：它的判据只有「路径」与「方法」，
 * 两者在 `kernel.request` 上就全知道。逐端点重复它的结果是漏一个就是一个洞。
 *
 * ============================================================================
 * ⚠️ 只对 `/v1/*` 生效
 * ============================================================================
 * 用 {@see ApiSurface::isProductApiPath()} 这个正向白名单，与 T-004 的
 * `ClientVersionListener` / `IdempotencyMiddleware` 一致。`/health/live` 与
 * `/health/ready` 的调用方是 Docker healthcheck、Caddy 与 §14.3 的 Ansible ——
 * 部署期间它们的调用频率完全不该受产品限额约束，而且被限流的探针会让
 * compose 起栈与 staging 部署一起失败。
 *
 * ============================================================================
 * ⚠️ 优先级 12：晚于路由，早于幂等
 * ============================================================================
 * - 晚于 `RouterListener`(32)：`POST /v1/typo` 应该直接 404，不该烧掉一次配额 ——
 *   否则打错路径的客户端会把自己限死，且错误信息毫无指向性。
 * - 晚于 `ClientVersionListener`(40)：一个即将因缺 `X-Client` 而 400 的请求同理。
 * - 早于 `IdempotencyMiddleware`(8)：被限流的请求**不该抢占幂等键**。
 *   反过来的话，一个 429 会先在 Redis 里占下 60 秒的在途锁，客户端按
 *   `Retry-After` 重试时拿到的是 `409 idempotency_in_progress`，
 *   排查方向直接被带偏。
 *
 * 这个数字由 `tests/Integration/Shared/Http/ListenerOrderTest` 钉死 ——
 * 优先级散在各个文件的属性里，那个测试是唯一能防止后来者随手改序的东西。
 */
#[AsEventListener(event: KernelEvents::REQUEST, method: 'onRequest', priority: self::PRIORITY)]
final readonly class RateLimitListener
{
    /** `config/packages/rate_limiter.yaml` 里的策略名。 */
    public const POLICY = 'write_endpoints';

    /** 见类注释「优先级 12」。由 ListenerOrderTest 钉死。 */
    public const PRIORITY = 12;

    /**
     * 「写接口」= 会改变服务端状态的方法。
     *
     * `GET` / `HEAD` / `OPTIONS` 不在内 —— §7.5 对读接口的限流是按端点配的
     * （`GET /v1/sync` 60/min、`GET /v1/users/lookup` 30/min），
     * 在这里一刀切会与那些更严格的策略叠加成一个说不清的复合限额。
     */
    private const WRITE_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function __construct(
        private RateLimiterInterface $limiter,
        private RateLimitSubjectResolverInterface $subjects,
    ) {
    }

    /**
     * @throws RateLimitExceeded 超限，由 {@see ApiProblemExceptionListener} 渲染成
     *                           `429 rate_limited` + `Retry-After` + `X-RateLimit-Remaining`
     */
    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        if (!self::applies($request)) {
            return;
        }

        $this->limiter->consume(self::POLICY, $this->subject($request));
    }

    private static function applies(Request $request): bool
    {
        return ApiSurface::isProductApiPath($request->getPathInfo())
            && \in_array($request->getMethod(), self::WRITE_METHODS, true);
    }

    /**
     * 认证落地后是 `user:<uuid>`；在那之前回落到 IP。
     *
     * ⚠️ 前缀不能省。不带 `ip:` / `user:` 的话，一个 IP 字符串与一个恰好相同的
     * user id 会共用同一个计数桶 —— 概率极低，但后果是一个用户被另一个人限流，
     * 而且完全无法从日志里看出来。
     *
     * `getClientIp()` 返回 null 只发生在没有 REMOTE_ADDR 的场景（控制台、
     * 某些测试内核）。回落到一个固定串而不是跳过限流：跳过等于给任何能造出
     * 这种请求的调用方开一个后门。
     */
    private function subject(Request $request): string
    {
        return $this->subjects->resolve() ?? 'ip:'.($request->getClientIp() ?? 'unknown');
    }
}
