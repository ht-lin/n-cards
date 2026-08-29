<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

use App\Shared\Application\RateLimit\RateLimitSubjectResolverInterface;

/**
 * 认证落地之前的默认主体解析器：恒返回 null。
 *
 * {@see RateLimitListener} 收到 null 时回落到 IP 维度 —— 这也是为什么
 * `config/packages/framework.yaml` 的 `trusted_proxies` 必须配对：没有它，
 * `getClientIp()` 对每个请求都返回 Caddy 容器的 IP，于是 §7.5 的
 * 「全部写接口 user 300/min」会把全世界算作一个人，等于对写接口的拒绝服务 ——
 * 而且看起来完全像是「限流生效了」。回归测试 `tests/Api/TrustedProxyTest`。
 *
 * **待接入（T-1xx / Identity）**：加一个返回 `user:<uuid>` 的实现，并把
 * `config/services.yaml` 里 `RateLimitSubjectResolverInterface` 的显式 alias 指过去。
 * 那条 alias 现在就写好了，正是为了让那次替换是一行的、显眼的编辑 ——
 * 与 {@see AnonymousIdempotencyScopeResolver} 完全同一个套路，两处最好一起改。
 */
final readonly class AnonymousRateLimitSubjectResolver implements RateLimitSubjectResolverInterface
{
    public function resolve(): ?string
    {
        return null;
    }
}
