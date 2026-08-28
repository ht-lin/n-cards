<?php

declare(strict_types=1);

namespace App\Shared\Domain\Event;

use App\Shared\Domain\Identity\Uuid;

/**
 * 领域事件（§12.2 的 `Shared/Domain/DomainEvent.php`）。
 *
 * §4.2 规则 3：**跨模块异步通知只能通过 Domain Event**（Symfony Messenger 的 async transport）。
 * 已知的用例见 §4.2 的协作点表 —— 例如 `Sharing → Notification` 的
 * `CardSharedWith` / `MemberRemoved`（推送失败不应回滚业务事务，所以必须异步）。
 *
 * 发布走 `Shared\Application\Bus\EventBusInterface`；实现类
 * `Shared\Infrastructure\Messenger\MessengerEventBus` 才碰 Messenger ——
 * 事件对象本身是纯 PHP，deptrac 的 `Shared.Domain: []` 不允许它认识任何总线。
 *
 * ⚠️ **`payload()` 只能放标量**。事件会被序列化进 Messenger transport，
 * 跨进程、可能跨版本地反序列化。塞实体进去等于把 Domain 对象的序列化格式
 * 变成一个隐式的、没人维护的契约。要什么字段就显式列什么字段。
 *
 * ⚠️ payload **绝不能**包含 `barcode_value`、`note`、`email` 等敏感字段（§14.4）——
 * transport 里的消息会被记录，`PiiRedactionProcessor` 只兜日志这一层。
 * 需要这些数据的消费方自己按 `aggregateId()` 回查。
 */
interface DomainEvent
{
    /**
     * 稳定的事件名，例如 `sharing.card_shared_with`。
     *
     * 这是**跨进程契约**：Messenger 用它路由，消费方用它分支。
     * 改名与改一个 API 错误码的含义是同一类破坏性变更（§13.6），不要改。
     */
    public function eventName(): string;

    /**
     * 事件所属聚合的 id（卡 id、用户 id……）。
     */
    public function aggregateId(): Uuid;

    /**
     * 事件发生时刻。**UTC**（§6.1）。
     *
     * 由发布方从 `ClockInterface` 取，不由消费方推断 —— 异步消费可能是几分钟后的事。
     */
    public function occurredAt(): \DateTimeImmutable;

    /**
     * 事件载荷。只放标量与 null，理由见类注释。
     *
     * @return array<string, scalar|null>
     */
    public function payload(): array;
}
