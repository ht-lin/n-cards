<?php

declare(strict_types=1);

namespace App\Shared\Domain\Crypto;

/**
 * 一条 32 字节的 HMAC-SHA256 / SHA-256 **裸摘要**，也就是 §17.1 里那些 `BYTEA` 列。
 *
 * ============================================================================
 * 这个值对象存在的两个理由
 * ============================================================================
 * 与 {@see Ciphertext} 是同一套论证 —— 数据库层分不出存进 `BYTEA` 的是什么，
 * 所以类型系统是唯一靠得住的闸门。具体挡两件事：
 *
 * 1. **挡住 hex / base64 / 明文被写进摘要列。**
 *    `hash_hmac(..., $binary = false)` 返回的是 64 个 hex 字符，长度恰好也「看起来
 *    很像摘要」。写进 BYTEA 不会报任何错，直到某天 `email_hash` 查找全部落空、
 *    而 §3.8 的「邮箱不存明文列」就此变成「邮箱谁也查不到」。
 *    本类只接受**恰好 32 字节**，hex 形态是 64 字节，构造期就被拒。
 *
 * 2. **挡住用 `===` 比摘要。**
 *    §7.1 要求 OTP 码的比较是常量时间的。`===` 会在第一个不同的字节上短路，
 *    于是比较耗时泄露「猜对了前几个字节」。把比较收敛到 {@see equals()} 一处，
 *    调用方就没有写错的机会 —— 见下面 equals() 的注释。
 *
 * ============================================================================
 * 用在哪
 * ============================================================================
 *   - `users.email_hash`（T-101）
 *   - `otp_challenges.{code_hash, magic_token_hash, request_ip_hash}`（T-101 建列，
 *     T-103/T-104/T-106 填）
 *   - `sessions.{refresh_token_hash, previous_token_hash}`（T-101 建列，T-105 轮换）
 *   - `cards.barcode_value_fingerprint`（T-109 重复卡检测）
 *
 * ⚠️ 本类**不改** {@see \App\Shared\Application\Crypto\HmacHasherInterface} 的签名 ——
 * 那个接口收发的仍然是 `string`（32 字节裸摘要），因为它是 Vault 门面，
 * 说的是「字节进、字节出」。转换发生在调用点：`HashDigest::fromRaw($hasher->hash($email))`。
 *
 * ============================================================================
 * 为什么既不是 Stringable 也不是 JsonSerializable
 * ============================================================================
 * 刻意的。摘要不该被顺手 `echo` 进日志，也不该被序列化进响应体 —— 它虽然算不回
 * 原文，但它是**查找键**：泄露一批 `email_hash` 等于泄露「这些邮箱在我们这儿有账号」，
 * 而 §3.8 的整套防枚举就是为了不泄露这件事。要拿字节请显式调 {@see toRaw()}，
 * 那是一个能被 review 看见的动作。
 */
final readonly class HashDigest
{
    /** SHA-256 输出的字节数。三个用途（HMAC-SHA256、SHA-256、Vault HMAC）都是这个长度。 */
    public const BYTES = 32;

    /**
     * @param string $value 已校验的 32 字节裸摘要
     */
    private function __construct(private string $value)
    {
    }

    /**
     * @param string $bytes **裸字节**，不是 hex、不是 base64
     *
     * @throws CryptoFailed 长度不是 32 字节
     */
    public static function fromRaw(string $bytes): self
    {
        $digest = self::tryFromRaw($bytes);

        if (null === $digest) {
            // ⚠️ **绝不**把 $bytes 放进异常消息。这个方法最典型的失败场景是
            // 「有人把明文/hex 当摘要传了进来」—— 那 $bytes 就是一封邮箱或一个会员卡号，
            // 而 detail 会进日志与 Sentry（见 DomainException 的约束 1）。
            // 长度是安全的：它不含原文的任何信息。
            throw new CryptoFailed(\sprintf('Hash digest must be exactly %d raw bytes, got %d.', self::BYTES, \strlen($bytes)));
        }

        return $digest;
    }

    /**
     * 校验失败时返回 null 而不是抛异常。
     *
     * 与 {@see Ciphertext::tryFromString()} 同一个用途：「从数据库读出来的一列可能是
     * 历史脏数据」时，调用方通常想跳过那一行并记一条告警，而不是让整个请求 500。
     */
    public static function tryFromRaw(string $bytes): ?self
    {
        if (self::BYTES !== \strlen($bytes)) {
            return null;
        }

        return new self($bytes);
    }

    /**
     * 32 字节裸摘要，直接进 `BYTEA` 列。
     */
    public function toRaw(): string
    {
        return $this->value;
    }

    /**
     * **常量时间**比较（§7.1）。
     *
     * 用 `hash_equals` 而不是 `===`。`Ciphertext::equals()` 那边用普通比较是对的
     * —— 密文不是秘密；这里不是：`code_hash` 与 `refresh_token_hash` 的比较结果直接
     * 决定登录成败，比较耗时的差异是一条可用的侧信道。
     *
     * 注意 `hash_equals` 只在两个参数**等长**时才是常量时间的，而本类的构造保证了
     * 所有实例都是 32 字节 —— 这正是「把长度校验放进构造函数」换来的东西。
     */
    public function equals(self $other): bool
    {
        return hash_equals($this->value, $other->value);
    }
}
