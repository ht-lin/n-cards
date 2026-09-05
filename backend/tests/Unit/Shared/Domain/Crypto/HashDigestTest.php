<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Domain\Crypto;

use App\Shared\Domain\Crypto\CryptoFailed;
use App\Shared\Domain\Crypto\HashDigest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(HashDigest::class)]
final class HashDigestTest extends TestCase
{
    /** 一条真实形态的 32 字节摘要。 */
    private const RAW = "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0a\x0b\x0c\x0d\x0e\x0f"
        ."\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1a\x1b\x1c\x1d\x1e\xff";

    public function testAcceptsExactlyThirtyTwoRawBytes(): void
    {
        $digest = HashDigest::fromRaw(self::RAW);

        self::assertSame(self::RAW, $digest->toRaw());
    }

    /**
     * 摘要里出现 0x00 是常态（概率 ≈ 12%），不能被当成字符串结尾截断。
     */
    public function testKeepsNullBytesIntact(): void
    {
        $digest = HashDigest::fromRaw(self::RAW);

        self::assertSame(HashDigest::BYTES, \strlen($digest->toRaw()));
        self::assertSame("\x00", $digest->toRaw()[0]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badLengths(): iterable
    {
        yield '空串' => [''];
        yield '短一字节' => [str_repeat("\x41", 31)];
        yield '长一字节' => [str_repeat("\x41", 33)];
        // 这一条是本类存在的首要理由：hash_hmac() 不传 $binary = true 时返回
        // 64 个 hex 字符，长度「看起来也像摘要」，写进 BYTEA 不会报任何错。
        yield 'hex 形态（64 字符）' => [bin2hex(self::RAW)];
    }

    #[DataProvider('badLengths')]
    public function testRejectsAnythingThatIsNotThirtyTwoBytes(string $bytes): void
    {
        $this->expectException(CryptoFailed::class);

        HashDigest::fromRaw($bytes);
    }

    #[DataProvider('badLengths')]
    public function testTryFromRawReturnsNullInsteadOfThrowing(string $bytes): void
    {
        self::assertNull(HashDigest::tryFromRaw($bytes));
    }

    /**
     * ⚠️ 失败最典型的成因是「有人把明文/hex 当摘要传了进来」，
     * 而 detail 会进日志与 Sentry。异常消息里只能有长度，不能有原值。
     */
    public function testExceptionMessageNeverLeaksTheInput(): void
    {
        $secret = 'anna.mueller@example.de';

        try {
            HashDigest::fromRaw($secret);
            self::fail('Expected CryptoFailed.');
        } catch (CryptoFailed $e) {
            self::assertStringNotContainsString($secret, $e->getMessage());
            self::assertStringNotContainsString($secret, $e->detail());
            // 长度是排查这条故障需要的全部信息，且不泄露原文。
            self::assertStringContainsString((string) \strlen($secret), $e->getMessage());
        }
    }

    public function testEqualsIsTrueForTheSameBytes(): void
    {
        self::assertTrue(
            HashDigest::fromRaw(self::RAW)->equals(HashDigest::fromRaw(self::RAW)),
        );
    }

    /**
     * 只差最后一个字节 —— 用 `===` 也能判出来，这条测的是结果不是耗时。
     * 常量时间本身由 `hash_equals` 保证（见 {@see HashDigest::equals()} 的注释），
     * 单测无法可靠地断言时序，所以这里只钉住「比较走的是等值语义」。
     */
    public function testEqualsIsFalseForDifferentBytes(): void
    {
        $other = substr(self::RAW, 0, 31)."\x00";

        self::assertFalse(
            HashDigest::fromRaw(self::RAW)->equals(HashDigest::fromRaw($other)),
        );
    }
}
