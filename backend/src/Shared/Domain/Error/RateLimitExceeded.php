<?php

declare(strict_types=1);

namespace App\Shared\Domain\Error;

/**
 * `429 rate_limited`（§7.5）。
 *
 * ============================================================================
 * 为什么是 DomainException 的子类
 * ============================================================================
 * {@see DomainException} 刻意**不 final**，其类注释明说这是预期用法。继承它意味着
 * 限流走的是与其余全部错误**同一条渲染路径**（`ApiProblemExceptionListener` →
 * `ApiProblemFactory`），于是 429 的响应体自动满足 RFC 9457、自动带 `request_id`、
 * 自动进同一份契约 schema。
 *
 * 自己在监听器里 `new JsonResponse(...)` 的话，API 会出现第二种错误响应格式 ——
 * 直接违反 ADR-0003 的「客户端只对 `code` 分支」。
 *
 * ============================================================================
 * 为什么要带 retryAfter / remaining 两个数
 * ============================================================================
 * §7.5：「限流响应**必须**带 `Retry-After` 与 `X-RateLimit-Remaining`」。
 * `ApiProblemExceptionListener::headersFor()` 原本只按 `ErrorCode` 给一个保守的
 * 静态默认值（`Retry-After: 5`），并在注释里留了「T-006 会带上更精确的值」。
 * 这两个字段就是那个「更精确的值」的载体 —— 它必须挂在异常上，因为算出它的地方
 * （Lua 脚本，知道最老一条记录的时间戳）与写出它的地方（Http 层）隔着两层。
 *
 * ⚠️ 两个数字都**不进** problem body，只进 header。§6.1 的 Problem Details 成员表
 * 是封闭的，多加成员会让 `docs/api/schemas/problem-details.schema.json` 与
 * Android 的 `ApiError` 一起漂。
 */
final class RateLimitExceeded extends DomainException
{
    /**
     * @param int         $retryAfterSeconds 最早可重试的等待秒数。多维度同时触发时，
     *                                       这里已经是**更长的**那个（§7.5 的明文要求），
     *                                       合并逻辑见 {@see \App\Shared\Domain\RateLimit\RateLimitDecision::mergeWith()}
     * @param int         $remaining         剩余次数，多窗口取最小值。超限时恒为 0
     * @param string|null $policy            触发的策略名，只进日志与 `detail`
     */
    public function __construct(
        private readonly int $retryAfterSeconds,
        private readonly int $remaining = 0,
        ?string $policy = null,
    ) {
        parent::__construct(
            ErrorCode::RateLimited,
            // 英文开发者文案（§6.1）。面向用户的德语由客户端本地化生成。
            //
            // ⚠️ 只放策略名，**绝不**放主体。主体是 email_hash / IP / user id ——
            // §8.2 的安全类数据，而 detail 会进日志与 Sentry。
            null !== $policy
                ? \sprintf('Rate limit "%s" exceeded. Retry after %d seconds.', $policy, $retryAfterSeconds)
                : \sprintf('Rate limit exceeded. Retry after %d seconds.', $retryAfterSeconds),
        );
    }

    public function retryAfterSeconds(): int
    {
        return $this->retryAfterSeconds;
    }

    public function remaining(): int
    {
        return $this->remaining;
    }
}
