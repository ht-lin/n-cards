<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine;

use App\Shared\Domain\Crypto\Ciphertext;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Exception\InvalidType;
use Doctrine\DBAL\Types\Exception\ValueNotConvertible;
use Doctrine\DBAL\Types\Type;

/**
 * `Shared\Domain\Crypto\Ciphertext` ↔ Postgres `TEXT`（§17.1 的 `*_encrypted` 列）。
 *
 * ============================================================================
 * 这个类型是 backend/README.md 那条规则的执行点
 * ============================================================================
 * 「`*_encrypted` 列的类型是 `Ciphertext`，不是 `string`」—— 光在实体属性上写
 * `Ciphertext` 是不够的：Doctrine 得知道怎么把它读写成一列 TEXT，否则只能退回
 * `type="text"` + `string` 属性，而那正是 {@see Ciphertext} 的类注释里说的
 * 「一次漏调 encrypt 的重构会安静地把会员卡号以明文写满整张表」。
 *
 * 用在 `users.email_encrypted`（T-101）、`cards.{barcode_value_encrypted, note_encrypted}`
 * （T-109）。
 *
 * ============================================================================
 * 声明为什么是 CLOB 而不是 STRING
 * ============================================================================
 * `getClobTypeDeclarationSQL()` 在 PG 上是 `TEXT`（无长度上限），
 * `getStringTypeDeclarationSQL()` 是 `VARCHAR(255)`。Transit 密文的长度随明文增长，
 * `cards.note_encrypted` 的明文上限是 §7.5 的 2000 字符 —— base64 之后远超 255。
 * 同时 PG 内省 `text` 得到 `TextType`，它的声明也是 `TEXT`，
 * 于是 `doctrine:schema:validate` 的列比较（走 `getColumnDeclarationSQL()` 字符串相等）
 * 能对上，不需要额外的 `mapping_types`。
 *
 * DBAL 4 的三处差异见 {@see UuidType} 的类注释。
 */
final class CiphertextType extends Type
{
    /** 与 config/packages/doctrine.yaml 里 `dbal.types` 的键保持一致。 */
    public const NAME = 'ciphertext';

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getClobTypeDeclarationSQL($column);
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }

        if ($value instanceof Ciphertext) {
            return $value->toString();
        }

        // **不照收字符串** —— 这正是整个类型存在的意义。放行 string 的话，
        // `$user->email_encrypted = $plaintextEmail` 会一路畅通地写进库，
        // 而 §5.3 的信封加密在那一刻就失效了，且不报任何错。
        //
        // ⚠️ 传 `'<redacted>'` 而不是 $value：`InvalidType::new()` 对**标量**会
        // `var_export($value)` 进消息，而这个分支最典型的入参就是一封明文邮箱。
        throw InvalidType::new('<redacted>', self::NAME, ['null', Ciphertext::class]);
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?Ciphertext
    {
        if (null === $value) {
            return null;
        }

        if ($value instanceof Ciphertext) {
            return $value;
        }

        if (!\is_string($value)) {
            throw InvalidType::new($value, self::NAME, ['null', 'string', Ciphertext::class]);
        }

        $ciphertext = Ciphertext::tryFromString($value);

        if (null === $ciphertext) {
            // 库里的这一列不是 `vault:vN:` 形态 —— 要么是历史脏数据，要么是明文，
            // 两种都是必须立刻被看见的事故，不能静默降级成 null。
            //
            // ⚠️ 这里**不用** `ValueNotConvertible::new($value, ...)`：它会把 $value
            // 拼进异常消息，而失败恰恰意味着「它可能是明文」。异常消息进的是日志的
            // `message` 字段，而 PiiRedactionProcessor（T-004）是**按键名**脱敏的，
            // 兜不住消息正文里的明文。所以只报列名与类型，不报值 ——
            // 与 `Ciphertext::fromString()` 那条「绝不把 $value 放进异常消息」同一条纪律。
            throw ValueNotConvertible::new('<redacted>', self::NAME);
        }

        return $ciphertext;
    }
}
