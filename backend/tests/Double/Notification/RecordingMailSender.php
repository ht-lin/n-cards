<?php

declare(strict_types=1);

namespace App\Tests\Double\Notification;

use App\Module\Notification\Application\Dto\MailRequest;
use App\Module\Notification\Application\Port\MailSenderInterface;

/**
 * 记下每一封被交出去的信，不真入队。
 *
 * T-102 的 QueuedMailPipelineTest 走的是真 Doctrine transport（它要验的就是
 * 「队列里没有明文」），本替身是给**调用方**用的：T-103 要断言的是
 * 「未注册邮箱一封都不发」，而那条断言不该依赖 compose 栈起没起。
 */
final class RecordingMailSender implements MailSenderInterface
{
    /** @var list<MailRequest> */
    private array $sent = [];

    public function send(MailRequest $request): void
    {
        $this->sent[] = $request;
    }

    /**
     * @return list<MailRequest>
     */
    public function sent(): array
    {
        return $this->sent;
    }

    public function count(): int
    {
        return \count($this->sent);
    }

    /**
     * 唯一一封信；不是恰好一封时抛 —— 用例写 `->only()->variables` 比
     * `->sent()[0]` 多一道保险，且失败信息说得清是几封。
     */
    public function only(): MailRequest
    {
        if (1 !== \count($this->sent)) {
            throw new \LogicException(\sprintf('Expected exactly one mail, got %d.', \count($this->sent)));
        }

        return $this->sent[0];
    }

    public function reset(): void
    {
        $this->sent = [];
    }
}
