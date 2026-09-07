<?php

declare(strict_types=1);

namespace App\Module\Identity\Application\Me;

use App\Shared\Domain\Error\DomainException;
use App\Shared\Domain\Error\FieldError;
use App\Shared\Domain\Error\FieldErrorCode;

/**
 * `POST /v1/me/username` 的请求体（契约的 `UsernameAssignment`）。
 *
 * 形状照抄 {@see \App\Module\Identity\Application\Session\PushTokenPayload}。
 *
 * ============================================================================
 * ⚠️ 这里**只管 JSON 形状**，不管名字合不合法
 * ============================================================================
 * 两种失败的错误码不同，而客户端要按它们分支：
 *
 *   - 字段缺失 / 类型不对 / 多了未知字段 → **400 `validation_failed`**，
 *     带 `errors[]`。这是**客户端 bug**：契约写着 `required: [username]`
 *     且 `username` 是 string，发成别的样子说明请求根本没照契约构造。
 *   - 名字太短 / 含非法字符 / 是保留词 → **422 `username_invalid`**。
 *     这是**用户输入问题**：请求完全合法，只是这个名字不行，
 *     UI 要做的是在输入框下面标红并让用户改，不是报「出错了」。
 *
 * 把两者压成一个码，Android 侧（T-151）就没法决定该显示哪种 UI。
 * 所以格式判定整个归 `Identity\Domain\ValueObject\Username`，本类不碰。
 *
 * ⚠️ 同理，本类**不做** trim / 小写化。归一化是 `Username::fromInput()` 的第一步，
 * 在两处都做的话，「归一化只有一个入口」这条前提就没了 —— 而
 * `GET /v1/users/lookup`（T-2xx）也要走同一条归一化，两边分叉的症状是
 * 「Anna 设了名字，Bob 搜不到她」，而两边的代码单独看都对。
 */
final readonly class UsernamePayload
{
    /** 契约 `UsernameAssignment` 的全部属性。多一个就是 `unknown_field`。 */
    private const ALLOWED_FIELDS = ['username'];

    /**
     * @param string $username **原样**的用户输入，未归一化。归一化归值对象
     */
    public function __construct(public string $username)
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

        // ⚠️ 这里**故意**不区分「省略该键」与「显式发了 null」，两者都是 `required`。
        //
        // 与 {@see \App\Module\Identity\Application\Session\PushTokenPayload} 相反 ——
        // 那边 `null` 是一个有意义的值（「清除推送令牌」），所以必须用
        // `array_key_exists()` 把两者分开。这里没有任何东西是 null 能表达的：
        // username 一旦设定就不可变，也就不存在「清空」这回事。
        // 于是两种输入对客户端意味着完全相同的一件事 ——「你没给我一个名字」——
        // 报成两个不同的 code 只会让 T-151 的错误处理多一个分支而拿不到任何信息。
        $username = $body['username'] ?? null;

        if (null === $username) {
            $errors[] = new FieldError('username', FieldErrorCode::Required, 'The username field is required.');

            throw DomainException::validationFailed(...$errors);
        }

        if (!\is_string($username)) {
            $errors[] = new FieldError('username', FieldErrorCode::InvalidType, 'The username must be a string.');
        }

        if ([] !== $errors) {
            throw DomainException::validationFailed(...$errors);
        }

        \assert(\is_string($username));

        return new self($username);
    }
}
