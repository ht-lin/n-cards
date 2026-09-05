<?php

declare(strict_types=1);

namespace App\Module\Notification\Infrastructure\Mail;

use App\Module\Notification\Application\Dto\MailRequest;
use App\Module\Notification\Application\Port\MailTransportInterface;
use App\Shared\Application\Crypto\CryptoServiceInterface;
use App\Shared\Domain\Crypto\CryptoKey;
use Symfony\Bridge\Twig\Mime\BodyRenderer;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Twig\Environment;

/**
 * {@see MailTransportInterface} 的实现 —— **全仓库唯一**碰邮箱服务商的地方（§3.2）。
 *
 * ============================================================================
 * 这个类是那道抽象墙本身
 * ============================================================================
 * §3.2 允许一期只用单通道、不做双活，前提是「未来 2 人日能补回来」，
 * 而那句话成立的唯一原因是：换服务商 = 改 `MAILER_DSN` 一个环境变量，
 * 本类以上的每一行代码都不用动。
 *
 * 所以这里**不允许**出现任何服务商特有的东西：没有服务商 SDK、没有它们的自定义
 * header、没有按服务商分支的 if。真需要那种东西时，说明选型本身选错了
 * （Q3 的三条硬要求之一就是「走标准 SMTP 或 API 均可」）。
 *
 * Q3 已决（ADR-0013）：通道是 `n-cards.de` 的域名邮箱（dogado GmbH），
 * 标准 SMTP submission。**换选型时本文件一行都没改** —— 这道墙的第一次真实检验。
 *
 * deptrac 兜底：`Framework.Mail` 与 `Framework.Templating` 两层只加进了
 * `Notification.Infrastructure` 的允许列表，别的地方 import `MailerInterface`
 * 会直接是 violation。
 *
 * ============================================================================
 * 明文邮箱地址的作用域 = 这一个方法体
 * ============================================================================
 * 入参里的收件人是 `Ciphertext`（一路从 `users.email_encrypted` 原样传下来，
 * 见 {@see MailRequest} 的类注释），在 {@see send()} 里解密一次，
 * 交给 `Address` 之后就不再有别的引用。
 *
 * ⚠️ 因此**绝不要**在本类里 log 收件人。`PiiRedactionProcessor` 会把 context
 * 里叫 `email` 的键换成 `[REDACTED]`，但它救不了拼进 message 字符串的值。
 */
final readonly class SymfonyMailerTransport implements MailTransportInterface
{
    public function __construct(
        private MailerInterface $mailer,
        private Environment $twig,
        private CryptoServiceInterface $crypto,
        private string $fromAddress,
        private string $fromName,
    ) {
    }

    public function send(MailRequest $request): void
    {
        $recipient = $this->crypto->decrypt(CryptoKey::Pii, $request->recipient);

        $directory = $request->locale->directory();
        $basename = $request->template->basename();

        // 主题单独渲染成一份模板，而不是放在 PHP 常量里。
        // 理由见 MailTemplate 的类注释：DoD 的「德语 + 英语文案齐全」
        // 需要一个能被穷举测试的单一位置，而主题行也是文案。
        $subject = trim($this->twig->render(
            \sprintf('email/%s/%s.subject.txt.twig', $directory, $basename),
            $request->variables,
        ));

        $email = (new TemplatedEmail())
            ->from(new Address($this->fromAddress, $this->fromName))
            ->to(new Address($recipient))
            ->subject($subject)
            ->textTemplate(\sprintf('email/%s/%s.txt.twig', $directory, $basename))
            ->htmlTemplate(\sprintf('email/%s/%s.html.twig', $directory, $basename))
            ->context($request->variables);

        // ⚠️ 显式渲染，**不依赖** `mailer.messenger_transport_listener` 那条自动路径。
        //
        // Symfony 只在「TemplatedEmail 经由 message_bus 异步发送」时才会自动
        // 调用 BodyRenderer。而我们刻意**没有**配 `framework.mailer.message_bus`
        // （理由见 config/packages/mailer.yaml：那条会把含明文 OTP 码的整封 MIME
        // 丢进队列）。不自己渲染的话，发出去的信正文是空的 —— 而且
        // `MailerInterface::send()` 不会报错，是一封静默发出的空信。
        (new BodyRenderer($this->twig))->render($email);

        $this->mailer->send($email);
    }
}
