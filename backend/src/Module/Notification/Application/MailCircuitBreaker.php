<?php

declare(strict_types=1);

namespace App\Module\Notification\Application;

use App\Module\Notification\Application\Dto\MailTemplate;
use App\Module\Notification\Application\Port\MailVolumeCounterInterface;
use App\Module\Notification\Domain\MailCriticality;
use Psr\Log\LoggerInterface;

/**
 * §3.1 的「全局日发信量超阈值 → 告警 + **自动熔断非关键邮件（保留 OTP）**」。
 *
 * ============================================================================
 * 它保护的是什么
 * ============================================================================
 * R1：**发信域名被列入黑名单 → 全站无法登录**，概率「中」、影响「致命」。
 * 一次异常的发信量突增（bug 造成的重发循环、或对自有邮箱的 OTP 轰炸）
 * 是最可能触发它的原因，而域名一旦进了主流邮箱的黑名单，
 * 恢复要按天算 —— 不是重启能解决的事故。
 *
 * ============================================================================
 * 两条阈值，三档行为
 * ============================================================================
 * | 当日累计   | Critical（OTP 码 + Magic Link） | Advisory（两封安全提醒） |
 * |-----------|----------------------------|------------------------|
 * | < warn    | 发                          | 发                      |
 * | ≥ warn    | 发 + error 日志（告警信号）    | 发 + error 日志          |
 * | ≥ breaker | **发**                      | **丢弃**                 |
 *
 * Critical 在任何阈值之上都照发。理由见 {@see MailCriticality} ——
 * 掐掉 OTP 的熔断会亲手造成它想避免的后果（用户登不进去），只是换了个原因。
 *
 * ============================================================================
 * ⚠️ 计数器不可达时**放行**，与 §7.5 的限流相反
 * ============================================================================
 * `config/packages/rate_limiter.yaml` 里除 `write_endpoints` 外每一条都是
 * fail-closed，而那个文件的注释解释了原因：那些策略保护的是**安全边界**
 * （OTP 轰炸、username 枚举），关掉它们等于防线消失。
 *
 * 这里不是。熔断保护的是「域名声誉」这个**可用性**资产，
 * 而 fail-closed 的代价是：Redis 一次重启 → 计数器读不到 → 所有 Advisory 邮件
 * 被丢弃。§14.2 的 Redis 是单容器、无 HA，于是「Redis 重启」会静默变成
 * 「新设备登录提醒集体消失」，而那是一类安全通知。
 *
 * 换句话说两边的取舍是一致的，都是「哪个方向的失败后果更小」：
 * 限流失效 = 攻击面打开（不可接受）；熔断失效 = 多发了一些本可以省下的信
 * （在计数器恢复前的几分钟里，可接受，且会留下一行 error 日志）。
 *
 * 这个不对称是刻意的，别当成不一致顺手「修」掉 —— ADR-0003 §4 与
 * rate_limiter.yaml 的同一段注释是同一个模式的两次应用。
 */
final readonly class MailCircuitBreaker
{
    /**
     * @param int $warnThreshold    超过即记 error 日志（T-405 接 Alertmanager）
     * @param int $breakerThreshold 超过即丢弃 Advisory 邮件
     */
    public function __construct(
        private MailVolumeCounterInterface $counter,
        private LoggerInterface $logger,
        private int $warnThreshold,
        private int $breakerThreshold,
    ) {
        if ($this->warnThreshold >= $this->breakerThreshold) {
            // 配反了的话「告警」永远不会先于「熔断」发生，于是运维第一次知道
            // 出事就是从「用户说没收到提醒信」开始 —— 而告警的全部意义
            // 就是抢在那之前。构造期就炸，由 ContainerCompilesTest 兜底。
            throw new \InvalidArgumentException(\sprintf('告警阈值（%d）必须严格小于熔断阈值（%d），否则告警永远不会先于熔断触发。检查 config/packages/ncards_mail.yaml。', $this->warnThreshold, $this->breakerThreshold));
        }
    }

    /**
     * 这封信现在该不该发？**有副作用**：当日计数 +1。
     *
     * 计数在**判定之前**就加，包括最终被丢弃的那些。这是刻意的：
     * 阈值衡量的是「系统正在试图往外发多少信」，若只统计发出去的，
     * 熔断一旦生效，计数就会停在阈值附近不再增长，
     * 于是「压力还在不在」这个运维最需要的信息就没了。
     */
    public function allows(MailTemplate $template): bool
    {
        try {
            $volume = $this->counter->incrementAndGet();
        } catch (\RuntimeException $e) {
            // fail-open。理由见类注释 —— 不是疏忽，是与 §7.5 刻意相反的取舍。
            $this->logger->error('邮件发信量计数器不可达，本次跳过熔断判定并放行。', [
                'template' => $template->basename(),
                'exception' => $e,
            ]);

            return true;
        }

        if ($volume >= $this->warnThreshold) {
            // level 取 error 而不是 warning：§14.4 的告警链路（T-405）按 level
            // 分流，warning 不进 Alertmanager。这条日志**就是**那个 P1 告警的信号源，
            // 而 R1 的影响是「致命」。
            $this->logger->error('当日全局发信量已越过告警阈值。', [
                'volume' => $volume,
                'warn_threshold' => $this->warnThreshold,
                'breaker_threshold' => $this->breakerThreshold,
                'template' => $template->basename(),
                'criticality' => self::criticalityOf($template)->name,
            ]);
        }

        if ($volume < $this->breakerThreshold) {
            return true;
        }

        // 熔断已生效。保留登录信（§3.1 括号里那半句）——
        // ADR-0016 之后它同时载着 6 位码与 Magic Link，是同一封。
        return MailCriticality::Critical === self::criticalityOf($template);
    }

    /**
     * 哪些信在熔断期间照发。判据见 {@see MailCriticality} 的类注释：
     * **收件人是不是正卡在这封信上**，不是主观重要性。
     *
     * ============================================================================
     * 为什么这个 match 在这里，而不是 MailTemplate 上的一个方法
     * ============================================================================
     * `MailTemplate` 在 `Application\Dto`（调用方必须能命名它），而 Dto 层的
     * deptrac 允许列表只有 `Shared.Domain` —— 它引用不到 `MailCriticality`。
     *
     * 而且这样分也更准：「这封信在**全局熔断**时的去留」是熔断策略的一部分，
     * 不是模板的固有属性。调用方（T-103/T-104）不需要、也不应该知道它。
     *
     * 无 `default` 分支是刻意的：新增 MailTemplate case 时这里会抛
     * `\UnhandledMatchError`，逼人显式选一边而不是悄悄拿到某个默认值。
     * `MailCircuitBreakerTest` 穷举全部 case，所以那个错误在 CI 就会红。
     */
    private static function criticalityOf(MailTemplate $template): MailCriticality
    {
        return match ($template) {
            // 用户正卡在登录流程上等这一封（码与 Magic Link 都在里面，ADR-0016）。
            MailTemplate::OtpCode => MailCriticality::Critical,
            // 事后知情；§7.1 规定同一件事同时还会走推送，邮件不是唯一通道。
            MailTemplate::NewDeviceLogin, MailTemplate::RefreshReplay => MailCriticality::Advisory,
        };
    }
}
