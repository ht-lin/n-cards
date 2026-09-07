<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

use App\Shared\Application\RateLimit\RateLimitSubjectResolverInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * §7.5 的 `user` 维度（T-105）—— 认证过的请求按用户限流，其余回落到 IP。
 *
 * 取代 T-004 留下的 `AnonymousRateLimitSubjectResolver`（恒返回 null，已删）。
 * 那个类的注释说这次替换「应该是一行的、显眼的编辑」，而它与
 * {@see AuthenticatedIdempotencyScopeResolver} **必须一起改** ——
 * 两者是同一个待办的两半，T-105 确实是一起改的。
 *
 * ============================================================================
 * 返回 null 仍然是正确答案，别硬凑一个主体
 * ============================================================================
 * 免鉴权的四个端点（OTP 两个、magic consume、token refresh）上没有 AuthContext，
 * 这里返回 null，{@see RateLimitListener} 回落到 `ip:`。那是**对的**：
 * 它们本来就有各自更贴切的限流维度（email_hash / challenge_id / session），
 * 由端点自己在 Application 层消费。
 *
 * ⚠️ 回落到 IP 依赖 `config/packages/framework.yaml` 的 `trusted_proxies`。
 * 没有它，`getClientIp()` 对每个请求都返回 Caddy 容器的 IP，
 * §7.5 的「全部写接口 300/min」会把全世界算作一个人 —— 等于对写接口的拒绝服务，
 * 而且看起来完全像是「限流生效了」。回归测试 `tests/Api/TrustedProxyTest`。
 *
 * ============================================================================
 * ⚠️ 前缀 `user:` 不能省
 * ============================================================================
 * {@see RateLimitListener::subject()} 的注释点名了这条：不带维度前缀的话，
 * 一个 IP 字符串与一个恰好相同的 user id 会共用同一个计数桶。
 * 概率极低，后果是一个用户被另一个人限流，且完全无法从日志里看出来。
 */
final readonly class AuthenticatedRateLimitSubjectResolver implements RateLimitSubjectResolverInterface
{
    public function __construct(private RequestStack $requests)
    {
    }

    public function resolve(): ?string
    {
        $request = $this->requests->getCurrentRequest();

        if (null === $request) {
            // 控制台命令、messenger worker：没有请求，也就没有限流主体。
            return null;
        }

        $context = AuthenticationListener::readFrom($request);

        return null === $context ? null : 'user:'.$context->userId->toString();
    }
}
