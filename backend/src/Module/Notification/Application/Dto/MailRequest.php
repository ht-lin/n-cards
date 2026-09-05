<?php

declare(strict_types=1);

namespace App\Module\Notification\Application\Dto;

use App\Shared\Domain\Crypto\Ciphertext;

/**
 * 「给这个人按这个模板发一封信」——{@see \App\Module\Notification\Application\Port\MailSenderInterface} 的唯一入参。
 *
 * ============================================================================
 * ⚠️ 收件人是 `Ciphertext`，不是 string
 * ============================================================================
 * 这是本模块最重要的一条类型约束，理由和 T-101 让 `users.email_encrypted`
 * 用 {@see Ciphertext} 而不是裸 string 完全一样，只是这里的**爆炸半径更大**：
 *
 *   - §3.8「users 不存明文邮箱列」的配套是「明文邮箱在系统里活得越短越好」。
 *     若这里收 string，调用方（T-103）就得先 decrypt 一次再传进来，
 *     于是明文地址会穿过 Identity.Application → Notification.Port → Messenger
 *     整条链路，还会被序列化进 `messenger_messages`。
 *   - 收 Ciphertext 的话，T-101 存的 `User::emailEncrypted()` 可以**原样**递进来，
 *     全程不解密；解密发生在 `SymfonyMailerTransport::send()` 里、构造 MIME
 *     消息的前一行，作用域是一个方法体。
 *   - 未注册邮箱（T-103 的 decoy 路径没有 users 行）由调用方自己
 *     `CryptoServiceInterface::encrypt(CryptoKey::Pii, …)` 一次 —— 那一次加密
 *     本来就要做，因为 §3.8 要求 decoy 路径与真实路径的耗时不可区分。
 *
 * 顺带：`Ciphertext` 的 `__toString()` 给出的是 `vault:v1:…`，
 * 所以就算有人不小心把整个 DTO 丢进日志，出来的也是密文。
 *
 * ============================================================================
 * `variables` 里**会**有密级最高的东西
 * ============================================================================
 * OTP 码就在这里（`['code' => '123456']`）。§7.1 规定它不存明文 ——
 * 落地点是 transport 级的 {@see \App\Module\Notification\Infrastructure\Messenger\EncryptedMailSerializer}：
 * 整条消息体经 Vault Transit 加密后才写进 `messenger_messages`。
 *
 * 值一律是 string：模板里要用的日期、时长都由调用方先格式化好。
 * 让 DTO 携带 `DateTimeImmutable` 会把「用哪个时区、哪种格式」的决定推给模板，
 * 而那个决定与 locale 有关，属于调用方的上下文。
 */
final readonly class MailRequest
{
    /**
     * @param array<string, string> $variables 必须**恰好**覆盖
     *                                         {@see MailTemplate::requiredVariables()}
     *
     * @throws \InvalidArgumentException 变量缺失或多余。这是**编程错误**而不是运行时状况，
     *                                   调用方不该 catch —— 见下方对「为什么在这里炸」的说明
     */
    public function __construct(
        public MailTemplate $template,
        public MailLocale $locale,
        public Ciphertext $recipient,
        public array $variables = [],
    ) {
        $required = $this->template->requiredVariables();
        $provided = array_keys($this->variables);

        sort($required);
        sort($provided);

        if ($required !== $provided) {
            // 在**入队前**炸，而不是等 worker 渲染时被 strict_variables 拦下。
            //
            // 后者也会失败，但那时消息已经在 `messenger_messages` 里，重试三次
            // 全部失败后进 `email_failed`，用户看到的是「码一直不来」。
            // 在这里炸的话，异常的堆栈直接指向调用方写错的那一行。
            //
            // 「多余的变量」同样拒绝：多出来的键说明调用方与模板对同一封信的
            // 理解已经分叉，静默忽略会让那个分叉一直活着。
            throw new \InvalidArgumentException(\sprintf('模板 "%s" 需要的变量是 [%s]，收到的是 [%s]。两者必须完全一致，检查 MailTemplate::requiredVariables() 与调用方。', $this->template->value, implode(', ', $required), implode(', ', $provided)));
        }
    }
}
