<?php

declare(strict_types=1);

namespace App\Shared\Application\Bus;

/**
 * 命令总线（§12.2 的 `Shared/Application/CommandBusInterface.php`）。
 *
 * 控制器把请求翻译成一个 Command 对象丢进来，由对应的 Handler 执行。
 * 这是 §12.2 分层职责表里「Http/Controller 只负责反序列化请求、调用 Application、
 * 序列化响应」的落法 —— 控制器不认识 Handler，只认识 Command。
 *
 * ============================================================================
 * 为什么是自己的接口而不是直接注入 MessageBusInterface
 * ============================================================================
 * §4.2 规则 3 规定跨模块异步只能走 Domain Event。若各模块直接注入 Messenger 的
 * `MessageBusInterface`，任何人都能随手 dispatch 任意消息到任意 transport，
 * 那条规则就没有强制点了。deptrac 里 `Framework.Messaging` 图层只对
 * `*.Infrastructure` 开放，Application 层只能看到本接口 —— 实现
 * `Shared\Infrastructure\Messenger\MessengerCommandBus` 是唯一碰 Messenger 的地方。
 *
 * 顺带的好处：换掉 Messenger（或在测试里换成同步直调）不需要动任何模块代码。
 */
interface CommandBusInterface
{
    /**
     * 同步派发一个命令，返回 Handler 的返回值。
     *
     * 命令总线**恒同步**：命令是「请你做这件事」，调用方通常要拿结果构造响应。
     * 需要异步的是事件（「这件事已经发生了」）—— 见 {@see EventBusInterface}。
     *
     * @return mixed Handler 的返回值；没有返回值的 Handler 给 null
     */
    public function dispatch(object $command): mixed;
}
