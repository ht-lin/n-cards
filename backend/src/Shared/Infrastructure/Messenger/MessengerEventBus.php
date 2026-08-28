<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messenger;

use App\Shared\Application\Bus\EventBusInterface;
use App\Shared\Domain\Event\DomainEvent;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * {@see EventBusInterface} 的 Messenger 实现。
 *
 * M0 阶段 config/packages/messenger.yaml 把 event.bus 全部路由到 `sync://`，
 * 所以「发布」此刻就是同步调用订阅者。T-1xx 接上 async transport 之后
 * 这里一行都不用改 —— 这正是把总线抽象出来的意义。
 */
final readonly class MessengerEventBus implements EventBusInterface
{
    public function __construct(private MessageBusInterface $eventBus)
    {
    }

    public function publish(DomainEvent ...$events): void
    {
        foreach ($events as $event) {
            $this->eventBus->dispatch($event);
        }
    }
}
