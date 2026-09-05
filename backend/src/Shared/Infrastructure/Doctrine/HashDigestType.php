<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine;

use App\Shared\Domain\Crypto\HashDigest;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Exception\InvalidType;
use Doctrine\DBAL\Types\Exception\ValueNotConvertible;
use Doctrine\DBAL\Types\Type;

/**
 * `Shared\Domain\Crypto\HashDigest` ↔ Postgres `BYTEA`。
 *
 * §17.1 里所有 `BYTEA` 列都用这个类型：`users.email_hash`、
 * `otp_challenges.{code_hash, magic_token_hash, request_ip_hash}`、
 * `sessions.{refresh_token_hash, previous_token_hash}`，
 * 以及 T-109 的 `cards.barcode_value_fingerprint`。
 *
 * ============================================================================
 * ⚠️ 读回来的是**资源句柄**，不是字符串
 * ============================================================================
 * pdo_pgsql 把 `BYTEA` 列作为 stream 交给 DBAL（DBAL 内建的 `BinaryType` 也做同样的
 * `stream_get_contents()`）。少了那一步的话，`convertToPHPValue()` 会拿到一个
 * `resource`，`strlen()` 直接抛 TypeError —— 而且**只在真库上复现**，
 * 单测里手喂字符串是看不出来的。所以 `tests/Integration` 里必须有一条真库往返用例。
 *
 * ============================================================================
 * 为什么不直接用 DBAL 内建的 `binary`
 * ============================================================================
 * 内建 `binary` 收发的是 `string`，那就等于放弃了 {@see HashDigest} 存在的全部理由
 * （挡住 hex 写进 BYTEA、挡住用 `===` 比摘要）。列声明两者是一样的
 * （PG 的 `getBinaryTypeDeclarationSQLSnippet()` 与 `getVarbinaryTypeDeclarationSQLSnippet()`
 * 都返回 `BYTEA`），差别只在 PHP 侧的类型。
 *
 * ⚠️ 与 {@see UuidType} 不同，本类型**不需要**在 `doctrine.yaml` 里配
 * `mapping_types: { bytea: hash_digest }`。原因是 `schema:validate` 的列比较走的是
 * `AbstractPlatform::getColumnDeclarationSQL()` 的**字符串相等**，不是类型对象相等：
 * 内省出来的 `bytea → BlobType` 与本类型生成的都是 `BYTEA`，能对上。
 * 真配了反而有害 —— 将来一个存二进制附件的 `BYTEA` 列会被反向工程成「摘要」。
 *
 * DBAL 4 的三处差异见 {@see UuidType} 的类注释（`getName()` 与
 * `requiresSQLCommentHint()` 已删除、异常搬到了 `Types\Exception\` 下）。
 */
final class HashDigestType extends Type
{
    /** 与 config/packages/doctrine.yaml 里 `dbal.types` 的键保持一致。 */
    public const NAME = 'hash_digest';

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        // fixed = true 走 getBinaryTypeDeclarationSQLSnippet()（PG：BYTEA）。
        // length 在 PG 上不影响声明，写出来是给别的平台与读代码的人看的：
        // 这一列恒为 HashDigest::BYTES 个字节。
        return $platform->getBinaryTypeDeclarationSQL([
            'length' => HashDigest::BYTES,
            'fixed' => true,
        ]);
    }

    /**
     * 绑定为二进制参数。少了这一句，pdo_pgsql 会把裸字节当文本发出去，
     * 遇到 0x00 就截断 —— 而摘要里出现 0x00 的概率是 1 - (255/256)^32 ≈ 12%。
     * 也就是说漏掉它，大约每八条摘要就有一条被静默截短。
     */
    public function getBindingType(): ParameterType
    {
        return ParameterType::BINARY;
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }

        if ($value instanceof HashDigest) {
            return $value->toRaw();
        }

        // 与 UuidType 不同，这里**不照收字符串**。
        // 一个长度对得上的字符串完全可能是 32 字节的明文或者被截断的 hex，
        // 而 BYTEA 列没有任何形态可供校验 —— 唯一能判定「这是不是摘要」的地方
        // 是它被 HashDigest 包起来的那一刻。放行 string 等于把闸门拆了。
        //
        // ⚠️ 传 `'<redacted>'` 而不是 $value：`InvalidType::new()` 对**标量**会
        // `var_export($value)` 进消息，而这个分支最典型的入参就是一段明文或 hex。
        throw InvalidType::new('<redacted>', self::NAME, ['null', HashDigest::class]);
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?HashDigest
    {
        if (null === $value) {
            return null;
        }

        if ($value instanceof HashDigest) {
            return $value;
        }

        if (\is_resource($value)) {
            $value = stream_get_contents($value);
        }

        if (!\is_string($value)) {
            throw InvalidType::new($value, self::NAME, ['null', 'string', 'resource', HashDigest::class]);
        }

        $digest = HashDigest::tryFromRaw($value);

        if (null === $digest) {
            // 库里存了个长度不对的摘要 —— 这是数据损坏，不是客户端输入问题，
            // 所以抛 DBAL 的异常（最终变成 500），而不是 DomainException。
            // 与 UuidType 处理非法 UUID 的口径一致。
            //
            // ⚠️ 只报长度，不报值。`ValueNotConvertible::new()` 会把 $value 截断到
            // 20 字节拼进消息，而「长度不对的 BYTEA」最可能的成因恰恰是有人往摘要列
            // 写了明文。长度是排查这条故障需要的全部信息，且不泄露任何原文。
            throw ValueNotConvertible::new(\sprintf('<%d bytes>', \strlen($value)), self::NAME);
        }

        return $digest;
    }
}
