<?php

declare(strict_types=1);

namespace App\Module\Identity\Application\Session;

use App\Module\Identity\Domain\ValueObject\DevicePlatform;
use App\Shared\Domain\Error\FieldError;
use App\Shared\Domain\Error\FieldErrorCode;
use App\Shared\Domain\Identity\Uuid;

/**
 * 契约里的 `Device` 对象 —— 一次登录随身上报的「这次安装是谁」。
 *
 * ============================================================================
 * 为什么它不再长在请求体类里
 * ============================================================================
 * T-104 时它只有一个来源（`OtpVerification`），所以那五个字段直接摊在
 * {@see \App\Module\Identity\Application\Otp\OtpVerificationPayload} 上。
 * T-106 起有了第二个（`MagicLinkConsumption`），而契约里两处引用的是**同一个**
 * `#/components/schemas/Device`。
 *
 * 摊两份的代价不是「多打一遍字」，是**两份会分叉**：`model` 的 `maxLength`、
 * 空串与缺字段的合流、`mb_strlen` 而不是 `strlen`——每一条都是踩过的坑
 * （注释见下），而分叉之后只有其中一条路径上有测试。
 *
 * ⚠️ 它放在 `Application/Session/` 而不是 `Application/Dto/`：它持有
 * {@see DevicePlatform}，而 deptrac 的 `Identity.Dto` 允许列表里没有本模块的
 * Domain（同一堵墙，`OtpRequestPayload` 的类注释第一次撞上它）。
 * 与 {@see SessionIssued} 同一个目录也是有意的 —— 那是「签发一次会话」
 * 的入参与出参。
 *
 * ============================================================================
 * ⚠️ `id` 是**客户端**生成的
 * ============================================================================
 * 安装级唯一，重装即新设备（§5.2）。服务端不生成它、也不能生成 ——
 * 那正是「重装即新设备」这条语义的来源。它同时是 JWT 的 `did` claim。
 */
final readonly class DeviceDescriptor
{
    /** 契约 `Device.model` 的 `maxLength`。 */
    public const MODEL_MAX_LENGTH = 100;

    /** 契约 `Device.os_version` / `Device.app_version` 的 `maxLength`。 */
    public const VERSION_MAX_LENGTH = 32;

    /** 本对象认识的全部字段。多一个就是 400 `unknown_field`。 */
    private const ALLOWED_FIELDS = ['id', 'platform', 'model', 'os_version', 'app_version'];

    /** 错误里的字段路径前缀。§6.1 用点号拼，客户端据此定位嵌套表单的具体一格。 */
    private const PATH = 'device.';

    private function __construct(
        public Uuid $id,
        public DevicePlatform $platform,
        public ?string $model,
        public ?string $osVersion,
        public ?string $appVersion,
    ) {
    }

    /**
     * 从请求体里的 `device` 值解析。
     *
     * ⚠️ **不抛异常**，把错误追加进 `$errors` 并返回 null —— 调用方要把
     * 顶层字段与本对象的错误**一起**抛，让客户端一次看到全部出错的格子
     * （理由见 `OtpRequestPayload::fromArray()`）。
     *
     * @param mixed            $raw    请求体里 `device` 键的原值，可能是任何类型
     * @param list<FieldError> $errors 就地追加
     */
    public static function fromValue(mixed $raw, array &$errors): ?self
    {
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

        foreach (array_diff(array_keys($raw), self::ALLOWED_FIELDS) as $unknown) {
            $errors[] = new FieldError(self::PATH.$unknown, FieldErrorCode::UnknownField, 'This endpoint does not accept the field.');
        }

        $id = self::readId($raw, $errors);
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

        return new self($id, $platform, $model, $osVersion, $appVersion);
    }

    /**
     * 新设备提醒信正文里那一行「Gerät: …」。
     *
     * 机型可能没上报（契约必填，服务端收得更宽）。回落到平台名而不是空串：
     * 信里写着「Gerät: {{ device_model }}」，空着比「Android」更没用。
     */
    public function displayName(): string
    {
        return $this->model ?? $this->platform->value;
    }

    /**
     * @param array<string, mixed> $device
     * @param list<FieldError>     $errors
     */
    private static function readId(array $device, array &$errors): ?Uuid
    {
        $raw = $device['id'] ?? null;

        if (null === $raw) {
            $errors[] = new FieldError(self::PATH.'id', FieldErrorCode::Required, 'The id field is required.');

            return null;
        }

        if (!\is_string($raw)) {
            $errors[] = new FieldError(self::PATH.'id', FieldErrorCode::InvalidType, 'The id field must be a string.');

            return null;
        }

        $uuid = Uuid::tryFromString($raw);

        if (null === $uuid) {
            // UUID 不是个人数据也不是凭据，但仍然不回显 —— 与其为每个字段
            // 单独判断「这个能不能回显」，不如统一不回显，少一处将来会判断错的地方。
            $errors[] = new FieldError(self::PATH.'id', FieldErrorCode::InvalidFormat, 'The id field must be a UUID.');

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
            $errors[] = new FieldError(self::PATH.'platform', FieldErrorCode::Required, 'The platform field is required.');

            return null;
        }

        if (!\is_string($raw)) {
            $errors[] = new FieldError(self::PATH.'platform', FieldErrorCode::InvalidType, 'The platform field must be a string.');

            return null;
        }

        $platform = DevicePlatform::tryFrom($raw);

        if (null === $platform) {
            // 取值域是闭合的（一期只有 android，§1.3 把 iOS 列在 OUT 里），
            // 所以可以安全地写进 detail —— 与 `locale` 同一口径。
            $errors[] = new FieldError(self::PATH.'platform', FieldErrorCode::InvalidFormat, 'The platform must be one of: android.');

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
            $errors[] = new FieldError(self::PATH.$field, FieldErrorCode::InvalidType, \sprintf('The %s field must be a string.', $field));

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
            $errors[] = new FieldError(self::PATH.$field, FieldErrorCode::TooLong, \sprintf('The %s must be at most %d characters.', $field, $maxLength));

            return null;
        }

        return $value;
    }
}
