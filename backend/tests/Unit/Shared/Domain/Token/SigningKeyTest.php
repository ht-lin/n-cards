<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Domain\Token;

use App\Shared\Domain\Token\SigningKey;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(SigningKey::class)]
final class SigningKeyTest extends TestCase
{
    private const SEED = '0123456789abcdef0123456789abcdef';

    public function testAcceptsAThirtyTwoByteSeed(): void
    {
        $key = new SigningKey(self::SEED, 'kid-1');

        self::assertSame(self::SEED, $key->seed);
        self::assertSame('kid-1', $key->kid);
    }

    /**
     * 长度校验不是装饰：它是 `Ed25519AccessTokenSigner` 那边
     * `sodium_crypto_sign_seed_keypair()` 的 `non-empty-string` 前提的来源。
     * 放宽它，PHPStan level 8 会在签名器上当场红。
     */
    #[DataProvider('badSeeds')]
    public function testRejectsAnythingThatIsNotThirtyTwoBytes(string $seed): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new SigningKey($seed, 'kid-1');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badSeeds(): iterable
    {
        yield 'empty' => [''];
        yield 'one short' => [substr(self::SEED, 0, 31)];
        yield 'one long' => [self::SEED.'x'];
        yield 'a full ed25519 secret key (64 bytes)' => [str_repeat("\x01", 64)];
    }

    /**
     * 空 kid 会签出一个验签方无法选密钥的 token —— §5.3 的双密钥重叠期
     * 靠 kid 区分新旧，而那个失败只在轮换当天显形。
     */
    public function testRejectsAnEmptyKid(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new SigningKey(self::SEED, '');
    }

    /**
     * ⚠️ 异常文案里只能有长度，不能有密钥字节 —— 它会进日志。
     */
    public function testTheLengthErrorLeaksNoKeyMaterial(): void
    {
        try {
            new SigningKey('super-secret-but-too-short', 'kid-1');
            self::fail('Expected an InvalidArgumentException.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringNotContainsString('super-secret', $exception->getMessage());
            self::assertStringContainsString('32 bytes', $exception->getMessage());
        }
    }

    /**
     * ⚠️ **不要**给这个类加 `__toString()` / `jsonSerialize()`：一旦有，
     * 某天就会有人把它丢进日志或异常上下文，而 `PiiRedactionProcessor`
     * 认不出这是密钥。与 `HashDigest` 刻意不给 `__toString()` 同理。
     */
    public function testIsNotAccidentallyStringable(): void
    {
        // 走 class_implements 而不是 method_exists：后者的两个参数都是常量，
        // PHPStan 会把断言折叠成恒假并报 function.impossibleType。
        $interfaces = class_implements(SigningKey::class);

        self::assertIsArray($interfaces);
        self::assertNotContains(\Stringable::class, $interfaces);
        self::assertNotContains(\JsonSerializable::class, $interfaces);
    }
}
