<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Domain\Identity;

use App\Shared\Domain\Error\DomainException;
use App\Shared\Domain\Error\ErrorCode;
use App\Shared\Domain\Identity\Uuid;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Uuid::class)]
final class UuidTest extends TestCase
{
    private const VALID = '01941f29-7c00-70ab-8000-000000000000';

    public function testAcceptsTheCanonicalForm(): void
    {
        self::assertSame(self::VALID, Uuid::fromString(self::VALID)->toString());
    }

    /**
     * Android 的 `UUID.toString()` 与 Postgres 的 `uuid` 输出都是小写。
     * 不归一化的话，同一个 id 会以两种字符串形态出现在幂等键与日志里。
     */
    public function testNormalisesToLowercase(): void
    {
        self::assertSame(self::VALID, Uuid::fromString(strtoupper(self::VALID))->toString());
    }

    public function testMixedCaseInputsAreEqual(): void
    {
        self::assertTrue(
            Uuid::fromString(self::VALID)->equals(Uuid::fromString(strtoupper(self::VALID))),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidValues(): iterable
    {
        yield 'empty' => [''];
        yield 'too short' => ['01941f29-7c00-70ab-8000-00000000000'];
        yield 'too long' => ['01941f29-7c00-70ab-8000-0000000000000'];
        yield 'no hyphens' => ['01941f297c0070ab8000000000000000'];
        yield 'wrong hyphen positions' => ['01941f2-97c00-70ab-8000-000000000000'];
        yield 'non-hex character' => ['01941f29-7c00-70ab-8000-00000000000g'];
        yield 'braced form' => ['{01941f29-7c00-70ab-8000-000000000000}'];
        yield 'urn prefix' => ['urn:uuid:01941f29-7c00-70ab-8000-000000000000'];
        yield 'leading whitespace' => [' 01941f29-7c00-70ab-8000-000000000000'];
        yield 'trailing whitespace' => ['01941f29-7c00-70ab-8000-000000000000 '];
        yield 'arbitrary text' => ['not-a-uuid'];
        // Nil 与 Max 是合法的 RFC 9562 值，但当哨兵用是 bug 温床 —— 一律拒绝。
        yield 'nil uuid' => ['00000000-0000-0000-0000-000000000000'];
        yield 'max uuid' => ['ffffffff-ffff-ffff-ffff-ffffffffffff'];
        yield 'max uuid uppercase' => ['FFFFFFFF-FFFF-FFFF-FFFF-FFFFFFFFFFFF'];
    }

    #[DataProvider('invalidValues')]
    public function testRejectsInvalidValues(string $value): void
    {
        self::assertFalse(Uuid::isValid($value));
        self::assertNull(Uuid::tryFromString($value));
    }

    #[DataProvider('invalidValues')]
    public function testFromStringThrowsValidationFailed(string $value): void
    {
        try {
            Uuid::fromString($value);
            self::fail('应该抛 DomainException');
        } catch (DomainException $e) {
            self::assertSame(ErrorCode::ValidationFailed, $e->errorCode());
            self::assertCount(1, $e->fieldErrors());
            self::assertSame('id', $e->fieldErrors()[0]->field);
        }
    }

    public function testBytesRoundTrip(): void
    {
        $uuid = Uuid::fromString(self::VALID);

        self::assertSame(16, \strlen($uuid->toBytes()));
        self::assertTrue(Uuid::fromBytes($uuid->toBytes())->equals($uuid));
    }

    public function testFromBytesRejectsWrongLength(): void
    {
        $this->expectException(DomainException::class);

        Uuid::fromBytes(str_repeat("\x01", 15));
    }

    public function testReadsTheVersionNibble(): void
    {
        self::assertSame(7, Uuid::fromString(self::VALID)->version());
        // 同样的位串换成 v4，只改版本 nibble。
        self::assertSame(4, Uuid::fromString('01941f29-7c00-40ab-8000-000000000000')->version());
    }

    public function testReadsTheV7Timestamp(): void
    {
        self::assertSame(1735689600000, Uuid::fromString(self::VALID)->timestampMillis());
    }

    /**
     * 非 v7 的前 48 位是随机数，读出来是个无意义的时间 —— 与其返回垃圾不如直接拒绝。
     * 抛 LogicException 而非 DomainException：这是调用方的编程错误，不是客户端输入问题。
     */
    public function testTimestampRejectsNonV7(): void
    {
        $this->expectException(\LogicException::class);

        Uuid::fromString('01941f29-7c00-40ab-8000-000000000000')->timestampMillis();
    }

    /**
     * §5.4.3 规定卡的 id 由客户端生成。今天是 v7，但 §13.6 禁止事后收紧校验 ——
     * 所以 fromString 必须照收 v4，把「要不要求 v7」的决定留给调用点。
     */
    public function testDoesNotEnforceVersion7(): void
    {
        $v4 = '01941f29-7c00-40ab-8000-000000000001';

        self::assertTrue(Uuid::isValid($v4));
        self::assertSame($v4, Uuid::fromString($v4)->toString());
    }

    public function testSerialisesAsAPlainString(): void
    {
        $uuid = Uuid::fromString(self::VALID);

        self::assertSame(self::VALID, $uuid->jsonSerialize());
        self::assertSame('"'.self::VALID.'"', json_encode($uuid, \JSON_THROW_ON_ERROR));
        self::assertSame(self::VALID, (string) $uuid);
    }

    public function testDifferentValuesAreNotEqual(): void
    {
        self::assertFalse(
            Uuid::fromString(self::VALID)->equals(Uuid::fromString('01941f29-7c00-70ab-8000-000000000001')),
        );
    }
}
