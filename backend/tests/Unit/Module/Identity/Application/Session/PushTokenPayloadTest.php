<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Identity\Application\Session;

use App\Module\Identity\Application\Session\PushTokenPayload;
use App\Shared\Domain\Error\DomainException;
use App\Shared\Domain\Error\ErrorCode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `PUT /v1/me/devices/{id}/push-token` 的请求体（T-105）。
 *
 * ============================================================================
 * ⚠️ 这个类存在的全部理由：`null` 与「没传」必须分得开
 * ============================================================================
 * 用户关掉通知权限后，客户端要发 `{"push_token": null}` 把服务端这一侧也清掉。
 * 用 `?? null` 读的话，「没传」也会变成 `null` —— 于是一个字段名写错的客户端
 * 会静默地把自己的推送关掉，而没有任何一方知道。
 */
#[CoversClass(PushTokenPayload::class)]
final class PushTokenPayloadTest extends TestCase
{
    public function testAcceptsAToken(): void
    {
        self::assertSame('fcm-token-value', PushTokenPayload::fromArray(['push_token' => 'fcm-token-value'])->pushToken);
    }

    /**
     * ⚠️ 显式 `null` 是**合法**的，意思是「清除」。
     */
    public function testAcceptsAnExplicitNullAsTheClearAction(): void
    {
        self::assertNull(PushTokenPayload::fromArray(['push_token' => null])->pushToken);
    }

    /**
     * ⚠️ 与上一条成对：**省略**该字段不是「清除」，是畸形请求。
     */
    public function testRejectsAnOmittedField(): void
    {
        self::assertSame(['push_token' => 'required'], self::errorsOf([]));
    }

    /**
     * 空串是客户端 bug（想清除就该发 null）。静默地当成「清除」会让那个 bug
     * 一直活着，而它的另一面是「明明设置了却收不到推送」。
     */
    public function testRejectsAnEmptyString(): void
    {
        self::assertSame(['push_token' => 'invalid_format'], self::errorsOf(['push_token' => '']));
    }

    #[DataProvider('wrongTypes')]
    public function testRejectsAWrongType(mixed $value): void
    {
        self::assertSame(['push_token' => 'invalid_type'], self::errorsOf(['push_token' => $value]));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function wrongTypes(): iterable
    {
        yield 'int' => [123];
        yield 'bool' => [true];
        yield 'array' => [['a']];
    }

    public function testRejectsATokenBeyondTheLengthCap(): void
    {
        self::assertSame(
            ['push_token' => 'too_long'],
            self::errorsOf(['push_token' => str_repeat('a', 4097)]),
        );
    }

    /**
     * 上界本身是允许的 —— 边界值上多错一格，症状是「个别设备永远上报失败」。
     */
    public function testAcceptsATokenExactlyAtTheCap(): void
    {
        self::assertSame(4096, \strlen((string) PushTokenPayload::fromArray(['push_token' => str_repeat('a', 4096)])->pushToken));
    }

    /**
     * 未知字段主动拒绝 —— 宽松地忽略会让「字段名写成驼峰」表现为
     * 「推送没生效」，而那是一条完全错误的排查方向。
     */
    public function testRejectsUnknownFields(): void
    {
        self::assertSame(
            ['pushToken' => 'unknown_field', 'push_token' => 'required'],
            self::errorsOf(['pushToken' => 'fcm']),
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
            PushTokenPayload::fromArray($body);
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
