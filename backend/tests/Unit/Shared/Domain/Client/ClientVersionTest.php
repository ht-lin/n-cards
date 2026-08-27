<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Domain\Client;

use App\Shared\Domain\Client\ClientVersion;
use App\Shared\Domain\Error\DomainException;
use App\Shared\Domain\Error\ErrorCode;
use App\Shared\Domain\Error\FieldErrorCode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ClientVersion::class)]
final class ClientVersionTest extends TestCase
{
    public function testParsesTheSpecExample(): void
    {
        $v = ClientVersion::parse('android/1.4.0 (26)');

        self::assertSame('android', $v->platform);
        self::assertSame(1, $v->major);
        self::assertSame(4, $v->minor);
        self::assertSame(0, $v->patch);
        self::assertSame(26, $v->build);
    }

    public function testToleratesSurroundingAndInternalWhitespace(): void
    {
        self::assertSame('android/1.4.0 (26)', (string) ClientVersion::parse('  android/1.4.0   (26)  '));
    }

    public function testRendersTheCanonicalForm(): void
    {
        // §14.4 的指标标签按这个字符串切分 —— 同一个版本必须只有一种形态。
        self::assertSame('android/1.4.0 (26)', (string) ClientVersion::parse('android/1.4.0(26)'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedHeaders(): iterable
    {
        yield 'empty' => [''];
        yield 'platform only' => ['android'];
        yield 'no build' => ['android/1.4.0'];
        yield 'empty build' => ['android/1.4.0 ()'];
        yield 'two-part version' => ['android/1.4 (26)'];
        yield 'four-part version' => ['android/1.4.0.1 (26)'];
        yield 'non-numeric build' => ['android/1.4.0 (beta)'];
        yield 'uppercase platform' => ['Android/1.4.0 (26)'];
        yield 'numeric platform' => ['123/1.4.0 (26)'];
        yield 'negative version' => ['android/-1.4.0 (26)'];
        yield 'unclosed paren' => ['android/1.4.0 (26'];
        yield 'trailing garbage' => ['android/1.4.0 (26) extra'];
        yield 'version too long' => ['android/12345.4.0 (26)'];
    }

    /**
     * §13.6 允许事后放宽校验、永远禁止事后收紧 —— 所以现在按完整格式从严收。
     */
    #[DataProvider('malformedHeaders')]
    public function testRejectsMalformedHeaders(string $header): void
    {
        try {
            ClientVersion::parse($header);
            self::fail('应该抛 DomainException：'.$header);
        } catch (DomainException $e) {
            self::assertSame(ErrorCode::ValidationFailed, $e->errorCode());
            self::assertCount(1, $e->fieldErrors());
            self::assertSame('X-Client', $e->fieldErrors()[0]->field);
            self::assertSame(FieldErrorCode::InvalidFormat, $e->fieldErrors()[0]->code);
        }
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function comparisons(): iterable
    {
        yield 'equal' => ['android/1.4.0 (26)', 'android/1.4.0 (26)', true];
        yield 'higher patch' => ['android/1.4.1 (26)', 'android/1.4.0 (26)', true];
        yield 'lower patch' => ['android/1.4.0 (26)', 'android/1.4.1 (26)', false];
        yield 'higher minor' => ['android/1.5.0 (26)', 'android/1.4.9 (99)', true];
        yield 'higher major' => ['android/2.0.0 (1)', 'android/1.99.99 (999)', true];
        yield 'lower major' => ['android/1.99.99 (999)', 'android/2.0.0 (1)', false];
        yield 'same version higher build' => ['android/1.4.0 (27)', 'android/1.4.0 (26)', true];
        yield 'same version lower build' => ['android/1.4.0 (25)', 'android/1.4.0 (26)', false];
        // 数字比较而非字符串比较：'10' < '9' 是字符串序的陷阱。
        yield 'double-digit minor beats single' => ['android/1.10.0 (1)', 'android/1.9.0 (1)', true];
    }

    #[DataProvider('comparisons')]
    public function testIsAtLeast(string $subject, string $minimum, bool $expected): void
    {
        self::assertSame(
            $expected,
            ClientVersion::parse($subject)->isAtLeast(ClientVersion::parse($minimum)),
        );
    }

    /**
     * 平台是维度而不是顺序 —— 「android 1.4.0 ≥ ios 1.2.0」这个问题本身没有意义，
     * 所以比较刻意忽略 platform。二期加 iOS 时改成按平台各配一条最低版本。
     */
    public function testComparisonIgnoresPlatform(): void
    {
        self::assertTrue(
            ClientVersion::parse('android/1.4.0 (26)')->isAtLeast(ClientVersion::parse('ios/1.4.0 (26)')),
        );
    }
}
