<?php

declare(strict_types=1);

namespace App\Module\Identity\Application\Otp;

use App\Module\Identity\Domain\ValueObject\DevicePlatform;
use App\Shared\Domain\Error\DomainException;
use App\Shared\Domain\Error\FieldError;
use App\Shared\Domain\Error\FieldErrorCode;
use App\Shared\Domain\Identity\Uuid;

/**
 * `POST /v1/auth/otp/verify` 的请求体（契约里的 `OtpVerification`）。
 *
 * 手写校验的理由、以及为什么放在 `Application/Otp/` 而不是 `Application/Dto/`，
 * 与 {@see OtpRequestPayload} 逐字相同（本类持有 {@see DevicePlatform}，
 * 而 deptrac 的 `Identity.Dto` 允许列表里没有本模块的 Domain）。
 *
 * ============================================================================
 * ⚠️ `code` 永远不进任何错误文案
 * ============================================================================
 * 与 `OtpRequestPayload` 里对 `email` 的处理同一条纪律，但更紧一档：
 * 邮箱是「不该记的个人数据」，而验证码是**一次性凭据**。
 * 一旦它进了 detail 字符串，就会同时躺在应用日志、Sentry 与任何抓过 4xx 响应的
 * 中间层里 —— 而它在 10 分钟内都还是有效的。
 *
 * `PiiRedactionProcessor` 兜的是结构化 context 里的键，兜不住 detail 字符串，
 * 所以这条只能靠这里不写。校验失败时只说「必须是 6 位数字」，不说收到了什么。
 *
 * ============================================================================
 * device 是嵌套对象，校验因此分两层
 * ============================================================================
 * 契约里 `device` 是一个必填的 `Device` 对象。字段路径按 §6.1 用点号拼
 * （`device.platform`），这样客户端能把错误定位到嵌套表单的具体一格。
 *
 * ⚠️ `device.id` 是**客户端生成**的（安装级唯一，重装即新设备，§5.2）。
 * 服务端不生成它、也不能生成 —— 那正是「重装即新设备」这条语义的来源。
 * 它同时是 JWT 的 `did` claim。
 */
final readonly class OtpVerificationPayload
{
    /** 契约 `Device.model` 的 `maxLength`。 */
    public const MODEL_MAX_LENGTH = 100;

    /** 契约 `Device.os_version` / `Device.app_version` 的 `maxLength`。 */
    public const VERSION_MAX_LENGTH = 32;

    /** §7.1：6 位数字。契约里的 `pattern: '^\d{6}$'`。 */
    private const CODE_PATTERN = '/^\d{6}$/';

    /** 本端点认识的全部顶层字段。多一个就是 400 `unknown_field`。 */
    private const ALLOWED_FIELDS = ['challenge_id', 'code', 'device'];

    /** `device` 对象里认识的全部字段。 */
    private const ALLOWED_DEVICE_FIELDS = ['id', 'platform', 'model', 'os_version', 'app_version'];

    private function __construct(
        public Uuid $challengeId,
        public string $code,
        public Uuid $deviceId,
        public DevicePlatform $platform,
        public ?string $model,
        public ?string $osVersion,
        public ?string $appVersion,
    ) {
    }

    /**
     * @param array<string, mixed> $body 已由 `AbstractApiController::decodeBody()` 确认是 JSON 对象
     *
     * @throws DomainException `validation_failed`（400），`errors[]` 里带上全部出错字段
     */
    public static function fromArray(array $body): self
    {
        // 全部字段校验完再抛，理由见 OtpRequestPayload::fromArray()。
        $errors = [];

        foreach (array_diff(array_keys($body), self::ALLOWED_FIELDS) as $unknown) {
            $errors[] = new FieldError($unknown, FieldErrorCode::UnknownField, 'This endpoint does not accept the field.');
        }

        $challengeId = self::readUuid($body, 'challenge_id', $errors);
        $code = self::readCode($body, $errors);
        $device = self::readDevice($body, $errors);

        if ([] !== $errors) {
            throw DomainException::validationFailed(...$errors);
        }

        // 三个 null 分支在上面必然已经记了错误，走不到这里。断言只为让 PHPStan
        // 收窄类型 —— 用 assert 而不是 if/throw：这不是可能发生的输入，是代码错误。
        \assert(null !== $challengeId && null !== $code && null !== $device);

        return new self(
            $challengeId,
            $code,
            $device['id'],
            $device['platform'],
            $device['model'],
            $device['os_version'],
            $device['app_version'],
        );
    }

    /**
     * @param array<string, mixed> $body
     * @param list<FieldError>     $errors
     */
    private static function readCode(array $body, array &$errors): ?string
    {
        $raw = $body['code'] ?? null;

        if (null === $raw) {
            $errors[] = new FieldError('code', FieldErrorCode::Required, 'The code field is required.');

            return null;
        }

        // ⚠️ 必须是**字符串**，不接受 JSON 数字。`418396` 作为数字进来会丢掉前导零
        // （§7.1 的码是 `000007` 这种形态，RequestOtpService::generateCode() 特意
        // str_pad 过），于是那批码永远对不上，而症状只出现在百万分之一的码上。
        if (!\is_string($raw)) {
            $errors[] = new FieldError('code', FieldErrorCode::InvalidType, 'The code field must be a string.');

            return null;
        }

        if (1 !== preg_match(self::CODE_PATTERN, $raw)) {
            // ⚠️ 绝不回显 $raw —— 它是一次性凭据，见类注释。
            $errors[] = new FieldError('code', FieldErrorCode::InvalidFormat, 'The code must be exactly 6 digits.');

            return null;
        }

        return $raw;
    }

    /**
     * @param array<string, mixed> $body
     * @param list<FieldError>     $errors
     *
     * @return array{id: Uuid, platform: DevicePlatform, model: ?string, os_version: ?string, app_version: ?string}|null
     */
    private static function readDevice(array $body, array &$errors): ?array
    {
        $raw = $body['device'] ?? null;

        if (null === $raw) {
            $errors[] = new FieldError('device', FieldErrorCode::Required, 'The device field is required.');

            return null;
        }

        // `array_is_list` 挡的是 `"device": [...]` —— json_decode 把 JSON 数组
        // 也解成 PHP array，不拦的话会一路走到 $raw['id'] 才以一个费解的类型错误炸掉。
        if (!\is_array($raw) || array_is_list($raw)) {
            $errors[] = new FieldError('device', FieldErrorCode::InvalidType, 'The device field must be a JSON object.');

            return null;
        }

        foreach (array_diff(array_keys($raw), self::ALLOWED_DEVICE_FIELDS) as $unknown) {
            $errors[] = new FieldError('device.'.$unknown, FieldErrorCode::UnknownField, 'This endpoint does not accept the field.');
        }

        $id = self::readUuid($raw, 'id', $errors, 'device.');
        $platform = self::readPlatform($raw, $errors);

        // 三个描述性字段在契约里是必填的，但服务端收得更宽：它们只用于设备管理页的
        // 展示与新设备提醒信的正文（§5.2），缺了不影响登录能不能成。
        // 库里对应的三列也都可空（Version20260905101500）。
        $model = self::readOptionalString($raw, 'model', self::MODEL_MAX_LENGTH, $errors);
        $osVersion = self::readOptionalString($raw, 'os_version', self::VERSION_MAX_LENGTH, $errors);
        $appVersion = self::readOptionalString($raw, 'app_version', self::VERSION_MAX_LENGTH, $errors);

        if (null === $id || null === $platform) {
            return null;
        }

        return [
            'id' => $id,
            'platform' => $platform,
            'model' => $model,
            'os_version' => $osVersion,
            'app_version' => $appVersion,
        ];
    }

    /**
     * @param array<string, mixed> $source
     * @param list<FieldError>     $errors
     */
    private static function readUuid(array $source, string $field, array &$errors, string $prefix = ''): ?Uuid
    {
        $raw = $source[$field] ?? null;

        if (null === $raw) {
            $errors[] = new FieldError($prefix.$field, FieldErrorCode::Required, \sprintf('The %s field is required.', $field));

            return null;
        }

        if (!\is_string($raw)) {
            $errors[] = new FieldError($prefix.$field, FieldErrorCode::InvalidType, \sprintf('The %s field must be a string.', $field));

            return null;
        }

        $uuid = Uuid::tryFromString($raw);

        if (null === $uuid) {
            // UUID 不是个人数据也不是凭据，但仍然不回显 —— 与其为每个字段
            // 单独判断「这个能不能回显」，不如统一不回显，少一处将来会判断错的地方。
            $errors[] = new FieldError($prefix.$field, FieldErrorCode::InvalidFormat, \sprintf('The %s field must be a UUID.', $field));

            return null;
        }

        return $uuid;
    }

    /**
     * @param array<string, mixed> $device
     * @param list<FieldError>     $errors
     */
    private static function readPlatform(array $device, array &$errors): ?DevicePlatform
    {
        $raw = $device['platform'] ?? null;

        if (null === $raw) {
            $errors[] = new FieldError('device.platform', FieldErrorCode::Required, 'The platform field is required.');

            return null;
        }

        if (!\is_string($raw)) {
            $errors[] = new FieldError('device.platform', FieldErrorCode::InvalidType, 'The platform field must be a string.');

            return null;
        }

        $platform = DevicePlatform::tryFrom($raw);

        if (null === $platform) {
            // 取值域是闭合的（一期只有 android，§1.3 把 iOS 列在 OUT 里），
            // 所以可以安全地写进 detail —— 与 `locale` 同一口径。
            $errors[] = new FieldError('device.platform', FieldErrorCode::InvalidFormat, 'The platform must be one of: android.');

            return null;
        }

        return $platform;
    }

    /**
     * @param array<string, mixed> $device
     * @param positive-int         $maxLength
     * @param list<FieldError>     $errors
     */
    private static function readOptionalString(array $device, string $field, int $maxLength, array &$errors): ?string
    {
        $raw = $device[$field] ?? null;

        if (null === $raw) {
            return null;
        }

        if (!\is_string($raw)) {
            $errors[] = new FieldError('device.'.$field, FieldErrorCode::InvalidType, \sprintf('The %s field must be a string.', $field));

            return null;
        }

        $value = trim($raw);

        if ('' === $value) {
            // 空串与「没传」等价 —— 客户端在拿不到机型时经常发空串而不是省略字段，
            // 两者存进库都该是 NULL。让它们在这里合流，读侧（设备管理页、提醒信）
            // 就只需要处理一种「不知道」。
            return null;
        }

        // ⚠️ mb_strlen 而不是 strlen：契约的 maxLength 是**字符**数，
        // 而机型名里有非 ASCII（`Xiaomi 红米 Note 13`）。按字节判会把合法机型误拒。
        if (mb_strlen($value, 'UTF-8') > $maxLength) {
            $errors[] = new FieldError('device.'.$field, FieldErrorCode::TooLong, \sprintf('The %s must be at most %d characters.', $field, $maxLength));

            return null;
        }

        return $value;
    }
}
