<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Identity\Application\Me;

use App\Module\Identity\Application\Me\ProfileUpdatePayload;
use App\Module\Identity\Domain\Entity\User;
use App\Module\Identity\Domain\ValueObject\Locale;
use App\Shared\Domain\Error\DomainException;
use App\Shared\Domain\Error\ErrorCode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `PATCH /v1/me` 请求体的解析（T-108）。
 *
 * ============================================================================
 * 这个文件真正在守的东西
 * ============================================================================
 * **`username` 的判定必须排在未知字段扫描之前，且抛 409 不是 400。**
 *
 * 把那三行往下挪，别的用例照常绿 —— 因为 `username` 落进
 * `ALLOWED_FIELDS` 的差集之后仍然是一个错误响应，只是变成了
 * `400 validation_failed` + `unknown_field`。而那对客户端意味着
 * 「这个字段不存在」，也就是 §6.2 明令禁止的「静默忽略」换了个说法。
 * 只有这里会红。
 */
#[CoversClass(ProfileUpdatePayload::class)]
final class ProfileUpdatePayloadTest extends TestCase
{
    // ========================================================================
    // 正常路径
    // ========================================================================

    #[DataProvider('locales')]
    public function testAcceptsBothLocales(string $raw, Locale $expected): void
    {
        self::assertSame($expected, ProfileUpdatePayload::fromArray(['locale' => $raw])->locale);
    }

    /**
     * @return iterable<string, array{string, Locale}>
     */
    public static function locales(): iterable
    {
        yield 'de' => ['de', Locale::German];
        yield 'en' => ['en', Locale::English];
    }

    // ========================================================================
    // username —— 409，且最先判
    // ========================================================================

    /**
     * 按**键是否存在**判定，不看值：`null`（想清空）与一个合法的新名字
     * 是同一件被禁止的事（§3.8 的不可变性里没有「清空」这个概念）。
     */
    #[DataProvider('usernameValues')]
    public function testRejectsAnyUsernameFieldAsImmutable(mixed $value): void
    {
        try {
            ProfileUpdatePayload::fromArray(['locale' => 'de', 'username' => $value]);
            self::fail('含 username 的请求体必须抛 username_immutable');
        } catch (DomainException $e) {
            self::assertSame(ErrorCode::UsernameImmutable, $e->errorCode());
            // 与 User::assignUsername() 共用一句话 —— 两处是同一条产品不变量。
            self::assertSame(User::USERNAME_IMMUTABLE_DETAIL, $e->detail());
        }
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function usernameValues(): iterable
    {
        yield '一个新名字' => ['bob_x'];
        yield 'null（想清空）' => [null];
        yield '类型不对' => [42];
        yield '空串' => [''];
    }

    /**
     * ⚠️ 本文件的核心断言：`username` 与一堆**别的**错误同时出现时，
     * 赢的必须是 409。
     *
     * 反过来（先收集 `errors[]` 再抛 400）的话，客户端会看到一条
     * 「unknown_field: username」—— 而 §6.2 要的恰恰是让它明确知道
     * 「这个字段存在，但你改不了它」。
     */
    public function testTheUsernameCheckWinsOverEveryOtherValidationError(): void
    {
        $this->expectException(DomainException::class);

        try {
            ProfileUpdatePayload::fromArray([
                'username' => 'bob_x',
                'locale' => 'fr',          // 枚举外
                'display_name' => 'Anna',  // 未知字段（C10 移除的那个）
            ]);
        } catch (DomainException $e) {
            self::assertSame(ErrorCode::UsernameImmutable, $e->errorCode());
            self::assertSame([], $e->fieldErrors(), '409 这一路不带 errors[] —— 它不是字段校验失败');

            throw $e;
        }
    }

    /**
     * `locale` 都没给也一样 —— 409 在 `required` 之前。
     */
    public function testTheUsernameCheckWinsOverTheRequiredLocale(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(User::USERNAME_IMMUTABLE_DETAIL);

        ProfileUpdatePayload::fromArray(['username' => 'bob_x']);
    }

    // ========================================================================
    // 字段形状 —— 400
    // ========================================================================

    public function testRequiresTheLocale(): void
    {
        $error = self::firstFieldError([]);

        self::assertSame('locale', $error['field']);
        self::assertSame('required', $error['code']);
    }

    /**
     * 显式发 `null` 与省略该键是同一件事：`locale` 没有「清空」的语义。
     */
    public function testTreatsAnExplicitNullAsMissing(): void
    {
        self::assertSame('required', self::firstFieldError(['locale' => null])['code']);
    }

    public function testRejectsANonStringLocale(): void
    {
        self::assertSame('invalid_type', self::firstFieldError(['locale' => 42])['code']);
    }

    /**
     * 枚举外的值是 `invalid_format`（400），**不是** 422 —— 契约把 `locale`
     * 写成 `enum: [de, en]`，发别的值说明请求没照契约构造。
     */
    public function testRejectsALocaleOutsideTheEnum(): void
    {
        $error = self::firstFieldError(['locale' => 'fr']);

        self::assertSame('invalid_format', $error['code']);
        // 值域闭合的两项，写进 detail 是安全的（不是个人数据）。
        self::assertStringContainsString('de, en', $error['message']);
    }

    public function testRejectsAnUnknownField(): void
    {
        $error = self::firstFieldError(['locale' => 'de', 'display_name' => 'Anna']);

        self::assertSame('display_name', $error['field']);
        self::assertSame('unknown_field', $error['code']);
    }

    /**
     * 一次把所有问题都报回去，不是逐个 —— 客户端一趟就能改完。
     */
    public function testReportsEveryProblemAtOnce(): void
    {
        try {
            ProfileUpdatePayload::fromArray(['locale' => 'fr', 'foo' => 1, 'bar' => 2]);
            self::fail('应该抛 validation_failed');
        } catch (DomainException $e) {
            self::assertSame(ErrorCode::ValidationFailed, $e->errorCode());
            self::assertCount(3, $e->fieldErrors());
        }
    }

    // ========================================================================
    // helpers
    // ========================================================================

    /**
     * @param array<string, mixed> $body
     *
     * @return array{field: string, code: string, message: string}
     */
    private static function firstFieldError(array $body): array
    {
        try {
            ProfileUpdatePayload::fromArray($body);
            self::fail('应该抛 validation_failed');
        } catch (DomainException $e) {
            self::assertSame(ErrorCode::ValidationFailed, $e->errorCode());
            self::assertNotSame([], $e->fieldErrors());

            $first = $e->fieldErrors()[0];

            return [
                'field' => $first->field,
                'code' => $first->code->value,
                'message' => $first->message,
            ];
        }
    }
}
