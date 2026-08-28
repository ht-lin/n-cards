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
 * T-004 阶段还没有认证 —— Identity 是 T-1xx。所以默认实现
 * `Shared\Infrastructure\Http\AnonymousIdempotencyScopeResolver` 恒返回 null，
 * 中间件回落到 IP 维度。
 *
 * **待接入**：T-1xx 加 `AuthenticatedIdempotencyScopeResolver`，返回 `user:<uuid>`。
 *
 * ⚠️ 那时**必须在 config/services.yaml 里改显式 alias**，而不是指望 Symfony 的
 * 「单实现自动别名」—— 第二个实现一出现，自动别名就消失了，autowiring 会报一个
 * 很难懂的错。显式 alias 让这次替换是一行的、显眼的编辑。所以 T-004 现在就把
 * alias 写出来了，哪怕此刻只有一个实现。
 */
interface IdempotencyScopeResolverInterface
{
    /**
     * @return string|null 作用域标识（如 `user:0192f3a1-...`）；
     *                     无法确定时返回 null，由调用方回落到 IP 维度
     */
    public function resolve(): ?string;
}
