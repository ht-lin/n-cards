<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Wallet\Application\Card;

use App\Module\Wallet\Application\Card\CardCreatePayload;
use App\Module\Wallet\Application\Card\CardFields;
use App\Module\Wallet\Domain\ValueObject\BarcodeFormat;
use App\Shared\Domain\Error\DomainException;
use App\Shared\Domain\Error\ErrorCode;
use App\Shared\Domain\Error\FieldErrorCode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(CardCreatePayload::class)]
#[CoversClass(CardFields::class)]
final class CardCreatePayloadTest extends TestCase
{
    private const ID = '0192f3a1-b2c3-7d4e-8f01-23456789abcd';

    public function testItAcceptsTheContractExample(): void
    {
        // 逐字取自 docs/api/openapi.yaml 的 CardCreate example。
        $payload = CardCreatePayload::fromArray([
            'id' => self::ID,
            'title' => 'REWE Payback',
            'merchant_label' => 'REWE',
            'color' => 'blue_600',
            'barcode_format' => 'EAN_13',
            'barcode_value' => '4012345678901',
            'note' => null,
            'expires_on' => null,
        ]);

        self::assertSame(self::ID, $payload->id->toString());
        self::assertSame('REWE Payback', $payload->title);
        self::assertSame('REWE', $payload->merchantLabel);
        self::assertSame(BarcodeFormat::Ean13, $payload->barcodeFormat);
        self::assertNull($payload->note);
        self::assertNull($payload->expiresOn);
    }

    public function testTheOptionalFieldsMayBeOmittedEntirely(): void
    {
        $payload = CardCreatePayload::fromArray([
            'id' => self::ID,
            'title' => 'REWE Payback',
            'color' => 'blue_600',
            'barcode_format' => 'EAN_13',
            'barcode_value' => '4012345678901',
        ]);

        self::assertNull($payload->merchantLabel);
        self::assertNull($payload->note);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string, FieldErrorCode}>
     */
    public static function invalidBodies(): iterable
    {
        $valid = [
            'id' => self::ID,
            'title' => 'REWE Payback',
            'color' => 'blue_600',
            'barcode_format' => 'EAN_13',
            'barcode_value' => '4012345678901',
        ];

        yield 'id 缺失' => [array_diff_key($valid, ['id' => null]), 'id', FieldErrorCode::Required];
        yield 'id 不是字符串' => [[...$valid, 'id' => 42], 'id', FieldErrorCode::InvalidType];
        yield 'id 不是 UUID' => [[...$valid, 'id' => 'not-a-uuid'], 'id', FieldErrorCode::InvalidFormat];
        // UUIDv4 —— 版本位是 4。收下它会打散主键的时间局部性，见 payload 的类注释。
        yield 'id 是 UUIDv4' => [[...$valid, 'id' => '9f8e7d6c-5b4a-4938-8271-605f4e3d2c1b'], 'id', FieldErrorCode::InvalidFormat];

        yield 'title 缺失' => [array_diff_key($valid, ['title' => null]), 'title', FieldErrorCode::Required];
        yield 'title 为空串' => [[...$valid, 'title' => ''], 'title', FieldErrorCode::TooShort];
        yield 'title 不是字符串' => [[...$valid, 'title' => ['a']], 'title', FieldErrorCode::InvalidType];

        yield 'color 缺失' => [array_diff_key($valid, ['color' => null]), 'color', FieldErrorCode::Required];
        yield 'barcode_value 为空串' => [[...$valid, 'barcode_value' => ''], 'barcode_value', FieldErrorCode::TooShort];

        yield 'barcode_format 缺失' => [array_diff_key($valid, ['barcode_format' => null]), 'barcode_format', FieldErrorCode::Required];
        yield 'barcode_format 不认识' => [[...$valid, 'barcode_format' => 'RM4SCC'], 'barcode_format', FieldErrorCode::InvalidFormat];

        // 三个「值的类型就不对」的分支 —— 与「格式不对」是两回事：
        // 前者说明客户端根本没照契约构造请求，后者是用户输了个不能用的值。
        yield 'merchant_label 不是字符串' => [[...$valid, 'merchant_label' => 7], 'merchant_label', FieldErrorCode::InvalidType];
        yield 'barcode_format 不是字符串' => [[...$valid, 'barcode_format' => 13], 'barcode_format', FieldErrorCode::InvalidType];
        yield 'expires_on 不是字符串' => [[...$valid, 'expires_on' => 20281231], 'expires_on', FieldErrorCode::InvalidType];

        yield 'expires_on 不是日期' => [[...$valid, 'expires_on' => '31.12.2028'], 'expires_on', FieldErrorCode::InvalidFormat];
        // PHP 会把 2026-02-31 静默滚成 2026-03-03 —— 不回头比对的话，
        // 用户设的到期日会被改成别的日子。
        yield 'expires_on 是不存在的日期' => [[...$valid, 'expires_on' => '2026-02-31'], 'expires_on', FieldErrorCode::InvalidFormat];

        // §7.5 的表里没有 merchant_label，所以它是 400 too_long 而不是 422。
        yield 'merchant_label 超长' => [[...$valid, 'merchant_label' => str_repeat('a', 101)], 'merchant_label', FieldErrorCode::TooLong];

        yield '未知字段' => [[...$valid, 'nonsense' => 1], 'nonsense', FieldErrorCode::UnknownField];
        // ⚠️ 这两个是**每成员私有**的，走 T-110 的 placement 端点。
        // 它们落进未知字段扫描正是想要的行为。
        yield 'sort_order 被拒' => [[...$valid, 'sort_order' => 0], 'sort_order', FieldErrorCode::UnknownField];
        yield 'is_pinned 被拒' => [[...$valid, 'is_pinned' => true], 'is_pinned', FieldErrorCode::UnknownField];
        // 服务端决定的字段，客户端说了不算。
        yield 'revision 被拒' => [[...$valid, 'revision' => 7], 'revision', FieldErrorCode::UnknownField];
        yield 'owner_id 被拒' => [[...$valid, 'owner_id' => self::ID], 'owner_id', FieldErrorCode::UnknownField];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[DataProvider('invalidBodies')]
    public function testItRejects(array $body, string $field, FieldErrorCode $code): void
    {
        try {
            CardCreatePayload::fromArray($body);
            self::fail('期待 validation_failed。');
        } catch (DomainException $e) {
            self::assertSame(ErrorCode::ValidationFailed, $e->errorCode());

            $matched = array_filter(
                $e->fieldErrors(),
                static fn ($error): bool => $error->field === $field && $error->code === $code,
            );

            self::assertNotEmpty($matched, \sprintf(
                '期待字段 %s 上有一条 %s，实际拿到：%s',
                $field,
                $code->value,
                json_encode(array_map(static fn ($e): string => $e->field.':'.$e->code->value, $e->fieldErrors()), \JSON_THROW_ON_ERROR),
            ));
        }
    }

    /**
     * 一次请求把全部问题都报出来，而不是一次报一个 ——
     * 否则客户端要往返七次才能把一个表单填对。
     */
    public function testItReportsEveryProblemAtOnce(): void
    {
        try {
            CardCreatePayload::fromArray(['id' => 'nope', 'title' => '', 'barcode_format' => 'RM4SCC']);
            self::fail('期待 validation_failed。');
        } catch (DomainException $e) {
            $fields = array_map(static fn ($error): string => $error->field, $e->fieldErrors());

            self::assertContains('id', $fields);
            self::assertContains('title', $fields);
            self::assertContains('barcode_format', $fields);
            self::assertContains('color', $fields);
            self::assertContains('barcode_value', $fields);
        }
    }

    /**
     * 空串归一化成 null —— 「没有备注」只该有一种表示。
     */
    public function testEmptyNullableStringsBecomeNull(): void
    {
        $payload = CardCreatePayload::fromArray([
            'id' => self::ID,
            'title' => 'REWE Payback',
            'color' => 'blue_600',
            'barcode_format' => 'EAN_13',
            'barcode_value' => '4012345678901',
            'merchant_label' => '',
            'note' => '',
        ]);

        self::assertNull($payload->merchantLabel);
        self::assertNull($payload->note);
    }

    /**
     * `expires_on` 解出来必须是**当天零点 UTC**。
     *
     * 不归零的话，同一个日期在一天里的不同时刻会解出不同的值，于是
     * `Card::changeExpiry()` 的相等判断随机失效。
     */
    public function testExpiresOnIsNormalisedToMidnightUtc(): void
    {
        $payload = CardCreatePayload::fromArray([
            'id' => self::ID,
            'title' => 'REWE Payback',
            'color' => 'blue_600',
            'barcode_format' => 'EAN_13',
            'barcode_value' => '4012345678901',
            'expires_on' => '2028-12-31',
        ]);

        self::assertSame('2028-12-31T00:00:00+00:00', $payload->expiresOn?->format('c'));
    }
}
