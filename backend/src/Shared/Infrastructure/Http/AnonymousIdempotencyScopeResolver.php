<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

use App\Shared\Application\Idempotency\IdempotencyScopeResolverInterface;

/**
 * 认证落地之前的默认作用域解析器：恒返回 null。
 *
 * {@see IdempotencyMiddleware} 收到 null 时回落到
 * IP 维度 —— 这也是为什么 `config/packages/framework.yaml` 的 `trusted_proxies`
 * 必须配对：没有它，`getClientIp()` 对每个请求都返回 Caddy 容器的 IP，
 * 全世界的匿名客户端会挤进同一个幂等作用域。
 *
 * **待接入（T-1xx / Identity）**：加一个返回 `user:<uuid>` 的实现，
 * 并把 config/services.yaml 里 `IdempotencyScopeResolverInterface` 的显式 alias
 * 指过去。那个 alias 现在就写好了，正是为了让这次替换是一行的、显眼的编辑。
 */
final readonly class AnonymousIdempotencyScopeResolver implements IdempotencyScopeResolverInterface
{
    public function resolve(): ?string
    {
        return null;
    }
}
