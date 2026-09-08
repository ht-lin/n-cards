<?php

declare(strict_types=1);

namespace App\Module\Wallet\Application\Card;

use App\Module\Wallet\Domain\ValueObject\BarcodeFormat;
use App\Shared\Domain\Error\FieldError;
use App\Shared\Domain\Error\FieldErrorCode;

/**
 * `CardCreate` 与 `CardUpdate` 共用的字段解析。
 *
 * ============================================================================
 * 为什么提出来
 * ============================================================================
 * 两个 payload 认的是**同一批字段**（契约里 `CardUpdate` 就是 `CardCreate`
 * 去掉 `id`、且全部可选），只是必填性不同。各写一份的话，
 * 「`barcode_format` 的合法值有哪些」「`expires_on` 是什么格式」这类判断
 * 会有两个副本 —— 而它们漂移时的症状是 `POST` 收得下、`PATCH` 收不下
 * （或者反过来），且两条路径的契约测试都还是绿的。
 *
 * ⚠️ 这里**只做类型与格式**（→ `400 validation_failed`）。
 * §7.5 的长度限额（→ `422 limit_exceeded`）在服务层，因为它要注入
 * {@see \App\Shared\Domain\Limit\LimitEnforcer}，而 payload 是静态工厂。
 * 两者的错误码不同不是随手分的，见 {@see \App\Shared\Domain\Limit\SystemLimit}
 * 的类注释：「格式不对，换一个」与「你的额度满了」对客户端是两种处置。
 */
final readonly class CardFields
{
    /**
     * 契约 `CardCreate` / `CardUpdate` 的全部内容字段（不含 `id`）。
     *
     * ⚠️ `sort_order` / `is_pinned` **不在这里，而且永远不该在** ——
     * 它们是每成员私有的，存在 `card_members` 上，走
     * `PUT /v1/cards/{id}/placement`（T-110）。往这里加一行等于把共享功能做没了。
     * 它们出现在请求体里时会被未知字段扫描拒掉，这正是想要的行为。
     *
     * ⚠️ `owner_id` / `revision` / `encryption_scheme` 也不在：它们由服务端决定。
     */
    public const CONTENT_FIELDS = [
        'title',
        'merchant_label',
        'color',
        'barcode_format',
        'barcode_value',
        'note',
        'expires_on',
    ];

    /**
     * `merchant_label` 的字符上限（§17.1 的 `CHECK (char_length(...) <= 100)`）。
     *
     * ⚠️ 它**不是** §7.5 的额度，所以超了报 `400 validation_failed` + `too_long`，
     * 不是 `422 limit_exceeded` —— §7.5 的表里只有 title / note / payload 三项，
     * T-111 的交付物清单也逐字只列了那三项加卡数。
     * 这一条纯粹是契约的字段约束（`maxLength: 100`），拦在这里是为了不让
     * 库层的 CHECK 变成一个 500。
     */
    public const MERCHANT_LABEL_MAX_CHARS = 100;

    /**
     * 必填的非空字符串。
     *
     * @param array<string, mixed> $body
     * @param list<FieldError>     $errors
     */
    public static function requiredString(array $body, string $field, array &$errors): ?string
    {
        if (!\array_key_exists($field, $body)) {
            $errors[] = new FieldError($field, FieldErrorCode::Required, \sprintf('The %s field is required.', $field));

            return null;
        }

        return self::string($body, $field, $errors);
    }

    /**
     * 非空字符串（键已知存在）。
     *
     * 空串单列成 `too_short` 而不是并进 `invalid_type`：契约给 `title` 与
     * `barcode_value` 写的是 `minLength: 1`，而一个空标题是用户在输入框里
     * 真会造出来的东西 —— 客户端要把它标红，不是上报 Sentry。
     *
     * @param array<string, mixed> $body
     * @param list<FieldError>     $errors
     */
    public static function string(array $body, string $field, array &$errors): ?string
    {
        $raw = $body[$field] ?? null;

        if (!\is_string($raw)) {
            $errors[] = new FieldError($field, FieldErrorCode::InvalidType, \sprintf('The %s field must be a string.', $field));

            return null;
        }

        if ('' === $raw) {
            $errors[] = new FieldError($field, FieldErrorCode::TooShort, \sprintf('The %s field must not be empty.', $field));

            return null;
        }

        return $raw;
    }

    /**
     * 可空字符串。`null` 是**合法值**（表示「没有」/「清空」），不是「缺失」。
     *
     * 空串归一化成 `null`：契约里这些字段的语义是「有或没有」，
     * 而 `""` 与 `null` 在 UI 上长得一模一样。存两种表示的后果是同步逻辑
     * （§5.4.3 的三方合并）要为它们各写一个分支，而它们本该是同一件事。
     *
     * @param array<string, mixed> $body
     * @param list<FieldError>     $errors
     */
    public static function nullableString(array $body, string $field, array &$errors): ?string
    {
        $raw = $body[$field] ?? null;

        if (null === $raw) {
            return null;
        }

        if (!\is_string($raw)) {
            $errors[] = new FieldError($field, FieldErrorCode::InvalidType, \sprintf('The %s field must be a string or null.', $field));

            return null;
        }

        return '' === $raw ? null : $raw;
    }

    /**
     * @param array<string, mixed> $body
     * @param list<FieldError>     $errors
     */
    public static function barcodeFormat(array $body, array &$errors): ?BarcodeFormat
    {
        $raw = $body['barcode_format'] ?? null;

        if (!\is_string($raw)) {
            $errors[] = new FieldError('barcode_format', FieldErrorCode::InvalidType, 'The barcode_format field must be a string.');

            return null;
        }

        $format = BarcodeFormat::tryFrom($raw);

        if (null === $format) {
            // 值域是闭合且公开的（契约里逐个列了 13 个），所以可以安全地写进 detail
            // —— 与 locale 的 `de` / `en` 同一条：它们不是个人数据。
            $errors[] = new FieldError('barcode_format', FieldErrorCode::InvalidFormat, \sprintf(
                'The barcode_format must be one of: %s.',
                implode(', ', array_column(BarcodeFormat::cases(), 'value')),
            ));

            return null;
        }

        return $format;
    }

    /**
     * `expires_on`：`YYYY-MM-DD`，可空（§3.13）。
     *
     * ⚠️ 用 `!Y-m-d` 而不是 `Y-m-d`：叹号把**没被格式覆盖的字段全部归零**。
     * 不写的话 PHP 会拿「现在」补时分秒，于是同一个日期在一天里的不同时刻
     * 解出不同的值 —— 那会让 {@see \App\Module\Wallet\Domain\Entity\Card::changeExpiry()}
     * 的相等判断随机失效，症状是「什么都没改的 PATCH 却把 revision 加了 1」。
     *
     * ⚠️ 解出来之后还要**回头格式化比对**：PHP 的日期解析会**滚动**非法日期
     * （`2026-02-31` 解成 `2026-03-03`，不报错）。不比对的话用户会看到自己
     * 设的到期日被静默改成了别的日子。
     *
     * @param array<string, mixed> $body
     * @param list<FieldError>     $errors
     */
    public static function expiresOn(array $body, array &$errors): ?\DateTimeImmutable
    {
        $raw = $body['expires_on'] ?? null;

        if (null === $raw) {
            return null;
        }

        if (!\is_string($raw)) {
            $errors[] = new FieldError('expires_on', FieldErrorCode::InvalidType, 'The expires_on field must be a date string or null.');

            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw, new \DateTimeZone('UTC'));

        if (false === $date || $date->format('Y-m-d') !== $raw) {
            $errors[] = new FieldError('expires_on', FieldErrorCode::InvalidFormat, 'The expires_on field must be a calendar date in YYYY-MM-DD form.');

            return null;
        }

        return $date;
    }

    /**
     * 未知字段 → `unknown_field`。
     *
     * ⚠️ 契约里全部 schema 都是 `additionalProperties: true`（§13.6 允许新增字段），
     * 但**运行时**拒绝未知字段。这两件事不矛盾：契约的宽松是给**客户端的
     * 反序列化**定的（老客户端遇到新字段不能崩），而服务端收到不认识的字段
     * 只可能是客户端 bug —— 静默忽略会让它一直活着。
     * 口径同 `UsernamePayload` / `ProfileUpdatePayload`。
     *
     * @param array<string, mixed> $body
     * @param list<string>         $allowed
     * @param list<FieldError>     $errors
     */
    public static function rejectUnknown(array $body, array $allowed, array &$errors): void
    {
        foreach (array_diff(array_keys($body), $allowed) as $unknown) {
            $errors[] = new FieldError((string) $unknown, FieldErrorCode::UnknownField, 'This endpoint does not accept the field.');
        }
    }

    /**
     * `merchant_label` 的长度（见 {@see MERCHANT_LABEL_MAX_CHARS} 的注释）。
     *
     * @param list<FieldError> $errors
     */
    public static function checkMerchantLabelLength(?string $merchantLabel, array &$errors): void
    {
        if (null !== $merchantLabel && mb_strlen($merchantLabel) > self::MERCHANT_LABEL_MAX_CHARS) {
            $errors[] = new FieldError('merchant_label', FieldErrorCode::TooLong, \sprintf(
                'The merchant_label must be at most %d characters.',
                self::MERCHANT_LABEL_MAX_CHARS,
            ));
        }
    }
}
