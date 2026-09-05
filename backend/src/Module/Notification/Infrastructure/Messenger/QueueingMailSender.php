<?php

declare(strict_types=1);

namespace App\Module\Notification\Infrastructure\Messenger;

use App\Module\Notification\Application\Dto\MailRequest;
use App\Module\Notification\Application\Port\MailSenderInterface;
use App\Module\Notification\Application\SendMailCommand;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * {@see MailSenderInterface} 的实现：入队即返回。
 *
 * ============================================================================
 * ⚠️ 这是仓库里第三处 import `Symfony\Component\Messenger\*` 的地方
 * ============================================================================
 * 前两处是 `Shared\Infrastructure\Messenger\{MessengerCommandBus,MessengerEventBus}`，
 * 它们的类注释在 T-004 时写的是「全仓库唯一」——T-102 起那句话已经更新过了。
 *
 * 这里不复用 `Shared\Application\Bus\CommandBusInterface` 有一个硬理由：
 * 它的实现用 `HandleTrait`，要求同步拿到 `HandledStamp`，
 * 路由到异步 transport 会直接抛「Message was handled zero times」。
 * 而它的接口注释白纸黑字写着「命令总线**恒同步**」——
 * 在那里开一个异步例外会把那句话变成假的，且下一个人不会知道。
 *
 * 所以走一条专门的 `mail.bus`（见 config/packages/messenger.yaml）。
 * deptrac 允许这次 import：`Notification.Infrastructure` 的允许列表里有
 * `Framework.Messaging`。
 *
 * ============================================================================
 * 为什么这里什么都不做
 * ============================================================================
 * 没有熔断判定、没有指标、没有渲染 —— 全在 worker 侧的
 * {@see \App\Module\Notification\Application\SendMailHandler} 里。
 *
 * 这不是偷懒。T-103 的验收标准要求「已注册 vs 未注册邮箱的**耗时分布**不可区分」，
 * 而 decoy 路径根本不会调到这里。所以这个方法多做一件事，
 * 两条路径的耗时差就多一点 —— 包括「读一次 Redis 计数器」这种看起来很便宜的事。
 * 让它退化成一次 INSERT 是防枚举的一部分。
 */
final readonly class QueueingMailSender implements MailSenderInterface
{
    public function __construct(private MessageBusInterface $mailBus)
    {
    }

    public function send(MailRequest $request): void
    {
        $this->mailBus->dispatch(SendMailCommand::fromRequest($request));
    }
}
