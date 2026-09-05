<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Doctrine;

use App\Shared\Domain\Crypto\HashDigest;
use App\Shared\Infrastructure\Doctrine\HashDigestType;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\Exception\InvalidType;
use Doctrine\DBAL\Types\Exception\ValueNotConvertible;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(HashDigestType::class)]
final class HashDigestTypeTest extends TestCase
{
    private const RAW = "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0a\x0b\x0c\x0d\x0e\x0f"
        ."\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1a\x1b\x1c\x1d\x1e\xff";

    private HashDigestType $type;
    private PostgreSQLPlatform $platform;

    protected function setUp(): void
    {
        $this->type = new HashDigestType();
        $this->platform = new PostgreSQLPlatform();
    }

    public function testDeclaresAByteaColumn(): void
    {
        self::assertSame('BYTEA', $this->type->getSQLDeclaration([], $this->platform));
    }

    /**
     * ⚠️ 少了这一条，pdo_pgsql 会把裸字节当文本发出去，遇到 0x00 就截断 ——
     * 而摘要里出现 0x00 的概率约 12%，也就是大约每八条就静默坏一条。
     */
    public function testBindsAsBinary(): void
    {
        self::assertSame(ParameterType::BINARY, $this->type->getBindingType());
    }

    public function testConvertsValueObjectToDatabaseValue(): void
    {
        self::assertSame(
            self::RAW,
            $this->type->convertToDatabaseValue(HashDigest::fromRaw(self::RAW), $this->platform),
        );
    }

    public function testConvertsNullBothWays(): void
    {
        self::assertNull($this->type->convertToDatabaseValue(null, $this->platform));
        self::assertNull($this->type->convertToPHPValue(null, $this->platform));
    }

    /**
     * 与 UuidType 不同，这里**不照收字符串** —— 一个长度对得上的字符串完全可能是
     * 明文。BYTEA 列没有任何形态可供校验，闸门只有构造期这一处。
     */
    public function testRejectsRawStringInput(): void
    {
        $this->expectException(InvalidType::class);

        $this->type->convertToDatabaseValue(self::RAW, $this->platform);
    }

    /**
     * ⚠️ 拒绝时不能把入参拼进消息 —— 这个分支最典型的入参就是明文。
     */
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
        $digest = $this->type->convertToPHPValue(self::RAW, $this->platform);

        self::assertInstanceOf(HashDigest::class, $digest);
        self::assertSame(self::RAW, $digest->toRaw());
    }

    /**
     * pdo_pgsql 把 BYTEA 列作为 stream 交出来。少了 `stream_get_contents()`
     * 这里就会拿到一个 resource —— 而且**只在真库上复现**。
     */
    public function testReadsBackFromAStreamResource(): void
    {
        $stream = fopen('php://memory', 'r+');
        self::assertIsResource($stream);
        fwrite($stream, self::RAW);
        rewind($stream);

        $digest = $this->type->convertToPHPValue($stream, $this->platform);

        self::assertInstanceOf(HashDigest::class, $digest);
        self::assertSame(self::RAW, $digest->toRaw());

        fclose($stream);
    }

    public function testPassesThroughAnAlreadyConvertedValueObject(): void
    {
        $digest = HashDigest::fromRaw(self::RAW);

        self::assertSame($digest, $this->type->convertToPHPValue($digest, $this->platform));
    }

    /**
     * 库里存了个长度不对的值 = 数据损坏，必须 500 而不是静默降级成 null。
     */
    public function testRejectsCorruptedDatabaseValue(): void
    {
        $this->expectException(ValueNotConvertible::class);

        $this->type->convertToPHPValue(substr(self::RAW, 0, 16), $this->platform);
    }

    /**
     * 报长度、不报值 —— 「长度不对的 BYTEA」最可能的成因就是有人写了明文进去。
     */
    public function testCorruptionMessageReportsLengthNotValue(): void
    {
        $secret = 'anna.mueller@example.de';

        try {
            $this->type->convertToPHPValue($secret, $this->platform);
            self::fail('Expected ValueNotConvertible.');
        } catch (ValueNotConvertible $e) {
            self::assertStringNotContainsString($secret, $e->getMessage());
            self::assertStringContainsString(\sprintf('<%d bytes>', \strlen($secret)), $e->getMessage());
        }
    }

    public function testRejectsNonStringDatabaseValue(): void
    {
        $this->expectException(InvalidType::class);

        $this->type->convertToPHPValue(42, $this->platform);
    }
}
