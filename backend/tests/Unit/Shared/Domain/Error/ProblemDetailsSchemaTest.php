<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Domain\Error;

use App\Shared\Domain\Error\ErrorCode;
use App\Shared\Domain\Error\FieldErrorCode;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * `docs/api/schemas/problem-details.schema.json` 与 PHP enum 的**防漂移**测试。
 *
 * ============================================================================
 * 这个文件存在的唯一理由
 * ============================================================================
 * T-004 选择「先落一份 JSON Schema 文件」来满足验收标准里的
 * 「契约测试能对 Problem Details schema 校验通过」（T-007 的 openapi.yaml 还不存在）。
 *
 * 这个选择自带一个风险：schema 文件会变成**第二个真相源**，然后和 ErrorCode
 * 悄悄漂开 —— 加了个 code 忘了改 schema，契约测试照样绿，直到 Android
 * 按 schema 生成的 sealed class 撞上一个它不认识的 code。
 *
 * 所以这条测试把两者钉死：schema 的 enum 数组必须与 `ErrorCode::cases()`
 * **逐项相等，含顺序**。少一个、多一个、顺序不同，都红。
 * 有了它，schema 文件就不再是第二个真相源，而是 enum 的一份被强制同步的投影。
 *
 * T-007 之后 openapi.yaml 用 `$ref` 引这个文件（见文件里的 $comment），
 * 本测试继续有效，不需要改。
 */
#[CoversNothing]
final class ProblemDetailsSchemaTest extends TestCase
{
    private const SCHEMA_PATH = __DIR__.'/../../../../../../docs/api/schemas/problem-details.schema.json';

    /**
     * @return array<string, mixed>
     */
    private static function schema(): array
    {
        $path = realpath(self::SCHEMA_PATH);

        self::assertIsString($path, 'schema 文件不存在：'.self::SCHEMA_PATH);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) file_get_contents($path), true, 512, \JSON_THROW_ON_ERROR);

        return $decoded;
    }

    public function testSchemaIsValidJsonAndDeclaresDraft202012(): void
    {
        $schema = self::schema();

        self::assertSame('https://json-schema.org/draft/2020-12/schema', $schema['$schema']);
        self::assertSame('object', $schema['type']);
    }

    /**
     * ⚠️ 本测试的核心断言。
     */
    public function testErrorCodeEnumMatchesTheSchemaExactly(): void
    {
        /** @var array<string, mixed> $properties */
        $properties = self::schema()['properties'];
        /** @var array<string, mixed> $code */
        $code = $properties['code'];

        self::assertSame(
            array_map(static fn (ErrorCode $c): string => $c->value, ErrorCode::cases()),
            $code['enum'],
            "schema 的 code 枚举与 ErrorCode 不一致。\n"
            .'新增/删除 ErrorCode 时必须同步改 docs/api/schemas/problem-details.schema.json —— '
            .'否则 Android（T-010）会按 schema 生成一份缺项的 ApiError。',
        );
    }

    public function testFieldErrorCodeEnumMatchesTheSchemaExactly(): void
    {
        /** @var array<string, mixed> $properties */
        $properties = self::schema()['properties'];
        /** @var array<string, mixed> $errors */
        $errors = $properties['errors'];
        /** @var array<string, mixed> $items */
        $items = $errors['items'];
        /** @var array<string, mixed> $itemProperties */
        $itemProperties = $items['properties'];
        /** @var array<string, mixed> $code */
        $code = $itemProperties['code'];

        self::assertSame(
            array_map(static fn (FieldErrorCode $c): string => $c->value, FieldErrorCode::cases()),
            $code['enum'],
            'schema 的 errors[].code 枚举与 FieldErrorCode 不一致',
        );
    }

    /**
     * §6.1 的基础成员必须全部 required —— 客户端的解析形状恒定，不用处理「有时没这个键」。
     */
    public function testBaseMembersAreRequired(): void
    {
        self::assertSame(
            ['type', 'title', 'status', 'code', 'detail', 'instance', 'request_id'],
            self::schema()['required'],
        );
    }

    /**
     * 反过来：可选成员**绝不能**是 required。
     *
     * ApiProblemFactory 在它们为空时会省略整个成员（而不是发 []/{}），
     * 把它们标成 required 会让每一个不带字段错误的响应都校验失败。
     */
    public function testOptionalMembersAreNotRequired(): void
    {
        /** @var list<string> $required */
        $required = self::schema()['required'];

        foreach (['errors', 'current', 'debug'] as $optional) {
            self::assertNotContains($optional, $required, $optional.' 为空时会被省略，不能标成 required');
        }
    }

    /**
     * §13.1 第 5 条：所有 schema 必须 `additionalProperties: true`（前向兼容）。
     */
    public function testAllowsAdditionalPropertiesForForwardCompatibility(): void
    {
        $schema = self::schema();

        self::assertTrue($schema['additionalProperties']);

        /** @var array<string, mixed> $properties */
        $properties = $schema['properties'];
        /** @var array<string, mixed> $errors */
        $errors = $properties['errors'];
        /** @var array<string, mixed> $items */
        $items = $errors['items'];

        self::assertTrue($items['additionalProperties']);
    }

    /**
     * request_id 可以是 null（取不到时），但键必须在 —— 见 ApiProblemFactory。
     */
    public function testRequestIdAcceptsNull(): void
    {
        /** @var array<string, mixed> $properties */
        $properties = self::schema()['properties'];
        /** @var array<string, mixed> $requestId */
        $requestId = $properties['request_id'];

        self::assertSame(['string', 'null'], $requestId['type']);
    }
}
