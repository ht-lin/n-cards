<?php

declare(strict_types=1);

namespace App\Module\Wallet\Application\Card;

use App\Module\Wallet\Domain\ValueObject\BarcodeFormat;
use App\Shared\Domain\Error\DomainException;
use App\Shared\Domain\Error\FieldError;
use App\Shared\Domain\Error\FieldErrorCode;
use App\Shared\Domain\Identity\Uuid;

/**
 * `POST /v1/cards` 的请求体（契约的 `CardCreate`）。
 *
 * 形状照抄 {@see \App\Module\Identity\Application\Me\ProfileUpdatePayload}：
 * 静态 `fromArray()`、先扫未知字段、逐字段累积 {@see FieldError}、最后一次性抛。
 *
 * ============================================================================
 * ⚠️ `id` 由客户端生成，而且必须是 UUIDv7
 * ============================================================================
 * §5.4.3 的离线优先前提：用户在没网的地方加的卡，本地就已经有了最终 id，
 * 联网后原样上行，不需要「本地临时 id → 服务端真 id」的重映射。
 *
 * 版本号**是要校验的**，不是随便一个 UUID 都收：
 *
 *   - UUIDv7 的前 48 位是毫秒时间戳，于是主键天然按创建时间聚簇。
 *     §9.3 的容量假设是 75 万行 —— 收一个 UUIDv4 进来会在 B-tree 里随机落点，
 *     把插入热点打散成全表范围的随机写。
 *   - `findOwnedPage()` 拿 `id` 当 keyset 游标键，靠的正是它单调递增
 *     （见 `CardRepositoryInterface::findOwnedPage()`）。乱序的 id 不会让分页
 *     出错（游标只管窗口位置），但会让「按 id 升序」不再等于「按创建时间升序」，
 *     而客户端 bootstrap 时是按这个顺序填本地库的。
 *
 * 所以发 v4 是 `400 validation_failed`，不是静默接受。T-010 的生成器
 * 与 Android 侧的 id 生成器都产 v7，客户端不该踩到这条。
 */
final readonly class CardCreatePayload
{
    /** 契约 `CardCreate` 的全部属性 = `id` + 内容字段。 */
    private const ALLOWED_FIELDS = ['id', ...CardFields::CONTENT_FIELDS];

    private const UUID_VERSION = 7;

    public function __construct(
        public Uuid $id,
        public string $title,
        public ?string $merchantLabel,
        public string $color,
        public BarcodeFormat $barcodeFormat,
        public string $barcodeValue,
        public ?string $note,
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

        CardFields::rejectUnknown($body, self::ALLOWED_FIELDS, $errors);

        $id = self::readId($body, $errors);
        $title = CardFields::requiredString($body, 'title', $errors);
        $color = CardFields::requiredString($body, 'color', $errors);
        $barcodeValue = CardFields::requiredString($body, 'barcode_value', $errors);

        $barcodeFormat = \array_key_exists('barcode_format', $body)
            ? CardFields::barcodeFormat($body, $errors)
            : self::missing('barcode_format', $errors);

        $merchantLabel = CardFields::nullableString($body, 'merchant_label', $errors);
        $note = CardFields::nullableString($body, 'note', $errors);
        $expiresOn = CardFields::expiresOn($body, $errors);

        CardFields::checkMerchantLabelLength($merchantLabel, $errors);

        if ([] !== $errors) {
            throw DomainException::validationFailed(...$errors);
        }

        // 到这里四个必填项必然非 null（为 null 时上面已经记了错误）。
        // 断言只为让 PHPStan 收窄类型 —— 口径同 ProfileUpdatePayload::fromArray()。
        \assert(null !== $id && null !== $title && null !== $color);
        \assert(null !== $barcodeFormat && null !== $barcodeValue);

        return new self($id, $title, $merchantLabel, $color, $barcodeFormat, $barcodeValue, $note, $expiresOn);
    }

    /**
     * @param array<string, mixed> $body
     * @param list<FieldError>     $errors
     */
    private static function readId(array $body, array &$errors): ?Uuid
    {
        $raw = $body['id'] ?? null;

        if (null === $raw) {
            $errors[] = new FieldError('id', FieldErrorCode::Required, 'The id field is required; the client generates it as a UUIDv7.');

            return null;
        }

        if (!\is_string($raw)) {
            $errors[] = new FieldError('id', FieldErrorCode::InvalidType, 'The id field must be a string.');

            return null;
        }

        $id = Uuid::tryFromString($raw);

        if (null === $id) {
            $errors[] = new FieldError('id', FieldErrorCode::InvalidFormat, 'The id field must be a canonical UUID.');

            return null;
        }

        if (self::UUID_VERSION !== $id->version()) {
            // 版本号是 id 自己带的，回显它不泄露任何东西 —— 而这条错误
            // 最可能的读者是一个正在接客户端的开发者，说清楚能省他半小时。
            $errors[] = new FieldError('id', FieldErrorCode::InvalidFormat, \sprintf(
                'The id field must be a UUIDv7; got version %d.',
                $id->version(),
            ));

            return null;
        }

        return $id;
    }

    /**
     * @param list<FieldError> $errors
     */
    private static function missing(string $field, array &$errors): null
    {
        $errors[] = new FieldError($field, FieldErrorCode::Required, \sprintf('The %s field is required.', $field));

        return null;
    }
}
