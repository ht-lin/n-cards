<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Wallet\Application\Card;

use App\Module\Wallet\Application\Card\CardUpdatePayload;
use App\Module\Wallet\Domain\ValueObject\BarcodeFormat;
use App\Shared\Domain\Error\DomainException;
use App\Shared\Domain\Error\ErrorCode;
use App\Shared\Domain\Error\FieldErrorCode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(CardUpdatePayload::class)]
final class CardUpdatePayloadTest extends TestCase
{
    public function testASingleFieldIsEnough(): void
    {
        $payload = CardUpdatePayload::fromArray(['title' => 'REWE Payback (Zweitkarte)']);

        self::assertSame('REWE Payback (Zweitkarte)', $payload->title);
        self::assertNull($payload->color);
        self::assertNull($payload->barcodeFormat);
        // 三个可空字段一个都没出现 → 全部不动。
        self::assertFalse($payload->merchantLabelPresent);
        self::assertFalse($payload->notePresent);
        self::assertFalse($payload->expiresOnPresent);
    }

    /**
     * ⚠️ 这是本类存在的全部理由：区分「没给」与「设成 null」。
     *
     * 合并两者的后果是「只改标题的 PATCH 顺手把备注清空了」——
     * 一次静默的数据丢失，而备注是服务端再生不出来的用户输入。
     */
    public function testOmittingANullableFieldDiffersFromNullingIt(): void
    {
        $omitted = CardUpdatePayload::fromArray(['title' => 'DM']);
        $nulled = CardUpdatePayload::fromArray(['note' => null]);

        self::assertFalse($omitted->notePresent);
        self::assertNull($omitted->note);

        self::assertTrue($nulled->notePresent);
        self::assertNull($nulled->note);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function presenceCases(): iterable
    {
        yield 'merchant_label 置空' => [['merchant_label' => null], 'merchantLabelPresent'];
        yield 'note 置空' => [['note' => null], 'notePresent'];
        yield 'expires_on 置空' => [['expires_on' => null], 'expiresOnPresent'];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[DataProvider('presenceCases')]
    public function testNullingANullableFieldIsRecordedAsPresent(array $body, string $flag): void
    {
        $payload = CardUpdatePayload::fromArray($body);

        self::assertTrue($payload->{$flag});
    }

    public function testItAcceptsEveryContentField(): void
    {
        $payload = CardUpdatePayload::fromArray([
            'title' => 'DM',
            'merchant_label' => 'dm-drogerie markt',
            'color' => 'green_500',
            'barcode_format' => 'QR_CODE',
            'barcode_value' => 'https://example.de/x',
            'note' => 'Rückseite abgenutzt',
            'expires_on' => '2028-12-31',
        ]);

        self::assertSame('DM', $payload->title);
        self::assertSame('dm-drogerie markt', $payload->merchantLabel);
        self::assertSame('green_500', $payload->color);
        self::assertSame(BarcodeFormat::QrCode, $payload->barcodeFormat);
        self::assertSame('https://example.de/x', $payload->barcodeValue);
        self::assertSame('Rückseite abgenutzt', $payload->note);
        self::assertSame('2028-12-31T00:00:00+00:00', $payload->expiresOn?->format('c'));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string, FieldErrorCode}>
     */
    public static function invalidBodies(): iterable
    {
        yield 'title 为空串' => [['title' => ''], 'title', FieldErrorCode::TooShort];
        yield 'title 不是字符串' => [['title' => 7], 'title', FieldErrorCode::InvalidType];
        // ⚠️ 契约里 title 不可空，所以 null 是类型错误而不是「清空」。
        yield 'title 不可为 null' => [['title' => null], 'title', FieldErrorCode::InvalidType];
        yield 'barcode_format 不认识' => [['barcode_format' => 'RM4SCC'], 'barcode_format', FieldErrorCode::InvalidFormat];
        yield 'expires_on 不是日期' => [['expires_on' => 'gestern'], 'expires_on', FieldErrorCode::InvalidFormat];
        yield 'merchant_label 超长' => [['merchant_label' => str_repeat('a', 101)], 'merchant_label', FieldErrorCode::TooLong];
        yield '未知字段' => [['nonsense' => 1], 'nonsense', FieldErrorCode::UnknownField];

        // ⚠️ 契约的 CardUpdate description 逐字写着「**没有** sort_order / is_pinned」
        // ——它们是每成员私有的，走 PUT /v1/cards/{id}/placement（T-110）。
        yield 'sort_order 被拒' => [['sort_order' => 3], 'sort_order', FieldErrorCode::UnknownField];
        yield 'is_pinned 被拒' => [['is_pinned' => true], 'is_pinned', FieldErrorCode::UnknownField];
        // 「没有 id / owner_id / revision：前两个不可变，revision 由服务端递增」。
        yield 'id 被拒' => [['id' => '0192f3a1-b2c3-7d4e-8f01-23456789abcd'], 'id', FieldErrorCode::UnknownField];
        yield 'owner_id 被拒' => [['owner_id' => '0192f3a1-b2c3-7d4e-8f01-23456789abcd'], 'owner_id', FieldErrorCode::UnknownField];
        yield 'revision 被拒' => [['revision' => 8], 'revision', FieldErrorCode::UnknownField];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[DataProvider('invalidBodies')]
    public function testItRejects(array $body, string $field, FieldErrorCode $code): void
    {
        try {
            CardUpdatePayload::fromArray($body);
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
}
