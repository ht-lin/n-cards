<?php

declare(strict_types=1);

namespace App\Module\Identity\Application\Magic;

use App\Module\Identity\Application\Session\DeviceDescriptor;
use App\Shared\Domain\Error\DomainException;
use App\Shared\Domain\Error\FieldError;
use App\Shared\Domain\Error\FieldErrorCode;

/**
 * `POST /v1/auth/magic/consume` 的请求体（契约里的 `MagicLinkConsumption`）。
 *
 * 手写校验的理由、以及为什么放在 `Application/Magic/` 而不是 `Application/Dto/`，
 * 与 {@see \App\Module\Identity\Application\Otp\OtpVerificationPayload} 逐字相同
 * （本类持有 {@see DeviceDescriptor}，而 deptrac 的 `Identity.Dto` 允许列表里
 * 没有本模块的 Domain）。
 *
 * ============================================================================
 * ⚠️ `token` 永远不进任何错误文案
 * ============================================================================
 * 与 `OtpVerificationPayload` 对 `code` 的纪律**同一档**，理由更硬一点：
 * 这个令牌**一步就能换到一个会话**（Magic Link 不需要再输任何东西）。
 * 一旦它进了 detail 字符串，就会同时躺在应用日志、Sentry 与任何抓过 4xx 响应的
 * 中间层里 —— 而它在 10 分钟内都还是有效的。
 *
 * `PiiRedactionProcessor` 兜的是结构化 context 里的键，兜不住 detail 字符串，
 * 所以这条只能靠这里不写。长度不对时只说「长度必须在 32 到 512 之间」，
 * 不说收到了什么。
 *
 * ============================================================================
 * 为什么这里也要 `device`
 * ============================================================================
 * 契约的 `MagicLinkConsumption` 是 `required: [token, device]` —— 与
 * `OtpVerification` 一样。理由不是对称性，是**这个端点签发的是一次真实登录**：
 * 没有 `device` 就建不出 `devices` 行，也就没有 JWT 的 `did` claim、
 * 没有设备管理页上的那一行、没有远程登出的抓手。
 *
 * ⚠️ 直接后果是**浏览器不能调用本端点**：`device.platform` 的取值域只有
 * `android`，而 `device.id` 是安装级的客户端生成 UUID。落地页
 * （`https://app.n-cards.de/l/magic/*`）因此不发这个请求，它只把令牌交给 App
 * （见 §7.1 第 4 条与 `infra/caddy/site/l/magic/index.html`）。
 */
final readonly class MagicLinkConsumptionPayload
{
    /** 契约 `MagicLinkConsumption.token` 的 `minLength`。 */
    public const TOKEN_MIN_LENGTH = 32;

    /** 契约 `MagicLinkConsumption.token` 的 `maxLength`。 */
    public const TOKEN_MAX_LENGTH = 512;

    /** 本端点认识的全部顶层字段。多一个就是 400 `unknown_field`。 */
    private const ALLOWED_FIELDS = ['token', 'device'];

    private function __construct(
        public string $token,
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

        $token = self::readToken($body, $errors);
        $device = DeviceDescriptor::fromValue($body['device'] ?? null, $errors);

        if ([] !== $errors) {
            throw DomainException::validationFailed(...$errors);
        }

        // 两个 null 分支在上面必然已经记了错误，走不到这里。断言只为让 PHPStan
        // 收窄类型 —— 用 assert 而不是 if/throw：这不是可能发生的输入，是代码错误。
        \assert(null !== $token && null !== $device);

        return new self($token, $device);
    }

    /**
     * @param array<string, mixed> $body
     * @param list<FieldError>     $errors
     */
    private static function readToken(array $body, array &$errors): ?string
    {
        $raw = $body['token'] ?? null;

        if (null === $raw) {
            $errors[] = new FieldError('token', FieldErrorCode::Required, 'The token field is required.');

            return null;
        }

        if (!\is_string($raw)) {
            $errors[] = new FieldError('token', FieldErrorCode::InvalidType, 'The token field must be a string.');

            return null;
        }

        // ⚠️ 长度上限**不是**装饰：本端点免鉴权，而下一步是一次 SHA-256 与一次
        // 索引查询。没有上限的话，一个 10 MB 的 "token" 也会被照单全收地哈希掉。
        // 契约里的 512 是这条闸，比真实长度（43 字符）宽出一大截是为了给
        // 将来换编码留余地。
        //
        // ⚠️ 按**字节**判而不是 mb_strlen：这是一个 base64url 串，不是文本，
        // 而这里挡的是「进来了多少数据」。与 `device.model` 的口径相反，
        // 那边判的是「用户看到多少字」，所以那边必须 mb_strlen。
        $length = \strlen($raw);

        if ($length < self::TOKEN_MIN_LENGTH || $length > self::TOKEN_MAX_LENGTH) {
            // ⚠️ 绝不回显 $raw —— 它一步就能换到一个会话，见类注释。
            $errors[] = new FieldError(
                'token',
                $length < self::TOKEN_MIN_LENGTH ? FieldErrorCode::TooShort : FieldErrorCode::TooLong,
                \sprintf('The token must be between %d and %d characters.', self::TOKEN_MIN_LENGTH, self::TOKEN_MAX_LENGTH),
            );

            return null;
        }

        return $raw;
    }
}
