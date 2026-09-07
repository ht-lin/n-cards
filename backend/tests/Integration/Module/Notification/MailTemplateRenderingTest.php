<?php

declare(strict_types=1);

namespace App\Tests\Integration\Module\Notification;

use App\Module\Notification\Application\Dto\MailLocale;
use App\Module\Notification\Application\Dto\MailTemplate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

/**
 * §13.8 DoD 的「**德语 + 英语文案齐全**（无 MissingTranslation）」在后端的落点。
 *
 * ============================================================================
 * 这条用例在守什么
 * ============================================================================
 * Android 侧有 lint 的 `MissingTranslation` 兜底，后端没有等价物 ——
 * 一份漏掉的德语模板不会让任何东西报错，它会在**用户点了「发送验证码」之后**
 * 才变成一个 worker 里的 TemplateNotFoundException。而那时候：
 *   - 用户看到的是「码一直不来」；
 *   - 消息重投三次进 `email_failed`；
 *   - 唯一的线索是 §14.4 的 `email_send_total{result="failed"}` 在涨。
 *
 * 穷举 4 封信 × 2 种语言 × 3 份模板（主题 / 纯文本 / HTML）= 24 次渲染，
 * 把那个错误提前到 CI。
 *
 * 顺带守住第二件事：`MailTemplate::requiredVariables()` 与模板里实际用到的
 * 变量必须同步。`config/packages/twig.yaml` 把 `strict_variables` 在三个环境
 * 都打开了，所以清单少一个变量 → 渲染直接抛异常，这里就会红。
 */
#[CoversClass(MailTemplate::class)]
#[CoversClass(MailLocale::class)]
final class MailTemplateRenderingTest extends KernelTestCase
{
    /**
     * @return iterable<string, array{MailTemplate, MailLocale}>
     */
    public static function everyTemplateInEveryLocale(): iterable
    {
        foreach (MailTemplate::cases() as $template) {
            foreach (MailLocale::cases() as $locale) {
                yield $locale->value.'/'.$template->value => [$template, $locale];
            }
        }
    }

    #[DataProvider('everyTemplateInEveryLocale')]
    public function testAllThreePartsRender(MailTemplate $template, MailLocale $locale): void
    {
        $twig = self::twig();
        $variables = self::sampleVariables($template);

        foreach (['subject.txt', 'txt', 'html'] as $part) {
            $rendered = $twig->render(
                \sprintf('email/%s/%s.%s.twig', $locale->directory(), $template->basename(), $part),
                $variables,
            );

            self::assertNotSame('', trim($rendered), \sprintf(
                '%s/%s.%s.twig 渲染出了空内容。',
                $locale->directory(),
                $template->basename(),
                $part,
            ));
        }
    }

    /**
     * 每个声明的变量都必须真的出现在正文里。
     *
     * 只断言「渲染不报错」是不够的：`requiredVariables()` 里多列一个模板根本
     * 没用到的变量时，渲染照样成功，而调用方（T-103/T-104）会被迫构造一个
     * 没有意义的值。反过来漏列的那一侧由 strict_variables 兜。
     *
     * ⚠️ 只查纯文本那一份。HTML 里 URL 会被 Twig 转义（`&` → `&amp;`），
     * 主题行则本来就只用得上一两个变量。
     */
    #[DataProvider('everyTemplateInEveryLocale')]
    public function testEveryDeclaredVariableIsActuallyUsed(MailTemplate $template, MailLocale $locale): void
    {
        $variables = self::sampleVariables($template);

        $rendered = self::twig()->render(
            \sprintf('email/%s/%s.txt.twig', $locale->directory(), $template->basename()),
            $variables,
        );

        foreach ($variables as $name => $value) {
            self::assertStringContainsString($value, $rendered, \sprintf(
                '模板 %s/%s.txt.twig 没有用到 requiredVariables() 声明的 "%s"。',
                $locale->directory(),
                $template->basename(),
                $name,
            ));
        }
    }

    /**
     * OTP 码那封信必须把码本身放进主题行。
     *
     * 不是审美要求：Android 与 iOS 的短信/邮件自动填充都从主题或正文的开头
     * 抓验证码，而 North Star 场景（§1）对「掏出手机到完成」的耐心极低。
     */
    public function testOtpSubjectCarriesTheCode(): void
    {
        foreach (MailLocale::cases() as $locale) {
            $subject = self::twig()->render(
                \sprintf('email/%s/otp_code.subject.txt.twig', $locale->directory()),
                self::sampleVariables(MailTemplate::OtpCode),
            );

            self::assertStringContainsString('481502', $subject);
        }
    }

    /**
     * 每个变量给一个**可辨识**的值，好让上面那条「有没有真用上」的断言可靠。
     * 用 'x' 之类的短值会与模板里的普通文字撞上。
     *
     * @return array<string, string>
     */
    private static function sampleVariables(MailTemplate $template): array
    {
        $samples = [
            'code' => '481502',
            'expires_in_minutes' => '10',
            'magic_link_url' => 'https://app.n-cards.de/l/magic/MAGICTOKENSAMPLE',
            'device_model' => 'Pixel 7a',
            'occurred_at' => '2026-09-05 18:30 UTC',
            'approximate_region' => 'Bayern, DE',
            'revoke_url' => 'https://app.n-cards.de/l/revoke/REVOKETOKENSAMPLE',
            'support_url' => 'https://n-cards.de/hilfe/sicherheit',
        ];

        $variables = [];

        foreach ($template->requiredVariables() as $name) {
            self::assertArrayHasKey($name, $samples, \sprintf(
                'requiredVariables() 声明了 "%s"，但本用例没有给它样例值 —— 补一个。',
                $name,
            ));

            $variables[$name] = $samples[$name];
        }

        return $variables;
    }

    private static function twig(): Environment
    {
        self::bootKernel();

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');

        return $twig;
    }
}
