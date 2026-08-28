<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine;

use App\Shared\Domain\Identity\Uuid;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Exception\InvalidType;
use Doctrine\DBAL\Types\Exception\ValueNotConvertible;
use Doctrine\DBAL\Types\Type;

/**
 * `Shared\Domain\Identity\Uuid` ↔ Postgres 原生 `uuid` 列。
 *
 * 走 `getGuidTypeDeclarationSQL()` 拿到 PG 的原生 `uuid`（16 字节）而不是
 * `VARCHAR(36)`。对 §9.3 假设的 75 万行 `cards` 表，这是索引大小与比较成本上的
 * 实质差别，不是洁癖。
 *
 * ============================================================================
 * DBAL 4 的三处差异（从 3.x 抄代码的话会踩）
 * ============================================================================
 * 1. `getName()` 已删除 —— 注册名完全由 config/packages/doctrine.yaml 的
 *    `dbal.types` 键决定。这里留一个 {@see NAME} 常量供别处引用。
 * 2. `requiresSQLCommentHint()` 已删除 —— DBAL 4 不再写 doctrine 类型注释，
 *    所以不会有 schema diff 噪声。
 * 3. 异常搬到了 `Types\Exception\` 下，`ConversionException::conversionFailed()`
 *    已经没有了。
 *
 * ⚠️ 给 T-101：ORM 落地时要在 doctrine.yaml 里补 `mapping_types: { uuid: uuid }`，
 * 否则反向工程与 `doctrine:schema:validate` 会把 PG 的原生 `uuid` 映回 DBAL
 * 内建的 `guid`（string），而不是本类型。
 */
final class UuidType extends Type
{
    /** 与 config/packages/doctrine.yaml 里 `dbal.types` 的键保持一致。 */
    public const NAME = 'uuid';

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getGuidTypeDeclarationSQL($column);
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }

        if ($value instanceof Uuid) {
            return $value->toString();
        }

        // 照收字符串，但**必须**过一遍校验与归一化 —— 否则大小写混写的值会以
        // 两种形态进库，而唯一约束是按字节比的。
        if (\is_string($value)) {
            $uuid = Uuid::tryFromString($value);

            if (null === $uuid) {
                throw ValueNotConvertible::new($value, self::NAME);
            }

            return $uuid->toString();
        }

        throw InvalidType::new($value, self::NAME, ['null', 'string', Uuid::class]);
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?Uuid
    {
        if (null === $value) {
            return null;
        }

        if ($value instanceof Uuid) {
            return $value;
        }

        if (!\is_string($value)) {
            throw InvalidType::new($value, self::NAME, ['null', 'string', Uuid::class]);
        }

        $uuid = Uuid::tryFromString($value);

        if (null === $uuid) {
            // 库里存了个非法 UUID —— 这是数据损坏，不是客户端输入问题，
            // 所以抛 DBAL 的异常（最终变成 500），而不是 DomainException。
            throw ValueNotConvertible::new($value, self::NAME);
        }

        return $uuid;
    }
}
