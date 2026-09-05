<?php

declare(strict_types=1);

namespace App\Module\Notification\Application;

use App\Module\Notification\Application\Dto\MailRequest;

/**
 * 放进 `email` transport 的那条消息（T-102）。
 *
 * ============================================================================
 * 为什么不直接把 `MailRequest` 丢进队列
 * ============================================================================
 * 两者字段一样，看起来是白加一层。区别在**生命周期**：
 *
 *   - `MailRequest` 是 Port 的入参，属于对外契约的一部分。
 *   - `SendMailCommand` 是**线上格式**（wire format）。一条消息可能在
 *     `messenger_messages` 里躺过一次部署 —— 入队的是旧版本代码，
 *     出队的是新版本。契约与线上格式合成一个类的话，任何一次对 Port 入参的
 *     修改都会静默地让在途消息解不出来。
 *
 * 分开之后，改 Port 入参不影响在途消息；真要改线上格式时，
 * {@see \App\Module\Notification\Infrastructure\Messenger\EncryptedMailSerializer}
 * 里有一个显式的 `VERSION` 常量可以分支。
 *
 * ============================================================================
 * ⚠️ 全标量 + 一个密文字符串，没有对象
 * ============================================================================
 * 序列化走 PHP 的 `serialize()`（见 EncryptedMailSerializer，它装饰的是
 * Messenger 自带的 PhpSerializer），所以技术上塞什么都能过。
 * 仍然只放标量，是因为这是**线上格式**：一条消息可能在 `messenger_messages`
 * 里躺过一次部署，入队与出队的是两个版本的代码。
 * 标量字段的兼容性判据一眼可见；一个值对象的构造函数在两个版本间改了签名
 * 的话，`unserialize()` 会给出一个绕过了构造函数的半成品对象，
 * 而那种损坏不会抛异常。
 *
 * `recipient` 存 `Ciphertext` 的字符串形式而不是对象，正是这条的应用。
 */
final readonly class SendMailCommand
{
    /**
     * ⚠️ 这四个字段**刻意不做校验**，类型也只是裸 `string`。
     *
     * 它们的取值域由 {@see Dto\MailTemplate} / {@see Dto\MailLocale} /
     * `Ciphertext` 在**入队那一侧**保证（`SendMailCommand::fromRequest()` 的入参
     * 是已经校验过的 {@see MailRequest}）。
     *
     * 出队那一侧则必须假定它们**可能非法** —— 队列里可能躺着上一个版本的代码
     * 入队的消息（ADR-0010：回滚只换镜像 tag，队列不清空）。所以
     * {@see SendMailHandler} 用的是 `tryFrom()` 而不是 `from()`，
     * 拿不回 enum 就记一行 error 并丢弃。
     *
     * 在这里标 `non-empty-string` 是一句谎话：构造函数不检查，
     * 而这个类的存在意义恰恰是「承载一段可能已经过时的数据」。
     *
     * @param array<string, string> $variables 模板变量。**OTP 码在这里** ——
     *                                         整条消息经 Vault Transit 加密后才落库，
     *                                         见 EncryptedMailSerializer
     */
    public function __construct(
        public string $template,
        public string $locale,
        public string $recipient,
        public array $variables,
    ) {
    }

    public static function fromRequest(MailRequest $request): self
    {
        return new self(
            $request->template->value,
            $request->locale->value,
            $request->recipient->toString(),
            $request->variables,
        );
    }
}
