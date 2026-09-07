<?php

declare(strict_types=1);

namespace App\Module\Identity\Application\Otp;

use App\Module\Identity\Application\Session\DeviceDescriptor;
use App\Shared\Domain\Error\DomainException;
use App\Shared\Domain\Error\FieldError;
use App\Shared\Domain\Error\FieldErrorCode;
use App\Shared\Domain\Identity\Uuid;

/**
 * `POST /v1/auth/otp/verify` 的请求体（契约里的 `OtpVerification`）。
 *
 * 手写校验的理由、以及为什么放在 `Application/Otp/` 而不是 `Application/Dto/`，
 * 与 {@see OtpRequestPayload} 逐字相同（本类持有 {@see DeviceDescriptor}，
 * 而它又持有 `Identity.Domain` 的 `DevicePlatform`，
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
 * 契约里 `device` 是一个必填的 `Device` 对象，而**同一个** schema 也被
 * `MagicLinkConsumption` 引用（T-106）。它的解析因此住在
 * {@see DeviceDescriptor::fromValue()} 里，两个请求体共用一份 ——
 * 摊两份会分叉，理由写在那个类的注释里。
 */
final readonly class OtpVerificationPayload
{
    /** §7.1：6 位数字。契约里的 `pattern: '^\d{6}$'`。 */
    private const CODE_PATTERN = '/^\d{6}$/';

    /** 本端点认识的全部顶层字段。多一个就是 400 `unknown_field`。 */
    private const ALLOWED_FIELDS = ['challenge_id', 'code', 'device'];

    private function __construct(
        public Uuid $challengeId,
        public string $code,
        public DeviceDescriptor $device,
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

        $challengeId = self::readChallengeId($body, $errors);
        $code = self::readCode($body, $errors);
        $device = DeviceDescriptor::fromValue($body['device'] ?? null, $errors);

        if ([] !== $errors) {
            throw DomainException::validationFailed(...$errors);
        }

        // 三个 null 分支在上面必然已经记了错误，走不到这里。断言只为让 PHPStan
        // 收窄类型 —— 用 assert 而不是 if/throw：这不是可能发生的输入，是代码错误。
        \assert(null !== $challengeId && null !== $code && null !== $device);

        return new self($challengeId, $code, $device);
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
     */
    private static function readChallengeId(array $body, array &$errors): ?Uuid
    {
        $raw = $body['challenge_id'] ?? null;

        if (null === $raw) {
            $errors[] = new FieldError('challenge_id', FieldErrorCode::Required, 'The challenge_id field is required.');

            return null;
        }

        if (!\is_string($raw)) {
            $errors[] = new FieldError('challenge_id', FieldErrorCode::InvalidType, 'The challenge_id field must be a string.');

            return null;
        }

        $uuid = Uuid::tryFromString($raw);

        if (null === $uuid) {
            // UUID 不是个人数据也不是凭据，但仍然不回显 —— 与其为每个字段
            // 单独判断「这个能不能回显」，不如统一不回显，少一处将来会判断错的地方。
            $errors[] = new FieldError('challenge_id', FieldErrorCode::InvalidFormat, 'The challenge_id field must be a UUID.');

            return null;
        }

        return $uuid;
    }
}
