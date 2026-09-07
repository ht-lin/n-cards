<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

use App\Shared\Application\Idempotency\IdempotencyScopeResolverInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * 幂等键的作用域（T-105）—— 认证过的请求按用户隔离，其余回落到 IP。
 *
 * 取代 T-004 留下的 `AnonymousIdempotencyScopeResolver`（恒返回 null，已删），与
 * {@see AuthenticatedRateLimitSubjectResolver} 是同一个待办的两半，**一起改**。
 *
 * ============================================================================
 * 为什么作用域必须包含身份，而不是只用 Idempotency-Key 本身
 * ============================================================================
 * 幂等键由**客户端**生成。两个用户各自生成同一个键（UUID 碰撞是不会的，
 * 但「客户端用了一个可预测的键」是会的）而共用一个全局命名空间的话，
 * 后者会拿到前者的响应 —— 那是一次跨用户的数据泄露，
 * 且症状是「偶尔看到别人的卡」这种没人能复现的报告。
 *
 * 加上 `user:` 前缀之后，最坏情况退化成「同一个用户自己的两次不同请求撞了键」，
 * 而那正是 `IdempotencyMiddleware` 的 body 指纹要挡的东西
 *（撞了且 body 不同 → `422 idempotency_key_reused`）。
 *
 * ⚠️ 回落到 IP 的那一半同样依赖 `trusted_proxies`，理由与
 * {@see AuthenticatedRateLimitSubjectResolver} 逐字相同。
 *
 * ============================================================================
 * ⚠️ `POST /v1/auth/token/refresh` 走的是 IP 那一半，这是对的
 * ============================================================================
 * 刷新端点没有 Bearer（契约里 `security: []`），所以这里返回 null。
 * 而幂等在那个端点上是**承重的**：客户端拿旧 refresh token 重试时，
 * 中间件回放存下的 200，否则会命中 §7.1 的重放检测、整条会话被撤销。
 * 按 IP 隔离对它足够 —— 键由客户端生成，且刷新请求本来就来自单一设备。
 */
final readonly class AuthenticatedIdempotencyScopeResolver implements IdempotencyScopeResolverInterface
{
    public function __construct(private RequestStack $requests)
    {
    }

    public function resolve(): ?string
    {
        $request = $this->requests->getCurrentRequest();

        if (null === $request) {
            return null;
        }

        $context = AuthenticationListener::readFrom($request);

        return null === $context ? null : 'user:'.$context->userId->toString();
    }
}
