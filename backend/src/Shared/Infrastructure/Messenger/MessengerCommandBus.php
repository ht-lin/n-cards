<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messenger;

use App\Shared\Application\Bus\CommandBusInterface;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\HandleTrait;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * {@see CommandBusInterface} 的 Messenger 实现。
 *
 * 这个类（连同 {@see MessengerEventBus}）是**全仓库唯一**允许 import
 * `Symfony\Component\Messenger\*` 的地方 —— deptrac 的 `Framework.Messaging` 图层
 * 只对 `*.Infrastructure` 开放。理由见 CommandBusInterface 的类注释。
 */
final class MessengerCommandBus implements CommandBusInterface
{
    use HandleTrait;

    public function __construct(MessageBusInterface $commandBus)
    {
        // HandleTrait 约定属性名就叫 $messageBus。
        $this->messageBus = $commandBus;
    }

    public function dispatch(object $command): mixed
    {
        try {
            return $this->handle($command);
        } catch (HandlerFailedException $e) {
            // Messenger 把 Handler 抛的异常包进 HandlerFailedException。
            // 若不拆包，业务代码抛的 DomainException 就到不了
            // ApiProblemExceptionListener 手里 —— 每一个 409 revision_conflict
            // 都会变成 500 internal_error。
            //
            // 只拆第一个：命令总线是同步单 Handler，不存在多个失败的情况。
            throw $e->getPrevious() ?? $e;
        }
    }
}
