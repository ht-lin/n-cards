<?php

declare(strict_types=1);

namespace App\Module\Notification\Application\Port;

/**
 * 当日**全局**外发信件数（§3.1 / §7.5 表格最后一行「全局 · 邮件外发总量」）。
 *
 * ============================================================================
 * 为什么不是一条限流策略
 * ============================================================================
 * `config/packages/rate_limiter.yaml` 里没有它，
 * 而 `tests/Unit/.../RateLimitPolicyCoverageTest` 里有一条显式豁免：
 *
 *   '全局邮件外发总量' => '§14.4 的阈值告警 + 熔断，不是 per-subject 限流'
 *
 * 三点区别：
 *   1. 它没有 subject —— `RateLimiterInterface` 的整个 API 都是围绕
 *      「某个 email_hash / IP / user 的配额」建的，全局计数没有键可传。
 *   2. 超限时的动作不是拒绝请求（429），而是**按信件分级丢弃**
 *      （见 {@see \App\Module\Notification\Domain\MailCriticality}）。
 *   3. 降级方向相反 —— 见 {@see \App\Module\Notification\Application\MailCircuitBreaker} 的类注释。
 *
 * ============================================================================
 * 「按日」是哪一天
 * ============================================================================
 * 实现按 **UTC** 日历日切换（`RedisMailVolumeCounter`）。不用欧洲/柏林本地时区
 * 是因为夏令时切换那两天会出现 23 或 25 小时的窗口，而阈值是按「一天大概多少封」
 * 定的 —— 25 小时的窗口会让告警在每年某一天无理由地更容易触发。
 */
interface MailVolumeCounterInterface
{
    /**
     * 当日计数 +1，返回**加过之后**的值。
     *
     * 原子地做完这两件事（Redis `INCR` 的返回值）。分成「读 + 写」两步的话，
     * app 与两个 worker 副本并发时会各自读到同一个旧值，
     * 于是阈值判定在最需要它的时候（发信量突增）最不准。
     *
     * @throws \RuntimeException 计数器不可达。调用方**必须** catch 并放行 ——
     *                           理由见 {@see \App\Module\Notification\Application\MailCircuitBreaker}
     */
    public function incrementAndGet(): int;
}
