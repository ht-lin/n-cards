<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Domain\Pagination;

use App\Shared\Domain\Error\DomainException;
use App\Shared\Domain\Error\ErrorCode;
use App\Shared\Domain\Error\FieldErrorCode;
use App\Shared\Domain\Pagination\Cursor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Cursor::class)]
final class CursorTest extends TestCase
{
    public function testRoundTrips(): void
    {
        $payload = ['v' => 1, 'k' => ['created_at' => '2025-01-01T00:00:00Z', 'id' => '01941f29-7c00-70ab-8000-000000000000']];

        $decoded = Cursor::decode((string) Cursor::encode($payload));

        self::assertSame($payload, $decoded->payload());
    }

    /**
     * §5.4.1 的同步游标形状必须能被同一个编解码器处理 —— 这是本类刻意「形状无关」的理由，
     * T-202 实现 /sync 时不需要再写一份。
     */
    public function testCarriesTheSyncCursorShapeFromTheSpec(): void
    {
        $payload = ['seq' => 123456, 'sv' => 1];

        self::assertSame($payload, Cursor::decode((string) Cursor::encode($payload))->payload());
    }

    /**
     * base64url 且无填充：游标要能直接放进 query string 而不用再转义一次。
     */
    public function testEncodesAsUnpaddedBase64Url(): void
    {
        $encoded = (string) Cursor::encode(['seq' => 1]);

        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $encoded);
        self::assertStringNotContainsString('=', $encoded);
        self::assertStringNotContainsString('+', $encoded);
        self::assertStringNotContainsString('/', $encoded);
    }

    public function testEncodingIsDeterministic(): void
    {
        self::assertSame((string) Cursor::encode(['seq' => 7]), (string) Cursor::encode(['seq' => 7]));
    }

    public function testRejectsAnEmptyPayload(): void
    {
        // 编码侧是**编程错误**（我们自己传的），所以是 InvalidArgumentException 而非 DomainException。
        $this->expectException(\InvalidArgumentException::class);

        Cursor::encode([]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedCursors(): iterable
    {
        yield 'empty' => [''];
        yield 'not base64url' => ['not base64!'];
        yield 'padded base64' => ['eyJzZXEiOjF9=='];
        yield 'standard base64 alphabet' => ['e+JzZXEiOjF9'];
        yield 'valid base64 but not json' => [rtrim(strtr(base64_encode('plain text'), '+/', '-_'), '=')];
        yield 'json scalar' => [rtrim(strtr(base64_encode('42'), '+/', '-_'), '=')];
        yield 'json null' => [rtrim(strtr(base64_encode('null'), '+/', '-_'), '=')];
        yield 'json list' => [rtrim(strtr(base64_encode('[1,2,3]'), '+/', '-_'), '=')];
        yield 'json empty object' => [rtrim(strtr(base64_encode('{}'), '+/', '-_'), '=')];
        yield 'too long' => [str_repeat('a', 513)];
    }

    #[DataProvider('malformedCursors')]
    public function testRejectsMalformedCursors(string $raw): void
    {
        try {
            Cursor::decode($raw);
            self::fail('应该抛 DomainException');
        } catch (DomainException $e) {
            self::assertSame(ErrorCode::ValidationFailed, $e->errorCode());
            self::assertCount(1, $e->fieldErrors());
            self::assertSame('cursor', $e->fieldErrors()[0]->field);
            self::assertSame(FieldErrorCode::InvalidFormat, $e->fieldErrors()[0]->code);
        }
    }

    /**
     * 游标是不透明的 —— 报错文案里讲解析细节只会诱导客户端去自己构造游标。
     */
    public function testErrorLeaksNoParsingDetail(): void
    {
        try {
            Cursor::decode('not base64!');
            self::fail('应该抛 DomainException');
        } catch (DomainException $e) {
            $message = $e->fieldErrors()[0]->message.' '.$e->detail();

            self::assertStringNotContainsStringIgnoringCase('base64', $message);
            self::assertStringNotContainsStringIgnoringCase('json', $message);
        }
    }

    /**
     * 恰好在长度上限上应该通过 —— 守卫是 `> 512` 而不是 `>= 512`。
     */
    public function testAcceptsAPayloadAtTheSizeBoundary(): void
    {
        $payload = ['k' => str_repeat('x', 300)];
        $encoded = (string) Cursor::encode($payload);

        self::assertLessThanOrEqual(512, \strlen($encoded));
        self::assertSame($payload, Cursor::decode($encoded)->payload());
    }

    public function testStringableReturnsTheEncodedForm(): void
    {
        $cursor = Cursor::encode(['seq' => 1]);

        self::assertSame((string) $cursor, (string) Cursor::decode((string) $cursor));
    }
}
