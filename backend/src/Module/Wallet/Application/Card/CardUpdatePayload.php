<?php

declare(strict_types=1);

namespace App\Module\Wallet\Application\Card;

use App\Module\Wallet\Domain\ValueObject\BarcodeFormat;
use App\Shared\Domain\Error\DomainException;
use App\Shared\Domain\Error\FieldError;
use App\Shared\Domain\Error\FieldErrorCode;

/**
 * `PATCH /v1/cards/{id}` 的请求体（契约的 `CardUpdate`）。
 *
 * ============================================================================
 * ⚠️ 稀疏更新：「没给这个字段」与「把这个字段设成 null」必须分得开
 * ============================================================================
 * 契约里 `CardUpdate` 是 `minProperties: 1`、全部字段可选，而其中三个字段
 * （`merchant_label` / `note` / `expires_on`）**本身可空**。于是对它们来说
 * 一个 `?string` 装不下三种状态里的两种：
 *
 *   `{"note": "abc"}` → 改成 abc
 *   `{"note": null}`  → **清空**
 *   `{}`（没这个键） → 不动
 *
 * 后两种都会让一个 `?string $note` 等于 `null`。合并它们的后果是
 * 「只改标题的 PATCH 顺手把备注清空了」—— 一次静默的数据丢失，
 * 而备注是用户手打的、服务端再生不出来的内容。
 *
 * 所以那三个字段各带一个 `*Present` 布尔。另外四个
 * （`title` / `color` / `barcode_format` / `barcode_value`）在契约里**不可空**，
 * `null` 只可能表示「没给」，不需要额外的标志位。
 *
 * ⚠️ 别「统一」成七个 `*Present` 求整齐：多出来的四个恒等于
 * `null !== $field`，而一个永远为真的判断迟早会被人删掉或写反。
 *
 * ============================================================================
 * ⚠️ 空体是 400，不是 200 no-op
 * ============================================================================
 * `{}` 更早一步就被 {@see \App\Shared\Http\Controller\AbstractApiController::decodeBody()}
 * 拒成 `400 malformed_request`（`array_is_list([])` 恒为 true —— T-107 的落地记录
 * 登记过这个坑）。所以本类的 `minProperties: 1` 只在「只发了未知字段」时生效，
 * 那一路由未知字段扫描给出 400。
 *
 * ============================================================================
 * ⚠️ `sort_order` / `is_pinned` 是**显式**拒绝的
 * ============================================================================
 * 它们落进未知字段扫描（不在 {@see CardFields::CONTENT_FIELDS} 里），
 * 于是得到 `400 validation_failed` + `unknown_field`。这正是要的行为：
 * 它们是每成员私有的，走 `PUT /v1/cards/{id}/placement`（T-110）。
 * 契约的 `CardUpdate` description 逐字写着「**没有** sort_order / is_pinned」。
 */
final readonly class CardUpdatePayload
{
    /**
     * @param string|null        $title                null 表示未提供（契约里它不可空）
     * @param string|null        $color                同上
     * @param BarcodeFormat|null $barcodeFormat        同上
     * @param string|null        $barcodeValue         同上
     * @param bool               $merchantLabelPresent 请求体里出现过这个键吗
     * @param bool               $notePresent          同上
     * @param bool               $expiresOnPresent     同上
     */
    public function __construct(
        public ?string $title,
        public ?string $color,
        public ?BarcodeFormat $barcodeFormat,
        public ?string $barcodeValue,
        public bool $merchantLabelPresent,
        public ?string $merchantLabel,
        public bool $notePresent,
        public ?string $note,
        public bool $expiresOnPresent,
        public ?\DateTimeImmutable $expiresOn,
    ) {
    }

    /**
     * @param array<string, mixed> $body
     *
     * @throws DomainException `validation_failed`（400）
     */
    public static function fromArray(array $body): self
    {
        $errors = [];

        CardFields::rejectUnknown($body, CardFields::CONTENT_FIELDS, $errors);

        // 每个字段只在**出现**时才解析 —— 缺席不是错误。
        $title = \array_key_exists('title', $body) ? CardFields::string($body, 'title', $errors) : null;
        $color = \array_key_exists('color', $body) ? CardFields::string($body, 'color', $errors) : null;
        $barcodeValue = \array_key_exists('barcode_value', $body) ? CardFields::string($body, 'barcode_value', $errors) : null;
        $barcodeFormat = \array_key_exists('barcode_format', $body) ? CardFields::barcodeFormat($body, $errors) : null;

        $merchantLabelPresent = \array_key_exists('merchant_label', $body);
        $merchantLabel = $merchantLabelPresent ? CardFields::nullableString($body, 'merchant_label', $errors) : null;

        $notePresent = \array_key_exists('note', $body);
        $note = $notePresent ? CardFields::nullableString($body, 'note', $errors) : null;

        $expiresOnPresent = \array_key_exists('expires_on', $body);
        $expiresOn = $expiresOnPresent ? CardFields::expiresOn($body, $errors) : null;

        if ($merchantLabelPresent) {
            CardFields::checkMerchantLabelLength($merchantLabel, $errors);
        }

        if ([] === $errors && [] === array_intersect(array_keys($body), CardFields::CONTENT_FIELDS)) {
            // 只发了未知字段（未知字段扫描已经把它们记进 $errors 了，所以
            // 这一条实际上走不到）—— 或者发了一个 decodeBody 放行、
            // 但一个内容字段都没有的体。契约的 `minProperties: 1` 落在这里。
            $errors[] = new FieldError('body', FieldErrorCode::Required, 'The request body must contain at least one field to update.');
        }

        if ([] !== $errors) {
            throw DomainException::validationFailed(...$errors);
        }

        return new self(
            $title,
            $color,
            $barcodeFormat,
            $barcodeValue,
            $merchantLabelPresent,
            $merchantLabel,
            $notePresent,
            $note,
            $expiresOnPresent,
            $expiresOn,
        );
    }
}
