<?php

declare(strict_types=1);

namespace App\Module\Identity\Application\Me;

use App\Module\Identity\Domain\Entity\User;
use App\Module\Identity\Domain\ValueObject\Locale;
use App\Shared\Domain\Error\DomainException;
use App\Shared\Domain\Error\ErrorCode;
use App\Shared\Domain\Error\FieldError;
use App\Shared\Domain\Error\FieldErrorCode;

/**
 * `PATCH /v1/me` 的请求体（契约的 `MeUpdate`）。
 *
 * 形状照抄 {@see UsernamePayload}。
 *
 * ============================================================================
 * ⚠️⚠️ `username` 先判，而且抛的是 409 不是 400
 * ============================================================================
 * §6.2 逐字：「若请求体出现 `username` 字段 → `409 username_immutable`，
 * **不静默忽略**」。这条判定必须排在未知字段扫描**之前** —— 否则
 * `username` 会先落进 {@see ALLOWED_FIELDS} 的差集里，被报成
 * `400 validation_failed` + `unknown_field`，而那对客户端意味着
 * 「这个字段不存在」，也就是「静默忽略」的另一种说法。
 *
 * 判定按**键是否存在**（`array_key_exists`），不看值：`{"username": null}`
 * 与 `{"username": "anna_b"}` 一样是 409。想「清空」username 与想改它
 * 是同一件被禁止的事，而 §3.8 的不可变性里没有「清空」这个概念。
 *
 * ============================================================================
 * ⚠️ 为什么**不**走 `User::assignUsername()`
 * ============================================================================
 * T-107 的移交笔记写的是「`PATCH /v1/me` 收到 `username` 字段时调
 * `User::assignUsername()` 即得 409」。本卡有意偏离，理由是那只在
 * 「调用者已经有 username」时成立：
 *
 *   - 对一个**已完成** onboarding 的调用者，那个方法确实抛 409；
 *   - 对一个**未完成**的调用者，它会**真的把值写进去** —— 绕过
 *     `Username::fromInput()` 的归一化、字符集、保留词黑名单，绕过
 *     `findByUsername()` 的查重，也绕过 §7.5 的 10 次计数（ADR-0017）。
 *
 * 也就是说那条路径会开出**第二个** username 写入口，而它唯一的守卫是
 * `OnboardingListener::EXEMPT_ROUTES` 里恰好没有 `me_update` 这件事。
 * 把一条产品不变量的正确性挂在另一个类的白名单上，正是本仓库反复在拦的形状
 * —— `UsernameController` 的类注释（「不可变性是靠没有 PATCH 端点保证的，
 * 不是靠某处的一个 if」）说的就是同一件事。
 *
 * 所以这里直接抛，并与实体共用 {@see User::USERNAME_IMMUTABLE_DETAIL}：
 * 一处文案、两个调用方，且这一路根本不碰实体。
 *
 * ============================================================================
 * `locale` 非法是 400 不是 422
 * ============================================================================
 * 与 {@see UsernamePayload} 类注释里那段分工逐字同源：契约把 `locale` 写成
 * `enum: [de, en]`，发别的值说明请求根本没照契约构造 —— 那是**客户端 bug**，
 * 不是「用户输了个不能用的值」。422 留给后者（`username_invalid`）。
 * 压成一个码的话，T-151 就没法决定该标红输入框还是该上报 Sentry。
 */
final readonly class ProfileUpdatePayload
{
    /**
     * 契约 `MeUpdate` 的全部属性。
     *
     * ⚠️ `username` **不在这里**，而且永远不该在 —— 它有自己的分支（见类注释）。
     * `display_name` 也不在：v1.1 C10 把它从整个产品里移除了。
     */
    private const ALLOWED_FIELDS = ['locale'];

    public function __construct(public Locale $locale)
    {
    }

    /**
     * @param array<string, mixed> $body
     *
     * @throws DomainException `username_immutable`（409）/ `validation_failed`（400）
     */
    public static function fromArray(array $body): self
    {
        if (\array_key_exists('username', $body)) {
            throw new DomainException(ErrorCode::UsernameImmutable, User::USERNAME_IMMUTABLE_DETAIL);
        }

        $errors = [];

        foreach (array_diff(array_keys($body), self::ALLOWED_FIELDS) as $unknown) {
            $errors[] = new FieldError($unknown, FieldErrorCode::UnknownField, 'This endpoint does not accept the field.');
        }

        $locale = self::readLocale($body, $errors);

        if ([] !== $errors) {
            throw DomainException::validationFailed(...$errors);
        }

        // null 分支在上面必然已经记了错误，走不到这里。断言只为让 PHPStan
        // 收窄类型 —— 口径同 OtpRequestPayload::fromArray()。
        \assert(null !== $locale);

        return new self($locale);
    }

    /**
     * 逐字照搬 {@see \App\Module\Identity\Application\Otp\OtpRequestPayload::readLocale()}。
     *
     * ⚠️ 这里**没有**「省略即不改」的分支：`locale` 是目前唯一的字段，
     * 所以省略它等于一个什么都不改的 PATCH —— 那只可能是客户端 bug，
     * 报 `required` 比返回一个 200 no-op 更早暴露它。
     * （顺带：空对象 `{}` 更早一步就被 `AbstractApiController::decodeBody()`
     * 拒成 `400 malformed_request`，因为 `array_is_list([])` 恒为 true。）
     *
     * 通知偏好落地时这条要改成「至少给一个字段」，那是 §13.6 允许的放宽方向。
     *
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
