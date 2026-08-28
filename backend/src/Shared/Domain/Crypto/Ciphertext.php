<?php

declare(strict_types=1);

namespace App\Shared\Domain\Crypto;

/**
 * Vault Transit 的密文（§5.3 里那个 `vault:v1:BASE64`）。
 *
 * ============================================================================
 * 这个值对象存在的唯一理由：挡住「明文被写进 `_encrypted` 列」
 * ============================================================================
 * §17.1 的 DDL 里，`cards.barcode_value_encrypted` 与 `note_encrypted` 是 `TEXT`，
 * `users.email_encrypted` 也是 `TEXT`。数据库层**分不出**存进去的是密文还是明文 ——
 * 一次漏调 encrypt 的重构会安静地把会员卡号以明文写满整张表，而且不报任何错，
 * 直到某天有人 `SELECT` 才发现。§5.3 的整套信封加密会在那一刻失效。
 *
 * 所以类型系统是这里唯一靠得住的闸门：仓储层的签名收 `Ciphertext` 而不是 `string`，
 * 而 `Ciphertext` 只能从一个带 `vault:v<N>:` 前缀的字符串构造出来。
 * 明文不可能长成那样，于是「忘了加密」变成一个编译期/构造期错误。
 *
 * ============================================================================
 * 为什么不校验 base64 载荷本身
 * ============================================================================
 * 只认前缀，不解码后面那段。载荷是 Vault 的内部格式（nonce + 密文 + tag 的打包），
 * 格式细节归 Vault 所有，我们照抄照还即可。真的解不开时 Vault 会在 decrypt 时报错，
 * 那是权威判定；在这里自己发明一套校验只会在 Vault 换版本时误伤。
 */
final readonly class Ciphertext implements \JsonSerializable, \Stringable
{
    /**
     * `vault:v<正整数>:<非空载荷>`。
     *
     * 版本号必须是不带前导零的正整数：Transit 的 key version 从 1 开始递增，
     * `v0` 与 `v01` 都不是 Vault 会产出的形态，接受它们等于放宽了闸门。
     */
    private const PATTERN = '/^vault:v[1-9][0-9]*:.+$/';

    /**
     * @param string $value 已校验的完整密文，含 `vault:vN:` 前缀
     */
    private function __construct(private string $value)
    {
    }

    /**
     * @throws CryptoFailed 值不是 Vault Transit 密文的形态
     */
    public static function fromString(string $value): self
    {
        $ciphertext = self::tryFromString($value);

        if (null === $ciphertext) {
            // ⚠️ **绝不**把 $value 放进异常消息。这个方法最典型的失败场景就是
            // 「有人把明文当密文传了进来」—— 那 $value 就是一个会员卡号或邮箱，
            // 而 detail 会进日志与 Sentry（见 DomainException 的约束 1）。
            throw new CryptoFailed('Value is not a Vault Transit ciphertext.');
        }

        return $ciphertext;
    }

    /**
     * 校验失败时返回 null 而不是抛异常。
     *
     * 给「从数据库读出来的一列可能是历史脏数据」这类场景用：调用方通常想跳过
     * 那一行并记一条告警，而不是让整个列表请求 500。
     */
    public static function tryFromString(string $value): ?self
    {
        if (1 !== preg_match(self::PATTERN, $value)) {
            return null;
        }

        return new self($value);
    }

    /**
     * 密文的 key version。
     *
     * T-404 的 rewrap 用它挑「还停在旧版本上」的行：rewrap 之后这个数字会变大，
     * 全部行都到 v2 了才能把 `min_decryption_version` 提到 2（§5.3 轮换流程第 4 步）。
     */
    public function keyVersion(): int
    {
        // 前缀已由 PATTERN 保证，这里的解析不会失败。
        $rest = substr($this->value, \strlen('vault:v'));
        $colon = strpos($rest, ':');

        return (int) substr($rest, 0, (int) $colon);
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }

    public function jsonSerialize(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        // 密文不是秘密（§5.3：Vault 内部密钥永不出 Vault），普通比较即可，
        // 不需要 hash_equals —— 那是给 HMAC 摘要用的，见 HmacHasherInterface::verify()。
        return $this->value === $other->value;
    }
}
