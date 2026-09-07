<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Identity\Application\Session;

use App\Module\Identity\Application\Session\RefreshTokenPayload;
use App\Shared\Domain\Error\DomainException;
use App\Shared\Domain\Error\ErrorCode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `POST /v1/auth/token/refresh` 的请求体（T-105）。
 *
 * ⚠️ 这里只校验**形状**，不校验令牌本身 —— 后者只有 `RefreshTokenService`
 * 能回答，而它的答案永远是同一个 401。两层的错误码因此不重叠：
 * 一个畸形请求体拿不到 401，一个长度合法的乱码拿不到 400。
 */
#[CoversClass(RefreshTokenPayload::class)]
final class RefreshTokenPayloadTest extends TestCase
{
    /** 32 字节 base64url 的实际长度。 */
    private const REALISTIC = '9tK3zQ1sX7bF0pR4hN8mV2wY6cL5dJ0aG3eU7iO1kS4';

    public function testAcceptsARealisticToken(): void
    {
        self::assertSame(self::REALISTIC, RefreshTokenPayload::fromArray(['refresh_token' => self::REALISTIC])->refreshToken);
    }

    public function testRejectsAMissingToken(): void
    {
        self::assertSame(['refresh_token' => 'required'], self::errorsOf([]));
    }

    #[DataProvider('wrongTypes')]
    public function testRejectsAWrongType(mixed $value): void
    {
        self::assertSame(['refresh_token' => 'invalid_type'], self::errorsOf(['refresh_token' => $value]));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function wrongTypes(): iterable
    {
        yield 'int' => [123];
        yield 'array' => [['a']];
        yield 'bool' => [true];
    }

    /**
     * ⚠️ 显式 `null` 在这里等同于「没传」（`required`），
     * 与 {@see \App\Module\Identity\Application\Session\PushTokenPayload} **相反**。
     *
     * 那个类里 `null` 是一个有意义的动作（清除 FCM 令牌），所以它必须用
     * `array_key_exists()` 把两者分开。这里没有那个动作 —— 一个 null 的
     * refresh token 什么也不是 —— 于是 `?? null` 就是正确的读法。
     *
     * 两个类形状几乎相同却在这一点上刻意不同，所以两边都写了用例。
     */
    public function testAnExplicitNullReadsAsMissingUnlikeThePushTokenPayload(): void
    {
        self::assertSame(['refresh_token' => 'required'], self::errorsOf(['refresh_token' => null]));
    }

    /**
     * 契约的 `minLength: 32` / `maxLength: 512`。它们挡的是空串与
     * 「有人往这里灌 10 MB」，不是「这个令牌对不对」。
     */
    public function testEnforcesTheContractedLengthBounds(): void
    {
        self::assertSame(['refresh_token' => 'too_short'], self::errorsOf(['refresh_token' => str_repeat('a', 31)]));
        self::assertSame(['refresh_token' => 'too_long'], self::errorsOf(['refresh_token' => str_repeat('a', 513)]));
    }

    /**
     * 边界值本身是允许的 —— 多错一格的症状是「个别客户端永远刷不了新」。
     */
    public function testAcceptsBothBounds(): void
    {
        self::assertSame(32, \strlen(RefreshTokenPayload::fromArray(['refresh_token' => str_repeat('a', 32)])->refreshToken));
        self::assertSame(512, \strlen(RefreshTokenPayload::fromArray(['refresh_token' => str_repeat('a', 512)])->refreshToken));
    }

    /**
     * ⚠️ 错误文案里**不回显**令牌片段或长度 —— 这条错误会进日志，
     * 而参数本身是一个凭据。`PiiRedactionProcessor` 认得 `refresh_token`
     * 这个**键名**，但认不出被拼进一句英文里的一段字面量。
     */
    public function testTheErrorMessageNeverEchoesTheToken(): void
    {
        $secret = str_repeat('S3cr3t', 100);

        try {
            RefreshTokenPayload::fromArray(['refresh_token' => $secret]);
            self::fail('The payload must have been rejected.');
        } catch (DomainException $e) {
            $rendered = $e->getMessage().json_encode($e->fieldErrors(), \JSON_THROW_ON_ERROR);

            self::assertStringNotContainsString('S3cr3t', $rendered);
            self::assertStringNotContainsString((string) \strlen($secret), $rendered);
        }
    }

    public function testRejectsUnknownFields(): void
    {
        self::assertSame(
            ['refreshToken' => 'unknown_field', 'refresh_token' => 'required'],
            self::errorsOf(['refreshToken' => self::REALISTIC]),
        );
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, string> 字段名 => 错误码
     */
    private static function errorsOf(array $body): array
    {
        try {
            RefreshTokenPayload::fromArray($body);
        } catch (DomainException $e) {
            self::assertSame(ErrorCode::ValidationFailed, $e->errorCode());

            $codes = [];

            foreach ($e->fieldErrors() as $error) {
                $codes[$error->field] = $error->code->value;
            }

            return $codes;
        }

        self::fail('The payload must have been rejected.');
    }
}
