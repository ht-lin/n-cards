<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Doctrine;

use App\Shared\Domain\Crypto\Ciphertext;
use App\Shared\Infrastructure\Doctrine\CiphertextType;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\Exception\InvalidType;
use Doctrine\DBAL\Types\Exception\ValueNotConvertible;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CiphertextType::class)]
final class CiphertextTypeTest extends TestCase
{
    private const VALUE = 'vault:v1:abcdefghijklmnopqrstuvwxyz0123456789==';

    private CiphertextType $type;
    private PostgreSQLPlatform $platform;

    protected function setUp(): void
    {
        $this->type = new CiphertextType();
        $this->platform = new PostgreSQLPlatform();
    }

    /**
     * TEXT 而不是 VARCHAR(255)：Transit 密文的长度随明文增长，
     * `cards.note_encrypted` 的明文上限是 2000 字符（§7.5），base64 之后远超 255。
     */
    public function testDeclaresATextColumn(): void
    {
        self::assertSame('TEXT', $this->type->getSQLDeclaration([], $this->platform));
    }

    public function testConvertsValueObjectToDatabaseValue(): void
    {
        self::assertSame(
            self::VALUE,
            $this->type->convertToDatabaseValue(Ciphertext::fromString(self::VALUE), $this->platform),
        );
    }

    public function testConvertsNullBothWays(): void
    {
        self::assertNull($this->type->convertToDatabaseValue(null, $this->platform));
        self::assertNull($this->type->convertToPHPValue(null, $this->platform));
    }

    /**
     * ⚠️ 这是整个类型存在的意义：放行 string 的话
     * `$user->emailEncrypted = $plaintext` 会一路畅通地写进库，
     * §5.3 的信封加密在那一刻失效且不报任何错。
     */
    public function testRejectsPlaintextString(): void
    {
        $this->expectException(InvalidType::class);

        $this->type->convertToDatabaseValue('anna.mueller@example.de', $this->platform);
    }

    /**
     * 连一个**看起来对**的密文字符串也不收 —— 唯一的入口是 Ciphertext。
     */
    public function testRejectsEvenAWellFormedCiphertextString(): void
    {
        $this->expectException(InvalidType::class);

        $this->type->convertToDatabaseValue(self::VALUE, $this->platform);
    }

    public function testRejectionNeverLeaksTheInput(): void
    {
        $secret = 'anna.mueller@example.de';

        try {
            $this->type->convertToDatabaseValue($secret, $this->platform);
            self::fail('Expected InvalidType.');
        } catch (InvalidType $e) {
            self::assertStringNotContainsString($secret, $e->getMessage());
        }
    }

    public function testConvertsDatabaseValueToValueObject(): void
    {
        $ciphertext = $this->type->convertToPHPValue(self::VALUE, $this->platform);

        self::assertInstanceOf(Ciphertext::class, $ciphertext);
        self::assertSame(self::VALUE, $ciphertext->toString());
        self::assertSame(1, $ciphertext->keyVersion());
    }

    public function testPassesThroughAnAlreadyConvertedValueObject(): void
    {
        $ciphertext = Ciphertext::fromString(self::VALUE);

        self::assertSame($ciphertext, $this->type->convertToPHPValue($ciphertext, $this->platform));
    }

    /**
     * 库里这一列不是 `vault:vN:` 形态 —— 要么历史脏数据，要么明文。
     * 两种都必须立刻被看见，不能静默降级成 null。
     */
    public function testRejectsCorruptedDatabaseValue(): void
    {
        $this->expectException(ValueNotConvertible::class);

        $this->type->convertToPHPValue('not-a-ciphertext', $this->platform);
    }

    /**
     * ⚠️ 读回失败恰恰意味着「它可能是明文」，异常消息进的是日志的 message 字段，
     * 而 PiiRedactionProcessor 是按**键名**脱敏的，兜不住消息正文。
     */
    public function testCorruptionMessageNeverLeaksTheValue(): void
    {
        $secret = 'anna.mueller@example.de';

        try {
            $this->type->convertToPHPValue($secret, $this->platform);
            self::fail('Expected ValueNotConvertible.');
        } catch (ValueNotConvertible $e) {
            self::assertStringNotContainsString($secret, $e->getMessage());
        }
    }

    public function testRejectsNonStringDatabaseValue(): void
    {
        $this->expectException(InvalidType::class);

        $this->type->convertToPHPValue(42, $this->platform);
    }
}
