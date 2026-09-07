<?php

declare(strict_types=1);

namespace App\Module\Identity\Application\Session;

use App\Shared\Domain\Error\DomainException;
use App\Shared\Domain\Error\FieldError;
use App\Shared\Domain\Error\FieldErrorCode;

/**
 * `PUT /v1/me/devices/{id}/push-token` 的请求体（契约的 `PushTokenUpdate`）。
 *
 * 形状照抄 {@see RefreshTokenPayload}。
 *
 * ============================================================================
 * `null` 是一个**合法值**，不是「没传」
 * ============================================================================
 * 用户在系统设置里关掉通知权限之后，客户端要把服务端这一侧也清掉 ——
 * 否则我们会继续往一个已经失效的 FCM 令牌上投递，而 §14.4 的
 * `fcm_send_total{result}` 会被一堆必然失败的投递污染。
 *
 * 所以 `{"push_token": null}` 与「省略 `push_token`」必须分得开：
 * 前者是「清除」，后者是**畸形请求**（400 `validation_failed`）。用 `array_key_exists()` 而不是
 * `?? null` 正是为了这个区分 —— 后者会把两者压成同一件事。
 *
 * ============================================================================
 * ⚠️ 不校验 FCM 令牌的格式
 * ============================================================================
 * 只校验长度上界。FCM 令牌的形状由 Google 定义，且历史上变过好几次
 *（旧的 152 字符固定长、新的 instance-id 形态长得多）。
 * 在这里写一个正则，等于把一个我们不控制的外部格式钉进服务端 ——
 * 下一次 Google 改格式时，症状是全体客户端上报失败、推送静默地全线停摆，
 * 而 T-306 那时还没上线，没人会去看这个端点。
 */
final readonly class PushTokenPayload
{
    /**
     * 上界纯粹是**防灌**，不是格式校验。取 4096：现役 FCM 令牌在 200 字符量级，
     * 留出二十倍余量，同时挡住「往这里塞 10 MB」。
     */
    private const MAX_LENGTH = 4096;

    /** 契约 `PushTokenUpdate` 的全部属性。 */
    private const ALLOWED_FIELDS = ['push_token'];

    public function __construct(public ?string $pushToken)
    {
    }

    /**
     * @param array<string, mixed> $body
     *
     * @throws DomainException `validation_failed`（400）
     */
    public static function fromArray(array $body): self
    {
        $errors = [];

        foreach (array_diff(array_keys($body), self::ALLOWED_FIELDS) as $unknown) {
            $errors[] = new FieldError($unknown, FieldErrorCode::UnknownField, 'This endpoint does not accept the field.');
        }

        // ⚠️ array_key_exists，不是 isset / ?? —— 见类注释：null 是合法值，
        // 而「省略」是畸形请求。用 `??` 会把两者压成同一件事。
        if (!\array_key_exists('push_token', $body)) {
            $errors[] = new FieldError('push_token', FieldErrorCode::Required, 'The push token field is required; send null to clear it.');

            throw DomainException::validationFailed(...$errors);
        }

        $token = $body['push_token'];

        if (null !== $token && !\is_string($token)) {
            $errors[] = new FieldError('push_token', FieldErrorCode::InvalidType, 'The push token must be a string or null.');
        } elseif ('' === $token) {
            // 空串是客户端 bug（想清除就该发 null）。静默地当成「清除」会让
            // 那个 bug 一直活着，而它的另一面是「明明设置了却收不到推送」。
            $errors[] = new FieldError('push_token', FieldErrorCode::InvalidFormat, 'The push token must not be empty; send null to clear it.');
        } elseif (\is_string($token) && \strlen($token) > self::MAX_LENGTH) {
            $errors[] = new FieldError('push_token', FieldErrorCode::TooLong, 'The push token is too long.');
        }

        if ([] !== $errors) {
            throw DomainException::validationFailed(...$errors);
        }

        \assert(null === $token || \is_string($token));

        return new self($token);
    }
}
