<?php

declare(strict_types=1);

namespace App\Shared\Application\RateLimit;

use App\Shared\Domain\Error\RateLimitExceeded;
use App\Shared\Domain\RateLimit\RateLimitCheck;

/**
 * §7.5 速率限制的门面。
 *
 * ============================================================================
 * ⚠️ 为什么接口在 Shared\Application 而实现在 Shared\Infrastructure
 * ============================================================================
 * 与 {@see \App\Shared\Application\Crypto\CryptoServiceInterface} 完全同一个理由：
 * deptrac 里各模块 `*.Application` 的允许列表含 `Shared.Application`、
 * **不含 `Shared.Infrastructure`**。而限流的调用点恰恰在 Application ——
 * T-103 的 OTP 请求处理器要在发信之前先扣三个维度的配额。
 *
 * 接口放进 Infrastructure 的话，那些处理器根本无法注入它，只能退化成
 * 「在 Http 层限流」，于是任何非 HTTP 入口（Messenger 消费者、控制台命令）
 * 就绕过了 §7.5。
 *
 * ============================================================================
 * 只有 consume，没有 peek
 * ============================================================================
 * 「先问问还剩几次」这类 API 会诱导出 check-then-act 的竞态调用。要判定就消耗，
 * 要展示剩余次数就读被拒时的 `X-RateLimit-Remaining`。
 */
interface RateLimiterInterface
{
    /**
     * 单维度限流。
     *
     * @param string $policy  `config/packages/rate_limiter.yaml` 里的策略名
     * @param string $subject 被限流的主体，**必须带维度前缀**（见 {@see RateLimitCheck}）
     *
     * @throws RateLimitExceeded                        超限，`429 rate_limited`
     * @throws \App\Shared\Domain\Error\DomainException Redis 不可达且该策略 fail-closed，
     *                                                  `503 service_unavailable`
     * @throws \InvalidArgumentException                策略名不存在（配置错误，不是运行时状况）
     */
    public function consume(string $policy, string $subject, int $tokens = 1): void;

    /**
     * 多维度限流，**全过才扣**。
     *
     * §7.5 的 `POST /auth/otp/request`（email_hash + IP）与 `GET /v1/users/lookup`
     * （user + IP）都是这个形状。
     *
     * ⚠️ 「全过才扣」是承重的，不是优化。逐个 `consume()` 的话：攻击者打爆某个
     * 共享出口 IP 的配额之后，每一个走那个 IP 的正常用户在被 IP 维度拒绝之前，
     * **自己的 email 维度配额已经被扣掉了** —— 于是攻击者能远程烧掉任意受害者的
     * OTP 额度。实现见 {@see RateLimiterInterface} 的实现类里的两阶段说明。
     *
     * 被拒时抛出的 {@see RateLimitExceeded} 已经合并过全部维度：
     * `Retry-After` 取**更长的**，`remaining` 取**更小的**（§7.5 的明文要求）。
     *
     * @param list<RateLimitCheck> $checks 空数组是合法的（无操作）
     *
     * @throws RateLimitExceeded
     * @throws \App\Shared\Domain\Error\DomainException
     */
    public function consumeAll(array $checks): void;
}
