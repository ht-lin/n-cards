<?php

declare(strict_types=1);

namespace App\Module\Identity\Application\Session;

use App\Shared\Domain\Error\DomainException;
use App\Shared\Domain\Error\FieldError;
use App\Shared\Domain\Error\FieldErrorCode;

/**
 * `POST /v1/auth/token/refresh` 的请求体（契约的 `TokenRefresh`）。
 *
 * 校验逻辑手写、不用 symfony/validator，理由见 `AbstractApiController` 的类注释
 * （那个包没装，装不装是另一张卡的决定）。形状照抄
 * {@see \App\Module\Identity\Application\Otp\OtpRequestPayload}。
 *
 * ============================================================================
 * ⚠️ 只校验**形状**，不校验令牌本身
 * ============================================================================
 * 长度上下界来自契约（`minLength: 32` / `maxLength: 512`），它们挡的是
 * 「空串」与「有人往这里灌 10 MB」，不是「这个令牌对不对」。
 * 后者只有 {@see RefreshTokenService} 能回答，而它的答案永远是同一个 401。
 *
 * 所以这里的 400 与那边的 401 **不重叠**：一个畸形请求体在任何情况下都拿不到
 * 401，而任何长度合法的字符串在这里都会放行。
 */
final readonly class RefreshTokenPayload
{
    /** 契约 `TokenRefresh.refresh_token.minLength`。32 字节 base64url 是 43 个字符，留出余量。 */
    private const MIN_LENGTH = 32;

    /** 契约 `TokenRefresh.refresh_token.maxLength`。 */
    private const MAX_LENGTH = 512;

    /** 契约 `TokenRefresh` 的全部属性。 */
    private const ALLOWED_FIELDS = ['refresh_token'];

    private function __construct(public string $refreshToken)
    {
    }

    /**
     * @param array<string, mixed> $body
     *
     * @throws DomainException `validation_failed`（400）
     */
    public static function fromArray(array $body): self
    {
        // ⚠️ 全部字段校验完再抛，口径同 OtpRequestPayload：客户端要一次拿到
        // 所有字段错误，逐个抛会让它改一个提交一次。
        $errors = [];

        foreach (array_diff(array_keys($body), self::ALLOWED_FIELDS) as $unknown) {
            // 主动拒绝未知字段的理由见 docs/api/README.md 的「第 5 条在请求方向上的含义」。
            // 宽松地忽略它们会让「客户端把 refresh_token 写成驼峰」这种 bug
            // 表现为「令牌无效」（401），而那是一条完全错误的排查方向。
            $errors[] = new FieldError($unknown, FieldErrorCode::UnknownField, 'This endpoint does not accept the field.');
        }

        $token = self::readToken($body, $errors);

        if ([] !== $errors) {
            throw DomainException::validationFailed(...$errors);
        }

        \assert(null !== $token);

        return new self($token);
    }

    /**
     * @param array<string, mixed> $body
     * @param list<FieldError>     $errors
     */
    private static function readToken(array $body, array &$errors): ?string
    {
        $token = $body['refresh_token'] ?? null;

        if (null === $token) {
            $errors[] = new FieldError('refresh_token', FieldErrorCode::Required, 'The refresh token is required.');

            return null;
        }

        if (!\is_string($token)) {
            $errors[] = new FieldError('refresh_token', FieldErrorCode::InvalidType, 'The refresh token must be a string.');

            return null;
        }

        // ⚠️ 文案里**不回显**长度或令牌片段 —— 这条错误会进日志，而参数本身是一个凭据。
        // `PiiRedactionProcessor` 认得 `refresh_token` 这个**键名**，
        // 但认不出被拼进一句英文里的一段字面量。
        if (\strlen($token) < self::MIN_LENGTH) {
            $errors[] = new FieldError('refresh_token', FieldErrorCode::TooShort, 'The refresh token is too short.');

            return null;
        }

        if (\strlen($token) > self::MAX_LENGTH) {
            $errors[] = new FieldError('refresh_token', FieldErrorCode::TooLong, 'The refresh token is too long.');

            return null;
        }

        return $token;
    }
}
