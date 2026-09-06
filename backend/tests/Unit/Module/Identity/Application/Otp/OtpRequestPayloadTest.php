<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Identity\Application\Otp;

use App\Module\Identity\Application\Otp\OtpRequestPayload;
use App\Module\Identity\Domain\ValueObject\Locale;
use App\Shared\Domain\Error\DomainException;
use App\Shared\Domain\Error\ErrorCode;
use App\Shared\Domain\Error\FieldErrorCode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(OtpRequestPayload::class)]
final class OtpRequestPayloadTest extends TestCase
{
    public function testAcceptsAWellFormedBody(): void
    {
        $payload = OtpRequestPayload::fromArray(['email' => 'anna@example.de', 'locale' => 'de']);

        self::assertSame('anna@example.de', $payload->email);
        self::assertSame(Locale::German, $payload->locale);
    }

    /**
     * §3.8 / §5.2：归一化必须发生在算 email_hash **之前**，否则同一个人能注册两次。
     * 这条用例是那个不变量在校验层的锚点。
     */
    #[DataProvider('equivalentSpellings')]
    public function testNormalisesTheEmailBeforeItIsEverHashed(string $input): void
    {
        self::assertSame('anna@example.de', OtpRequestPayload::fromArray(['email' => $input, 'locale' => 'de'])->email);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function equivalentSpellings(): iterable
    {
        yield 'already normalised' => ['anna@example.de'];
        yield 'uppercase' => ['ANNA@EXAMPLE.DE'];
        yield 'mixed case' => ['Anna@Example.De'];
        yield 'leading and trailing space' => ['  anna@example.de  '];
        yield 'tab and newline' => ["\tanna@example.de\n"];
        yield 'both' => [" Anna@EXAMPLE.de\t"];
    }

    public function testAcceptsEnglish(): void
    {
        self::assertSame(Locale::English, OtpRequestPayload::fromArray(['email' => 'anna@example.de', 'locale' => 'en'])->locale);
    }

    /**
     * @param array<string, mixed> $body
     */
    #[DataProvider('invalidBodies')]
    public function testRejects(array $body, string $field, FieldErrorCode $code): void
    {
        try {
            OtpRequestPayload::fromArray($body);
            self::fail('Expected a validation_failed DomainException.');
        } catch (DomainException $exception) {
            self::assertSame(ErrorCode::ValidationFailed, $exception->errorCode());

            $errors = $exception->fieldErrors();
            self::assertCount(1, $errors, 'This body has exactly one problem.');

            $error = $errors[0];
            self::assertSame($field, $error->field);
            self::assertSame($code, $error->code);
        }
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string, FieldErrorCode}>
     */
    public static function invalidBodies(): iterable
    {
        yield 'email missing' => [['locale' => 'de'], 'email', FieldErrorCode::Required];
        yield 'email null' => [['email' => null, 'locale' => 'de'], 'email', FieldErrorCode::Required];
        yield 'email is a number' => [['email' => 42, 'locale' => 'de'], 'email', FieldErrorCode::InvalidType];
        yield 'email is an array' => [['email' => ['a@b.de'], 'locale' => 'de'], 'email', FieldErrorCode::InvalidType];
        yield 'email is not an address' => [['email' => 'not-an-address', 'locale' => 'de'], 'email', FieldErrorCode::InvalidFormat];
        yield 'email is empty' => [['email' => '', 'locale' => 'de'], 'email', FieldErrorCode::InvalidFormat];
        yield 'email is only whitespace' => [['email' => '   ', 'locale' => 'de'], 'email', FieldErrorCode::InvalidFormat];

        yield 'locale missing' => [['email' => 'anna@example.de'], 'locale', FieldErrorCode::Required];
        yield 'locale null' => [['email' => 'anna@example.de', 'locale' => null], 'locale', FieldErrorCode::Required];
        yield 'locale is a number' => [['email' => 'anna@example.de', 'locale' => 1], 'locale', FieldErrorCode::InvalidType];
        yield 'locale not in the enum' => [['email' => 'anna@example.de', 'locale' => 'fr'], 'locale', FieldErrorCode::InvalidFormat];
        // 归一化只作用于 email。locale 由客户端从一个闭合列表里选，不是自由输入 ——
        // 悄悄接受 `DE` 会让契约的 `enum: [de, en]` 与实现不一致。
        yield 'locale is uppercase' => [['email' => 'anna@example.de', 'locale' => 'DE'], 'locale', FieldErrorCode::InvalidFormat];

        yield 'unknown field' => [['email' => 'anna@example.de', 'locale' => 'de', 'device_id' => 'x'], 'device_id', FieldErrorCode::UnknownField];
    }

    /**
     * 契约写 `maxLength: 254`。边界两侧都要测 —— 只测超限的话，
     * 一个 `>=` 写成 `>` 的 off-by-one 不会红。
     */
    public function testAcceptsAnEmailOfExactlyTheMaximumLength(): void
    {
        $email = self::emailOfLength(OtpRequestPayload::EMAIL_MAX_LENGTH);

        self::assertSame($email, OtpRequestPayload::fromArray(['email' => $email, 'locale' => 'de'])->email);
    }

    public function testRejectsAnEmailOneByteOverTheMaximum(): void
    {
        $email = self::emailOfLength(OtpRequestPayload::EMAIL_MAX_LENGTH + 1);

        $this->expectException(DomainException::class);

        try {
            OtpRequestPayload::fromArray(['email' => $email, 'locale' => 'de']);
        } catch (DomainException $exception) {
            $errors = $exception->fieldErrors();
            self::assertSame(FieldErrorCode::TooLong, $errors[0]->code);

            throw $exception;
        }
    }

    /**
     * 造一个恰好 $length 字节、且 `FILTER_VALIDATE_EMAIL` 认可的地址。
     *
     * ⚠️ 不能简单地 `str_repeat('a', N).'@example.de'`：RFC 5321 把 local part 限到
     * 64 字节，而 PHP 的过滤器**强制**这一条 —— 那样造出来的长地址会因为格式非法
     * 而被拒，于是这条本该验长度边界的用例实际上验的是别的东西。
     * 所以固定 64 字节的 local part，把长度差额摊到域名的几个 label 上（每个 ≤ 63）。
     */
    private static function emailOfLength(int $length): string
    {
        $local = str_repeat('a', 64);
        $domainLength = $length - \strlen($local) - 1;

        $labels = [];
        $remaining = $domainLength;

        while ($remaining > 63) {
            $labels[] = str_repeat('b', 63);
            // 减 63 个字符再减掉它后面那个点
            $remaining -= 64;
        }

        $labels[] = str_repeat('c', $remaining);

        $email = $local.'@'.implode('.', $labels);

        // 造错了就当场停，别让一条构造 bug 伪装成断言失败。
        self::assertSame($length, \strlen($email), 'The fixture builder produced the wrong length.');

        return $email;
    }

    /**
     * §6.1：客户端要一次拿到全部字段错误才能一次性把表单标红。
     * 遇到第一个就抛的实现会让用户改一个提交一次。
     */
    public function testReportsEveryProblemInOneResponse(): void
    {
        try {
            OtpRequestPayload::fromArray(['email' => 'nope', 'locale' => 'fr', 'extra' => 1]);
            self::fail('Expected a validation_failed DomainException.');
        } catch (DomainException $exception) {
            $fields = array_map(static fn ($error): string => $error->field, $exception->fieldErrors());

            sort($fields);
            self::assertSame(['email', 'extra', 'locale'], $fields);
        }
    }

    /**
     * ⚠️ 报错文案里绝不能回显邮箱：detail 与 errors[].message 都会进日志与 Sentry，
     * 而 PiiRedactionProcessor 只兜结构化 context 里叫 `email` 的键，兜不住这里。
     */
    public function testNeverEchoesTheSubmittedEmail(): void
    {
        $email = 'leaky-canary@example.de';

        try {
            OtpRequestPayload::fromArray(['email' => $email.' not an address', 'locale' => 'de']);
            self::fail('Expected a validation_failed DomainException.');
        } catch (DomainException $exception) {
            $rendered = $exception->detail().json_encode($exception->fieldErrors(), \JSON_THROW_ON_ERROR);

            self::assertStringNotContainsString('leaky-canary', $rendered);
        }
    }

    /**
     * §6.1：`detail` / `message` 是**英文开发者文案**，服务端绝不返回可展示的德语。
     * 与 ApiProblemFactoryTest 的 ASCII-only 断言同一条约束。
     */
    public function testMessagesAreAsciiOnly(): void
    {
        try {
            OtpRequestPayload::fromArray(['email' => 'nope', 'locale' => 'fr', 'extra' => 1]);
            self::fail('Expected a validation_failed DomainException.');
        } catch (DomainException $exception) {
            foreach ($exception->fieldErrors() as $error) {
                self::assertSame($error->message, mb_convert_encoding($error->message, 'ASCII', 'UTF-8'));
            }
        }
    }
}
