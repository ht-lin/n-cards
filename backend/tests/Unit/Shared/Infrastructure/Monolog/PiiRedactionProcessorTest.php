<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Monolog;

use App\Shared\Infrastructure\Monolog\PiiRedactionProcessor;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * §14.4 的硬性要求：日志脱敏。
 *
 * 这是**安全**测试，不是整洁测试。日志是绕过 §5.3 服务端加密的旁路 ——
 * Loki 里没有 Vault，一条 `['barcode_value' => $plaintext]` 就是把会员卡号
 * 明文写进了一个 §8 的 ROPA 里没登记的存储。
 */
#[CoversClass(PiiRedactionProcessor::class)]
final class PiiRedactionProcessorTest extends TestCase
{
    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $extra
     */
    private static function process(array $context, array $extra = []): LogRecord
    {
        return (new PiiRedactionProcessor())(new LogRecord(
            new \DateTimeImmutable(),
            'app',
            Level::Info,
            'test',
            $context,
            $extra,
        ));
    }

    /**
     * §14.4 点名的那几个键。
     *
     * @return iterable<string, array{string}>
     */
    public static function alwaysRedactedKeys(): iterable
    {
        yield 'barcode_value' => ['barcode_value'];
        yield 'note' => ['note'];
        yield 'email' => ['email'];
        yield 'refresh_token' => ['refresh_token'];
        // 同类，一并纳入
        yield 'access_token' => ['access_token'];
        yield 'password' => ['password'];
        yield 'passphrase' => ['passphrase'];
        yield 'secret' => ['secret'];
        yield 'token' => ['token'];
        yield 'authorization' => ['authorization'];
    }

    #[DataProvider('alwaysRedactedKeys')]
    public function testRedactsSensitiveKeys(string $key): void
    {
        $record = self::process([$key => 'super-sensitive-value']);

        self::assertSame(PiiRedactionProcessor::REDACTED, $record->context[$key]);
    }

    #[DataProvider('alwaysRedactedKeys')]
    public function testKeyMatchingIsCaseInsensitive(string $key): void
    {
        $record = self::process([strtoupper($key) => 'super-sensitive-value']);

        self::assertSame(PiiRedactionProcessor::REDACTED, $record->context[strtoupper($key)]);
    }

    public function testRedactsInsideNestedArrays(): void
    {
        // grep 式的 CI 扫描（§13.3）拦不住整体传对象/数组的写法，这一层才行。
        $record = self::process([
            'user' => [
                'id' => '01941f29-7c00-70ab-8000-000000000000',
                'email' => 'anna@example.de',
                'devices' => [
                    ['model' => 'Pixel 7', 'refresh_token' => 'rt_secret'],
                ],
            ],
        ]);

        /** @var array<string, mixed> $user */
        $user = $record->context['user'];
        self::assertSame(PiiRedactionProcessor::REDACTED, $user['email']);
        self::assertSame('01941f29-7c00-70ab-8000-000000000000', $user['id'], 'id 不敏感，不该被抹掉');

        /** @var array<int, array<string, mixed>> $devices */
        $devices = $user['devices'];
        self::assertSame(PiiRedactionProcessor::REDACTED, $devices[0]['refresh_token']);
        self::assertSame('Pixel 7', $devices[0]['model']);
    }

    public function testRedactsExtraAsWellAsContext(): void
    {
        $record = self::process([], ['email' => 'anna@example.de']);

        self::assertSame(PiiRedactionProcessor::REDACTED, $record->extra['email']);
    }

    public function testLeavesInnocuousFieldsAlone(): void
    {
        $record = self::process([
            'request_id' => '01941f29-7c00-70ab-8000-000000000000',
            'route' => 'cards_list',
            'method' => 'GET',
            'duration_ms' => 12,
        ]);

        self::assertSame('cards_list', $record->context['route']);
        self::assertSame(12, $record->context['duration_ms']);
    }

    // ========================================================================
    // `code` 的取舍
    // ========================================================================

    /**
     * §14.4 要求脱敏 `code`，指的是 §7.1 的 **OTP 验证码**。
     * 但 `code` 同时是 §6.1 的**错误码**字段名 —— 而错误码恰恰是日志里最该留的东西。
     *
     * 所以按值的形状判：像 OTP（纯数字 4–10 位）才抹。
     */
    public function testRedactsOtpShapedCodes(): void
    {
        $record = self::process(['code' => '123456']);

        self::assertSame(PiiRedactionProcessor::REDACTED, $record->context['code']);
    }

    public function testKeepsErrorCodes(): void
    {
        $record = self::process(['code' => 'revision_conflict']);

        self::assertSame(
            'revision_conflict',
            $record->context['code'],
            '错误码被抹掉的话，ApiProblemExceptionListener 记的每条日志都失去了意义',
        );
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function codeValues(): iterable
    {
        yield '6-digit otp' => ['123456', true];
        yield '4-digit otp' => ['1234', true];
        yield '10-digit otp' => ['1234567890', true];
        yield 'error code' => ['validation_failed', false];
        yield 'too short to be an otp' => ['123', false];
        yield 'too long to be an otp' => ['12345678901', false];
        yield 'alphanumeric' => ['abc123', false];
    }

    #[DataProvider('codeValues')]
    public function testCodeRedactionFollowsValueShape(string $value, bool $shouldRedact): void
    {
        $record = self::process(['code' => $value]);

        self::assertSame(
            $shouldRedact ? PiiRedactionProcessor::REDACTED : $value,
            $record->context['code'],
        );
    }

    // ========================================================================
    // 健壮性
    // ========================================================================

    public function testHandlesEmptyContext(): void
    {
        self::assertSame([], self::process([])->context);
    }

    public function testStopsRecursingAtTheDepthLimit(): void
    {
        // 深到超过上限的结构不能让 processor 自己爆栈 —— 日志处理器炸掉会
        // 连带整个请求一起炸。
        $deep = 'leaf';

        for ($i = 0; $i < 20; ++$i) {
            $deep = ['nested' => $deep];
        }

        $record = self::process(['data' => $deep]);

        self::assertArrayHasKey('data', $record->context);
    }

    public function testPreservesNonArrayScalars(): void
    {
        $record = self::process(['count' => 42, 'ok' => true, 'ratio' => 1.5, 'nothing' => null]);

        self::assertSame(42, $record->context['count']);
        self::assertTrue($record->context['ok']);
        self::assertSame(1.5, $record->context['ratio']);
        self::assertNull($record->context['nothing']);
    }
}
