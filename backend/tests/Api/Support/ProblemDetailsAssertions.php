<?php

declare(strict_types=1);

namespace App\Tests\Api\Support;

use App\Shared\Domain\Error\ErrorCode;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Assert;
use Symfony\Component\HttpFoundation\Response;

/**
 * 对着 `docs/api/schemas/problem-details.schema.json` 校验一个错误响应。
 *
 * T-004 的验收标准：「契约测试能对 Problem Details schema 校验通过」。
 *
 * schema 校验之外还断言了三件 JSON Schema 结构上表达不了、但恰恰是真正契约的事：
 *   1. `status` 成员、HTTP 状态行、`code` 的规范状态码，**三者一致**
 *   2. `type` 由 `code` 按 slug 规则派生
 *   3. `instance` 不含 query string（§3.8-C4 的 username 枚举泄露面）
 *
 * ============================================================================
 * T-007 之后：这**就是**契约校验，不需要再走一遍 openapi.yaml
 * ============================================================================
 * openapi.yaml 的 `components/schemas/Problem` 是一个**指向本 schema 文件的
 * `$ref`**（不是拷贝）。所以「对着这份 JSON Schema 校验」与「对着契约里的
 * Problem 校验」是同一件事 —— 再加一遍只会得到一条恒真的断言。
 *
 * 那个「是 $ref 而不是拷贝」的前提本身也是被守住的，在 {@see OpenApiContract}
 * 的两个邻居里：
 *   - `OpenApiDocumentTest::testProblemSchemaIsAReferenceToTheSharedFileNotACopy()`
 *     看**未解引用**的 yaml，确认它确实只是一个 `$ref`；
 *   - `OpenApiDocumentTest::testResolvedProblemCodeEnumMatchesErrorCode()`
 *     看**解引用之后**的结果，确认它真的解到了这份文件（$ref 指错、或路径变了
 *     导致解引用悄悄退化成空 schema，都会在这里红）。
 *
 * 整份契约的校验（路径匹配、状态码、响应头、body）走
 * {@see OpenApiContract::assertResponseMatchesContract()}；本 trait 只管
 * 错误响应的形状，两者不重叠。
 */
trait ProblemDetailsAssertions
{
    private static ?Validator $schemaValidator = null;

    /**
     * @return array<string, mixed> 解码后的 problem body
     */
    protected static function assertIsProblemDetails(Response $response, ErrorCode $expected): array
    {
        Assert::assertSame(
            'application/problem+json',
            $response->headers->get('Content-Type'),
            'RFC 9457 的媒体类型是 application/problem+json（不带 charset）',
        );

        Assert::assertSame(
            $expected->httpStatus(),
            $response->getStatusCode(),
            $expected->value.' 的 HTTP 状态行与 ErrorCode::httpStatus() 不符',
        );

        $raw = (string) $response->getContent();

        /** @var array<string, mixed> $body */
        $body = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);

        self::assertMatchesProblemSchema($raw);

        Assert::assertSame($expected->value, $body['code']);
        // 三方一致 —— body 里的 status 不能和状态行说两套话。
        Assert::assertSame($expected->httpStatus(), $body['status']);
        Assert::assertSame(
            'https://api.ncards.de/problems/'.$expected->slug(),
            $body['type'],
            'type URI 必须由 code 派生',
        );

        Assert::assertIsString($body['instance']);
        Assert::assertStringNotContainsString('?', $body['instance'], 'instance 绝不能含 query string');

        Assert::assertMatchesRegularExpression(
            '/^[\x20-\x7E]+$/',
            (string) $body['detail'],
            'detail 必须是英文开发者文案（§6.1：服务端不返回可展示的德语文案）',
        );

        return $body;
    }

    protected static function assertMatchesProblemSchema(string $rawJson): void
    {
        $validator = self::$schemaValidator ??= new Validator();

        $schemaPath = realpath(__DIR__.'/../../../../docs/api/schemas/problem-details.schema.json');
        Assert::assertIsString($schemaPath, 'Problem Details schema 文件不存在');

        $result = $validator->validate(
            json_decode($rawJson, false, 512, \JSON_THROW_ON_ERROR),
            (string) file_get_contents($schemaPath),
        );

        if ($result->isValid()) {
            return;
        }

        $error = $result->error();
        Assert::assertNotNull($error);

        Assert::fail(
            "响应不符合 Problem Details schema：\n"
            .json_encode((new ErrorFormatter())->format($error), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR)
            ."\n实际响应：\n".$rawJson,
        );
    }
}
