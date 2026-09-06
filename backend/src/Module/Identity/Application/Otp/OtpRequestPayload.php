<?php

declare(strict_types=1);

namespace App\Module\Identity\Application\Otp;

use App\Module\Identity\Domain\ValueObject\Locale;
use App\Shared\Domain\Error\DomainException;
use App\Shared\Domain\Error\FieldError;
use App\Shared\Domain\Error\FieldErrorCode;

/**
 * `POST /v1/auth/otp/request` 的请求体（契约里的 `OtpRequest`）。
 *
 * ============================================================================
 * 为什么是手写校验，而不是 symfony/validator
 * ============================================================================
 * {@see \App\Shared\Http\Controller\AbstractApiController} 的类注释把
 * 「装不装 validator」明确留给了第一个 T-1xx 端点，也就是本任务。选了不装：
 *
 *   - §6.1 的 `errors[].code` 是一张**闭合的九项词表**
 *     （{@see FieldErrorCode}），而 validator 产出的是它自己的一套约束标识，
 *     中间必然要有一层映射表。那层映射是新的真相源，会与词表漂移。
 *   - 本端点只有两个字段。为它拉进一个组件、再在 Shared 里建一个
 *     `ConstraintViolationList → FieldError` 的参数解析器，是在**一个**样本上
 *     定死七个模块都要用的形状。
 *   - 反过来说这个决定不是不可逆的：真到了字段矩阵复杂的端点（T-109 的建卡），
 *     把 validator 加进来、让这里的 fromArray() 退化成一层薄封装即可。
 *
 * ============================================================================
 * 为什么在 Application/Otp/ 而不是 Application/Dto/
 * ============================================================================
 * deptrac 里 `Identity.Dto: [Shared.Domain]` —— **刻意不含本模块的 Domain**，
 * 因为 `Application/Dto/` 是给**别的模块**看的跨模块契约。而本类持有一个
 * {@see Locale}（Identity 的领域值对象），放进 Dto 层当场 violation。
 *
 * 它本来也不是跨模块契约，是本端点的内部入参。放在 `Application/` 下的子目录里
 * 归入 `Identity.Application` 图层，既能碰 Domain，也仍然能被 `Identity.Http` 引用。
 */
final readonly class OtpRequestPayload
{
    /**
     * §5.2 / 契约里的 `maxLength: 254` —— RFC 5321 对 forward-path 的上限。
     */
    public const EMAIL_MAX_LENGTH = 254;

    /**
     * 本端点认识的全部字段。多一个就是 400 `unknown_field`。
     */
    private const ALLOWED_FIELDS = ['email', 'locale'];

    /**
     * @param string $email **已归一化**（trim + 小写）的邮箱
     */
    private function __construct(
        public string $email,
        public Locale $locale,
    ) {
    }

    /**
     * @param array<string, mixed> $body 已由 `AbstractApiController::decodeBody()` 确认是 JSON 对象
     *
     * @throws DomainException `validation_failed`（400），`errors[]` 里带上全部出错字段
     */
    public static function fromArray(array $body): self
    {
        // ⚠️ 全部字段校验完再抛，不要遇到第一个错误就抛。客户端要一次拿到所有
        // 字段错误才能一次性把表单标红；逐个抛会让用户改一个提交一次。
        $errors = [];

        foreach (array_diff(array_keys($body), self::ALLOWED_FIELDS) as $unknown) {
            // 契约里 OtpRequest 是 `additionalProperties: true`，但那是给**客户端**的
            // 前向兼容（服务端将来加字段时老客户端不能崩），不是「服务端接受任意字段」。
            // 服务端主动拒绝的理由与做法见 docs/api/README.md 的「第 5 条在请求方向上的含义」。
            $errors[] = new FieldError($unknown, FieldErrorCode::UnknownField, 'This endpoint does not accept the field.');
        }

        $email = self::readEmail($body, $errors);
        $locale = self::readLocale($body, $errors);

        if ([] !== $errors) {
            throw DomainException::validationFailed(...$errors);
        }

        // 两个 null 分支在上面必然已经记了错误，走不到这里。断言只为让 PHPStan
        // 收窄类型 —— 用 assert 而不是 if/throw：这不是可能发生的输入，是代码错误。
        \assert(null !== $email && null !== $locale);

        return new self($email, $locale);
    }

    /**
     * @param array<string, mixed> $body
     * @param list<FieldError>     $errors
     */
    private static function readEmail(array $body, array &$errors): ?string
    {
        $raw = $body['email'] ?? null;

        if (null === $raw) {
            $errors[] = new FieldError('email', FieldErrorCode::Required, 'The email field is required.');

            return null;
        }

        if (!\is_string($raw)) {
            $errors[] = new FieldError('email', FieldErrorCode::InvalidType, 'The email field must be a string.');

            return null;
        }

        // ⚠️ 归一化必须发生在**算 HMAC 之前**，否则 `Anna@Example.com ` 与
        // `anna@example.com` 会算出两条不同的 email_hash，同一个人能注册两次
        // （UserRepositoryInterface::findByEmailHash() 的注释点名了这条）。
        //
        // 用 mb_strtolower 而不是 strtolower：后者按当前 C locale 逐字节转，
        // 对非 ASCII 的本地部分行为取决于环境。这里显式给 UTF-8。
        $email = mb_strtolower(trim($raw), 'UTF-8');

        // 长度在格式之前判：一个 100 KB 的字符串没必要送进 filter_var。
        if (\strlen($email) > self::EMAIL_MAX_LENGTH) {
            $errors[] = new FieldError('email', FieldErrorCode::TooLong, \sprintf('The email must be at most %d bytes.', self::EMAIL_MAX_LENGTH));

            return null;
        }

        if (false === filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            // ⚠️ 报错文案里**绝不**回显 $email：它会进日志与 Sentry，
            // 而这里恰恰是一个未经任何处理的原始邮箱（§8.2 / PiiRedactionProcessor
            // 只兜住结构化 context 里叫 `email` 的键，兜不住 detail 字符串）。
            $errors[] = new FieldError('email', FieldErrorCode::InvalidFormat, 'The email is not a valid address.');

            return null;
        }

        return $email;
    }

    /**
     * @param array<string, mixed> $body
     * @param list<FieldError>     $errors
     */
    private static function readLocale(array $body, array &$errors): ?Locale
    {
        $raw = $body['locale'] ?? null;

        if (null === $raw) {
            $errors[] = new FieldError('locale', FieldErrorCode::Required, 'The locale field is required.');

            return null;
        }

        if (!\is_string($raw)) {
            $errors[] = new FieldError('locale', FieldErrorCode::InvalidType, 'The locale field must be a string.');

            return null;
        }

        $locale = Locale::tryFrom($raw);

        if (null === $locale) {
            // 值域是闭合的两项，所以可以安全地把它们写进 detail —— 与邮箱不同,
            // `de` / `en` 不是个人数据。
            $errors[] = new FieldError('locale', FieldErrorCode::InvalidFormat, 'The locale must be one of: de, en.');

            return null;
        }

        return $locale;
    }
}
