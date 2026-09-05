<?php

declare(strict_types=1);

namespace App\Module\Notification\Application\Port;

use App\Module\Notification\Application\Dto\MailRequest;

/**
 * 真正把一封信交给邮箱服务商（worker 侧，同步、会阻塞）。
 *
 * ============================================================================
 * 与 {@see MailSenderInterface} 的分工
 * ============================================================================
 *   MailSenderInterface     模块**对外**的入口。调用即返回，只负责入队。
 *   MailTransportInterface  模块**对内**的出站边界。由
 *                           {@see \App\Module\Notification\Application\SendMailHandler}
 *                           在 worker 里调用，此时阻塞是正确的。
 *
 * 两个接口都在 `Port/` 目录下，但只有第一个是给别的模块用的。
 * 分成两个而不是让 Handler 直接 new 一个 Mailer，是为了让熔断判定与指标记录
 * 留在 Application 层（可单测、计入 `Module/<M>/Application` 的 85% 覆盖率门槛），
 * 而 `Symfony\Component\Mailer\*` 留在 Infrastructure（deptrac 的
 * `Framework.Mail` 图层只对 `Notification.Infrastructure` 开放）。
 *
 * ============================================================================
 * 实现要做的三件事，顺序不能换
 * ============================================================================
 * 1. 解密收件人（`CryptoServiceInterface::decrypt(CryptoKey::Pii, …)`）——
 *    明文地址的作用域到此为止，仅一个方法体。
 * 2. 渲染 `templates/email/{locale}/{basename}.{subject.txt,txt,html}.twig` 三份。
 * 3. 交给 Symfony Mailer。
 */
interface MailTransportInterface
{
    /**
     * @throws \RuntimeException 渲染失败、解密失败或投递失败。**一律让它冒泡** ——
     *                           Messenger 的 retry_strategy 会重投三次，
     *                           三次都失败才进 `email_failed`。在这里 catch 并
     *                           静默返回的话，一封没发出去的 OTP 信会被记成成功
     */
    public function send(MailRequest $request): void;
}
