<?php

declare(strict_types=1);

namespace App\Shared\Application\Idempotency;

/**
 * 幂等键的作用域 —— 「这个 `Idempotency-Key` 是**谁**的」。
 *
 * 没有作用域的话，任何人都能用别人的键把对方的响应读出来，或者更糟：
 * 两个不相干的客户端偶然用了同一个 UUID，第二个会拿到第一个的响应。
 *
 * ============================================================================
 * 这是一条**认证接缝**（写法照抄 T-003 的 HealthCheckInterface）
 * ============================================================================
 * T-004 阶段还没有认证，默认实现 `AnonymousIdempotencyScopeResolver` 恒返回 null，
 * 中间件回落到 IP 维度。
 *
 * ✅ **T-105 已接入**：
 * {@see \App\Shared\Infrastructure\Http\AuthenticatedIdempotencyScopeResolver}
 * 在认证过的请求上返回 `user:<uuid>`，其余仍然回落到 IP
 * （免鉴权的四个端点上没有身份可用 —— 那不是「匿名用户」，是「没有用户」）。
 * 那个 Anonymous 实现随之删除。
 *
 * ⚠️ 替换的落点是 `config/services.yaml` 里的**显式 alias**，不是指望 Symfony 的
 * 「单实现自动别名」—— 第二个实现一出现，自动别名就消失了，autowiring 会报一个
 * 很难懂的错。T-004 提前把 alias 写出来正是为了这一天，而它确实只改了一行。
 */
interface IdempotencyScopeResolverInterface
{
    /**
     * @return string|null 作用域标识（如 `user:0192f3a1-...`）；
     *                     无法确定时返回 null，由调用方回落到 IP 维度
     */
    public function resolve(): ?string;
}
