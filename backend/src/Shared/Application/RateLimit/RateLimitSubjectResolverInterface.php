<?php

declare(strict_types=1);

namespace App\Shared\Application\RateLimit;

/**
 * 「本次请求该按谁限流？」—— §7.5 的 `user` 维度的解析点。
 *
 * ============================================================================
 * ⚠️ 为什么方法不接收 Request
 * ============================================================================
 * 与 {@see \App\Shared\Application\Idempotency\IdempotencyScopeResolverInterface}
 * 完全同一个约束：deptrac 里 `Shared.Application` 的允许列表是
 * `Shared.Domain` + `Framework.Core`，而 `Symfony\Component\HttpFoundation\Request`
 * 属于 `Framework.Http` —— 在这一层是 violation。
 *
 * 实现类住在 `Shared\Infrastructure\Http`，那里可以自由地从
 * `RequestStack` / 安全上下文里取值。
 *
 * ============================================================================
 * 返回 null 是什么意思
 * ============================================================================
 * 「这个请求还没有可识别的用户」。{@see \App\Shared\Infrastructure\Http\RateLimitListener}
 * 收到 null 时回落到 IP 维度 —— 这也是为什么 `config/packages/framework.yaml`
 * 的 `trusted_proxies` 必须配对：没有它，`getClientIp()` 对每个请求都返回
 * Caddy 容器的 IP，§7.5 的「全部写接口 300/min」会把全世界算作一个人。
 * 回归测试 `tests/Api/TrustedProxyTest`。
 */
interface RateLimitSubjectResolverInterface
{
    /**
     * @return string|null 已带维度前缀的主体（如 `user:0192...`）；未认证时 null
     */
    public function resolve(): ?string;
}
