<?php

declare(strict_types=1);

namespace App\Module\Identity\Domain\ValueObject;

use App\Shared\Domain\Error\DomainException;
use App\Shared\Domain\Error\ErrorCode;

/**
 * `users.username`（§3.8、§5.2）—— 全局唯一、**不可变**、公开可搜索的伪名。
 *
 * ============================================================================
 * 这个值对象存在的理由
 * ============================================================================
 * username 是 v1.1 之后**唯一**的社交检索键（`GET /v1/users/lookup` 的
 * `WHERE username = :normalized`），而检索键的等值查询要成立，前提是
 * 「同一个人输入的同一个名字每次都归一化成同一个字符串」。
 *
 * 归一化只要有第二处实现，两处就会分叉，而分叉的症状极难发现：
 * 设定时走 A 归一化、查找时走 B 归一化，于是 Anna 设了名字、Bob 搜不到她，
 * 两边的代码单独看都对。所以归一化与校验在服务端只有这一个入口，
 * 库层的 `chk_users_username_format` 是第二道防线（§17.1）。
 *
 * ============================================================================
 * ⚠️ 为什么是 strtolower() 而不是 mb_strtolower()
 * ============================================================================
 * §3.8 要求小写化必须是 `Locale.ROOT` 语义，并且点名了土耳其语的 `I → ı`：
 * 在 tr_TR locale 下 `strtolower('I')` 得到的不是 `i`，于是同一个名字在
 * 两台 locale 不同的机器上归一化成两个字符串，等值查询全线落空。
 *
 * PHP 8.2 起 `strtolower()` **不再受 locale 影响**，只做 ASCII A–Z 的映射 ——
 * 这正好就是 `Locale.ROOT` 语义，也正好覆盖字符集允许的全部字符。
 *
 * `mb_strtolower()` 是**错的**选择：它做完整的 Unicode 折叠，
 * `İ`（U+0130）会变成 `i̇`（i + U+0307），一个本该被字符集直接拒掉的输入
 * 就此变成两个码位、长度也变了。把土耳其语那条坑原样请了回来。
 *
 * 同理，非 ASCII 的同形异义字（西里尔 `а` U+0430 vs 拉丁 `a`）**不做**折叠 ——
 * 它们过不了 `^[a-z0-9_]{3,20}$`，在字符集那一步就被拒。§3.8 选字符集时
 * 「排除同形异义字」就是为了把这件事变成一条不需要维护的规则。
 *
 * ============================================================================
 * 与 User 实体的分工
 * ============================================================================
 * 本类管「这个名字合不合法」，{@see \App\Module\Identity\Domain\Entity\User::assignUsername()}
 * 管「能不能再写一次」。实体那边刻意收一个已归一化的 `string` 而不是本类 ——
 * 它守的是不变量（写过一次就不能再写），不是格式，
 * `UserTest::testDoesNotValidateTheUsernameFormat()` 把这条分工钉成了显式约定。
 *
 * ============================================================================
 * 为什么可以 toString()（而 HashDigest 不行）
 * ============================================================================
 * {@see \App\Shared\Domain\Crypto\HashDigest} 刻意既不 Stringable 也不
 * JsonSerializable，因为摘要是查找键、泄露一批等于泄露「这些邮箱有账号」。
 * username 相反：§3.8 明写它「是公开可搜索的伪名，不含个人数据即为设计目标」，
 * 本来就要出现在响应体里。所以给 `toString()`。
 *
 * 但仍然**不实现** `__toString()`：隐式转换会让它悄悄出现在字符串拼接与
 * 异常消息里，而 `detail` 会进日志与 Sentry —— 见下面 reject() 的注释。
 */
final readonly class Username
{
    /**
     * @param string $value 已归一化、已校验的值
     */
    private function __construct(private string $value)
    {
    }

    /**
     * 用户输入 → 归一化 → 校验。**服务端唯一的 username 入口。**.
     *
     * 顺序是有意的：先归一化再校验，否则 `Anna_B ` 会因为大写和尾随空格被拒，
     * 而 §3.8 明写这两者与 `anna_b` 是同一个名字（「用户不会记得自己用的大小写」）。
     *
     * @param string $raw 原样的用户输入，可能带首尾空格与大小写
     *
     * @throws DomainException `username_invalid`（422）—— 长度、字符集或保留词
     */
    public static function fromInput(string $raw, UsernameRules $rules): self
    {
        // trim() 只吃 ASCII 空白。U+00A0（不换行空格）、U+200B（零宽空格）之类
        // 留着不管是对的 —— 它们过不了字符集，会在下面被拒成 username_invalid，
        // 而不是被悄悄抹掉后变成一个用户没打算要的名字。
        $normalized = strtolower(trim($raw));

        // 长度先于字符集：两者都不满足时，「太短」比「有非法字符」更接近用户
        // 真正需要做的事。长度按**字符**数，但归一化后的合法值必然是纯 ASCII，
        // 所以这里 mb_strlen 与 strlen 只在**非法**输入上才会不同 ——
        // 用 mb_strlen 是为了让「三个汉字」被判成长度 3 而不是 9，
        // 于是它拿到的是字符集错误（说得清），而不是长度错误（说不清）。
        $length = mb_strlen($normalized, 'UTF-8');

        if ($length < $rules->minChars() || $length > $rules->maxChars()) {
            self::reject(\sprintf(
                'The username must be between %d and %d characters long.',
                $rules->minChars(),
                $rules->maxChars(),
            ));
        }

        // 定界符在这里加 —— 配置里那个正则不带，因为同一个字符串还要喂给
        // Android 侧的 Kotlin Regex（T-010）。用 `#` 而不是 `/`：
        // 万一 Q9 之后有人往字符集里加了斜杠，`/` 会让整个模式静默失效。
        if (1 !== preg_match('#'.$rules->pattern().'#', $normalized)) {
            self::reject('The username may only contain lowercase letters, digits and underscores.');
        }

        if ($rules->isReserved($normalized)) {
            // ⚠️ 文案里**不能**说「这个词被保留了」之外的任何东西，尤其不能
            // 回显是哪个词 —— 见 reject()。客户端拿到的是 code，不是这句话。
            self::reject('The username is reserved and cannot be used.');
        }

        return new self($normalized);
    }

    /**
     * 归一化后的小写值。直接进 `users.username` 列，也直接进响应体。
     */
    public function toString(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    /**
     * ⚠️ `$detail` 里**绝不**出现用户输入的那个字符串。
     *
     * `DomainException` 的约束 1 写着「detail 会进日志与 Sentry」，而
     * `ApiProblemExceptionListener` 已经为同一个理由把 `instance` 从
     * `getRequestUri()` 收敛成了 `getPathInfo()`（§3.8-C4：不让 username
     * 出现在每个错误体、每条日志与每个 Sentry 报告里）。
     * 在这里把候选名字拼进 detail，等于把那处修补绕过去。
     *
     * 三条文案都是**固定串**，所以它们也是安全的：说清楚规则，不复述输入。
     *
     * @phpstan-return never
     *
     * @throws DomainException 恒抛
     */
    private static function reject(string $detail): never
    {
        throw new DomainException(ErrorCode::UsernameInvalid, $detail);
    }
}
