<?php

declare(strict_types=1);

namespace App\Module\Notification\Domain;

/**
 * §14.4 `email_send_total{provider,template,result}` 里那个 `result` 标签的值域。
 *
 * 三个取值刻意互斥且穷尽 —— 每一次 `SendMailHandler::__invoke()` 恰好记一次，
 * 所以 `sum(email_send_total)` 就是「worker 处理过的消息数」，
 * 而 §14.4 的「邮件发送失败率 > 5% 持续 10 min → P1」可以直接写成
 * `failed / (sent + failed + suppressed)`。
 *
 * ⚠️ `Suppressed` 与 `Failed` 分开是必须的：熔断丢弃是**我们自己的决定**，
 * 混进失败率会让 §14.4 那条 P1 告警在熔断生效期间必然触发，
 * 于是「域名要被拉黑了」与「通道坏了」两个完全不同的事故长得一模一样。
 */
enum MailResult: string
{
    /** 已交给邮箱服务商。**不代表送达** —— 送达率监控是 §3.2 明确后置的项。 */
    case Sent = 'sent';

    /** 渲染 / 解密 / 投递抛了异常。Messenger 会重投，重投也会各记一次。 */
    case Failed = 'failed';

    /** 全局熔断丢弃的非关键邮件（§3.1）。见 {@see MailCriticality}。 */
    case Suppressed = 'suppressed';
}
