<?php

declare(strict_types=1);

namespace App\Shared\Application\Bus;

use App\Shared\Domain\Event\DomainEvent;

/**
 * 领域事件总线（§12.2 的 `Shared/Application/EventBusInterface.php`）。
 *
 * §4.2 规则 3：**跨模块异步通知只能通过 Domain Event**（Messenger 的 async transport）。
 * 已知用例见 §4.2 的协作点表 —— 例如 `Sharing → Notification` 的
 * `CardSharedWith` / `MemberRemoved`：推送失败不应回滚业务事务，所以必须异步。
 *
 * ============================================================================
 * ⚠️ 事务边界：先提交，再发事件
 * ============================================================================
 * 发布本身是异步的，但**发布这个动作**发生在调用的那一刻。若在
 * {@see \App\Shared\Application\Transaction\TransactionRunnerInterface::run()} 内部
 * 发事件、而事务随后回滚，消费方就会收到一个「从未发生过的事」的通知 ——
 * 推送已经发出去了，撤不回来。
 *
 * 所以：**在事务提交之后**再 publish。M0 阶段全部路由到 `sync://`，这个坑还不会显形；
 * T-1xx 接上 async transport 之后它就是真的了。
 *
 * 唯一的例外是 §4.2 点名的那条强制同事务协作（Social 解除好友 → Sharing 级联撤销），
 * 而它按规格走的是**同步 Port 调用**，不是事件 —— 正是为了避开这个问题。
 */
interface EventBusInterface
{
    /**
     * 发布一个或多个领域事件。
     *
     * 变参而不是数组：绝大多数调用点只发一个事件，`publish($event)` 比
     * `publish([$event])` 干净；需要批量时 `publish(...$events)` 也照用。
     */
    public function publish(DomainEvent ...$events): void;
}
