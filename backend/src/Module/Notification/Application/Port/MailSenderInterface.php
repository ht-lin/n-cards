<?php

declare(strict_types=1);

namespace App\Module\Notification\Application\Port;

use App\Module\Notification\Application\Dto\MailRequest;

/**
 * **Notification 模块对外的唯一发信入口**（§3.2 / §4.2 规则 1）。
 *
 * ============================================================================
 * 这个接口存在的全部理由
 * ============================================================================
 * §3.2 把「一期单通道、无双活、无送达率监控体系」记为**有意识的风险接受**，
 * 而那个决定能被撤回（「未来 2 人日能补回来」）的唯一前提，是规格书里那句
 * 给实现者的话：
 *
 * > 请把发信实现收敛在 `Notification` 模块的 `MailSenderInterface` 之后
 * > （Symfony Mailer DSN 一行配置即可切换），**不要把邮箱服务商的细节泄漏到
 * > 业务代码里**。
 *
 * 所以：**本接口之上（Identity 等调用方）不得出现任何 `Symfony\Component\Mailer\*`
 * 或服务商特有类型**。这条不靠自觉 —— deptrac 里 `Framework.Mail` 图层
 * 只加进了 `Notification.Infrastructure` 的允许列表，别处 import 一个
 * `MailerInterface` 会直接是 violation（见 deptrac.yaml 里两层的定义注释）。
 *
 * ============================================================================
 * ⚠️ 调用即返回，**不等 SMTP**
 * ============================================================================
 * 实现是 {@see \App\Module\Notification\Infrastructure\Messenger\QueueingMailSender}：
 * 把请求丢进 `email` transport 就返回，真正的投递发生在 worker 里。
 *
 * 这不是性能优化，是 §3.8 的**安全要求**。T-103 的验收标准写明「已注册 vs
 * 未注册邮箱的响应体结构、状态码、**耗时分布**不可区分」——
 * 而真实路径要发信、decoy 路径不发信。若发送是同步的，两条路径的耗时差
 * 就是一次 SMTP 往返（几十到几百毫秒），防枚举当场失效，
 * 且这种失效在功能测试里完全看不出来。
 *
 * 推论：**本方法不会告诉你信有没有发出去**。返回 void 是刻意的 ——
 * 一个 bool 返回值只能表达「入队成功」，而调用方几乎一定会把它读成「已送达」。
 * 投递结果只在两个地方可见：§14.4 的 `email_send_total{result}` 指标，
 * 与失败重投三次后进的 `email_failed` 队列。
 */
interface MailSenderInterface
{
    /**
     * 把一封信排进外发队列。
     *
     * @throws \InvalidArgumentException {@see MailRequest} 的变量集与模板对不上
     *                                   （构造 DTO 时就会抛，这里只是转述）
     */
    public function send(MailRequest $request): void;
}
