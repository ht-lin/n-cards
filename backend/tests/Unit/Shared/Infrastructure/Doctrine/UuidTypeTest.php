<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Doctrine;

use App\Shared\Domain\Identity\Uuid;
use App\Shared\Infrastructure\Doctrine\UuidType;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\Exception\InvalidType;
use Doctrine\DBAL\Types\Exception\ValueNotConvertible;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(UuidType::class)]
final class UuidTypeTest extends TestCase
{
    private const VALUE = '01941f29-7c00-70ab-8000-000000000000';

    private UuidType $type;
    private PostgreSQLPlatform $platform;

    protected function setUp(): void
    {
        $this->type = new UuidType();
        $this->platform = new PostgreSQLPlatform();
    }

    public function testDeclaresANativeUuidColumn(): void
    {
        // 原生 uuid（16 字节）而不是 VARCHAR(36) —— 对 §9.3 假设的 75 万行
        // cards 表，这是索引大小与比较成本上的实质差别。
        self::assertSame('UUID', $this->type->getSQLDeclaration([], $this->platform));
    }

    public function testConvertsValueObjectToDatabaseValue(): void
    {
        self::assertSame(
            self::VALUE,
            $this->type->convertToDatabaseValue(Uuid::fromString(self::VALUE), $this->platform),
        );
    }

    /**
     * 照收字符串，但**必须**归一化 —— 否则大小写混写的值会以两种形态进库，
     * 而唯一约束是按字节比的。
     */
    public function testNormalisesStringInput(): void
    {
        self::assertSame(
            self::VALUE,
            $this->type->convertToDatabaseValue(strtoupper(self::VALUE), $this->platform),
        );
    }

    public function testConvertsDatabaseValueToValueObject(): void
    {
        $uuid = $this->type->convertToPHPValue(self::VALUE, $this->platform);

        self::assertInstanceOf(Uuid::class, $uuid);
        self::assertSame(self::VALUE, $uuid->toString());
    }

    public function testRoundTrips(): void
    {
        $original = Uuid::fromString(self::VALUE);
        $stored = $this->type->convertToDatabaseValue($original, $this->platform);
        $restored = $this->type->convertToPHPValue($stored, $this->platform);

        self::assertInstanceOf(Uuid::class, $restored);
        self::assertTrue($original->equals($restored));
    }

    public function testNullPassesThroughBothWays(): void
    {
        self::assertNull($this->type->convertToDatabaseValue(null, $this->platform));
        self::assertNull($this->type->convertToPHPValue(null, $this->platform));
    }

    public function testAlreadyConvertedValuesPassThrough(): void
    {
        $uuid = Uuid::fromString(self::VALUE);

        self::assertSame($uuid, $this->type->convertToPHPValue($uuid, $this->platform));
    }

    public function testRejectsAnInvalidStringOnWrite(): void
    {
        $this->expectException(ValueNotConvertible::class);

        $this->type->convertToDatabaseValue('not-a-uuid', $this->platform);
    }

    /**
     * 库里存了个非法 UUID = 数据损坏，不是客户端输入问题 ——
     * 所以抛 DBAL 异常（最终 500），而不是 DomainException（那会变成 400）。
     */
    public function testRejectsAnInvalidStringOnRead(): void
    {
        $this->expectException(ValueNotConvertible::class);

        $this->type->convertToPHPValue('not-a-uuid', $this->platform);
    }

    public function testRejectsWrongPhpTypes(): void
    {
        $this->expectException(InvalidType::class);

        $this->type->convertToDatabaseValue(42, $this->platform);
    }

    // 「UuidType::NAME 与 config/packages/doctrine.yaml 的 `dbal.types` 键一致」
    // 这条不在这里断言 —— 对着字面量比常量是恒真的，测不到任何东西。
    // 真正有效的检查在 tests/Integration/.../UuidTypeRoundTripTest::testTypeIsRegisteredWithDoctrine：
    // 它问的是容器**实际**注册了没有，那才会因为配置写错而失败。
}
