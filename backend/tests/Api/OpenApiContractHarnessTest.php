<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Tests\Api\Support\OpenApiContract;
use cebe\openapi\spec\MediaType;
use cebe\openapi\spec\Operation;
use cebe\openapi\spec\Response as SpecResponse;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * T-007 的验收标准：**一个故意与契约不符的响应能让契约测试失败**。
 *
 * ============================================================================
 * 为什么这条测试不发真实 HTTP 请求
 * ============================================================================
 * 契约里的十个端点一个都还没实现 —— Auth 属于 T-103/T-104，Cards 属于 T-109。
 * 但「契约测试底座能咬人」这件事必须在**今天**就被证明，否则等到 T-109 才第一次
 * 发现校验器接线错了，中间三个任务都是在没有契约门禁的情况下写的。
 *
 * 所以这里用契约**自己的 example** 当夹具，成对地验两件事：
 *
 *   1. 正向：每一个 `example` 按契约声明的媒体类型与响应头发出来，都必须通过校验。
 *      这同时把 `example` 从「文档里的装饰」变成了**可执行的断言** ——
 *      写错的示例会在这里红，而不是等到有人照着它写客户端。
 *   2. 反向：把同一个 example 改坏（删必填字段 / 改类型 / 改媒体类型 / 改状态码），
 *      校验器**必须**拒绝。这一半才是验收标准，也是唯一能防住
 *      「校验器悄悄退化成空跑」的东西。
 *
 * ============================================================================
 * 夹具为什么不放 tests/Fixture/Contract/*.json
 * ============================================================================
 * 那样会立刻产生第二个真相源：契约改了、夹具没改，测试照样绿。直接从契约里取
 * example，结构上就不可能漂。
 *
 * 等 T-109 之后真实端点存在了，`tests/Api` 里针对那些端点的测试会用
 * {@see OpenApiContract::assertResponseMatchesContract()} 走真实 HTTP 往返；
 * 本文件继续留着守「校验器本身还在工作」这一层。
 */
#[CoversNothing]
final class OpenApiContractHarnessTest extends TestCase
{
    use OpenApiContract;

    /**
     * 契约里每一个带 example 的响应。
     *
     * @return iterable<string, array{string, string, int, string}>
     */
    public static function documentedResponses(): iterable
    {
        foreach (self::contractValidator()->getSchema()->paths->getPaths() as $template => $pathItem) {
            foreach ($pathItem->getOperations() as $method => $operation) {
                foreach ($operation->responses ?? [] as $status => $response) {
                    // `default` 一类的非数字键不是状态码，跳过。
                    if (!ctype_digit((string) $status)) {
                        continue;
                    }

                    foreach ($response->content ?? [] as $mediaType => $content) {
                        // 没有 example 的响应（如 204，压根没有 content）没什么可喂的。
                        if (null === $content->example) {
                            continue;
                        }

                        yield \sprintf('%s %s -> %s (%s)', strtoupper((string) $method), $template, $status, $mediaType) => [(string) $method, (string) $template, (int) $status, (string) $mediaType];
                    }
                }
            }
        }
    }

    /**
     * 正向：契约里写的每个示例，作为真实响应发出来都合法。
     */
    #[DataProvider('documentedResponses')]
    public function testEveryDocumentedExampleSatisfiesItsOwnContract(
        string $method,
        string $template,
        int $status,
        string $mediaType,
    ): void {
        self::assertResponseMatchesContract(
            $method,
            self::requestPathFor($template),
            self::responseFrom($method, $template, $status, $mediaType),
        );
    }

    /**
     * 反向 —— **这组就是 T-007 的验收标准**。
     *
     * 每种坏法都取一个具体的、真实会犯的错误：
     *   - 少一个必填字段：后端忘了序列化某个属性
     *   - 类型不对：`revision` 从 int 变成 string（PHP 里 `"7"` 与 `7` 太容易混）
     *   - 超出长度上限：`title` 越过 §7.5 的 100 字符
     *   - 枚举值不在表里：新增了一个 code 却没同步 problem-details.schema.json
     *   - 媒体类型不对：错误响应发成了 application/json 而不是 problem+json
     *   - 状态码不在契约里：端点返回了一个从未声明过的状态
     *
     * @param \Closure(array<string, mixed>): array<string, mixed> $break
     */
    #[DataProvider('deliberateViolations')]
    public function testDeliberatelyNonConformingResponsesAreRejected(
        string $method,
        string $template,
        int $status,
        string $mediaType,
        \Closure $break,
        string $because,
    ): void {
        /** @var array<string, mixed> $example */
        $example = self::exampleOf($method, $template, $status, $mediaType);

        self::assertResponseViolatesContract(
            $method,
            self::requestPathFor($template),
            self::psrResponse($status, $mediaType, $break($example), self::headersFor($method, $template, $status)),
            $because,
        );
    }

    /**
     * @return iterable<string, array{string, string, int, string, \Closure(array<string, mixed>): array<string, mixed>, string}>
     */
    public static function deliberateViolations(): iterable
    {
        yield '缺少必填字段 Card.revision' => [
            'get', '/cards/{cardId}', 200, 'application/json',
            static function (array $body): array {
                unset($body['revision']);

                return $body;
            },
            'Card 的 required 里有 revision',
        ];

        yield 'Card.revision 类型不对（string 而非 integer）' => [
            'get', '/cards/{cardId}', 200, 'application/json',
            static function (array $body): array {
                $body['revision'] = '7';

                return $body;
            },
            'revision 是 integer，不是数字字符串',
        ];

        yield 'Card.title 超出 100 字符上限' => [
            'get', '/cards/{cardId}', 200, 'application/json',
            static function (array $body): array {
                $body['title'] = str_repeat('x', 101);

                return $body;
            },
            '§7.5：title 上限 100 字符',
        ];

        yield 'Card.my_role 是一个不存在的角色' => [
            'get', '/cards/{cardId}', 200, 'application/json',
            static function (array $body): array {
                $body['my_role'] = 'editor';

                return $body;
            },
            'v1.1 的角色只有 owner 与 viewer，editor 已移除',
        ];

        yield 'Problem.code 不在 ErrorCode 枚举里' => [
            'get', '/cards/{cardId}', 404, 'application/problem+json',
            static function (array $body): array {
                $body['code'] = 'card_vanished';

                return $body;
            },
            'code 枚举由 problem-details.schema.json 钉死，加 code 必须同步那份文件',
        ];

        yield 'Problem 缺少 request_id' => [
            'get', '/cards/{cardId}', 404, 'application/problem+json',
            static function (array $body): array {
                unset($body['request_id']);

                return $body;
            },
            'request_id 是 required —— 恒存在，取不到时为 null 而不是缺席',
        ];

        yield 'CardPage.has_more 类型不对（string 而非 boolean）' => [
            'get', '/cards', 200, 'application/json',
            static function (array $body): array {
                $body['has_more'] = 'true';

                return $body;
            },
            '列表信封的 has_more 是 boolean',
        ];

        yield 'Session 缺少 user' => [
            'post', '/auth/otp/verify', 200, 'application/json',
            static function (array $body): array {
                unset($body['user']);

                return $body;
            },
            '客户端靠 user.onboarding_complete 决定是否跳 username 设定页',
        ];
    }

    /**
     * 媒体类型不对：错误响应发成 `application/json`。
     *
     * 单独一条，因为它坏的不是 body 而是 Content-Type ——
     * 而这正是 Android 侧最容易把错误响应喂给成功解析器的那条路。
     */
    public function testErrorResponseSentAsPlainJsonIsRejected(): void
    {
        self::assertResponseViolatesContract(
            'get',
            '/v1/cards/0192f3a1-b2c3-7d4e-8f01-23456789abcd',
            self::psrResponse(
                404,
                'application/json',
                self::exampleOf('get', '/cards/{cardId}', 404, 'application/problem+json'),
                self::headersFor('get', '/cards/{cardId}', 404),
            ),
            'RFC 9457 要求错误响应用 application/problem+json',
        );
    }

    /**
     * 状态码根本不在契约里。
     */
    public function testUndeclaredStatusCodeIsRejected(): void
    {
        self::assertResponseViolatesContract(
            'get',
            '/v1/cards/0192f3a1-b2c3-7d4e-8f01-23456789abcd',
            self::psrResponse(418, 'application/json', ['brewing' => false], []),
            '契约里没有声明 418',
        );
    }

    /**
     * 守住上面那一整组的前提：契约里**真的**有这些操作。
     *
     * 没有这一条的话，把 openapi.yaml 里的 Cards 整段删掉会让
     * deliberateViolations 的每个用例都在 contractOperation() 里失败 ——
     * 那是一堆看不懂的红，而不是一句「契约少了端点」。
     */
    public function testContractCoversTheOperationsThisHarnessDependsOn(): void
    {
        $resolved = [];

        foreach ([
            ['get', '/v1/cards'],
            ['post', '/v1/cards'],
            ['get', '/v1/cards/0192f3a1-b2c3-7d4e-8f01-23456789abcd'],
            ['patch', '/v1/cards/0192f3a1-b2c3-7d4e-8f01-23456789abcd'],
            ['delete', '/v1/cards/0192f3a1-b2c3-7d4e-8f01-23456789abcd'],
            ['put', '/v1/cards/0192f3a1-b2c3-7d4e-8f01-23456789abcd/placement'],
            ['post', '/v1/auth/otp/request'],
            ['post', '/v1/auth/otp/verify'],
            ['post', '/v1/auth/magic/consume'],
            ['post', '/v1/auth/token/refresh'],
            ['post', '/v1/auth/logout'],
        ] as [$method, $path]) {
            $operation = self::contractOperation($method, $path);
            $resolved[] = $operation->method().' '.$operation->path();
        }

        self::assertSame(
            [
                'get /cards',
                'post /cards',
                'get /cards/{cardId}',
                'patch /cards/{cardId}',
                'delete /cards/{cardId}',
                'put /cards/{cardId}/placement',
                'post /auth/otp/request',
                'post /auth/otp/verify',
                'post /auth/magic/consume',
                'post /auth/token/refresh',
                'post /auth/logout',
            ],
            $resolved,
            '真实路径到契约操作的映射不对 —— 通常意味着 servers 的 /v1 base path 没被正确剥掉，'
            .'或者某个路径模板被另一个抢先匹配了。',
        );
    }

    // ========================================================================
    // 夹具构造
    // ========================================================================

    /**
     * 把契约里的路径模板变成一条真实的请求路径：补上 servers 的 `/v1`，
     * 并把 `{cardId}` 一类的占位符填成合法值。
     */
    private static function requestPathFor(string $template): string
    {
        return '/v1'.str_replace('{cardId}', '0192f3a1-b2c3-7d4e-8f01-23456789abcd', $template);
    }

    /**
     * @return array<string, mixed>
     */
    private static function exampleOf(string $method, string $template, int $status, string $mediaType): array
    {
        $content = self::mediaTypeOf($method, $template, $status, $mediaType);

        self::assertNotNull(
            $content->example,
            \sprintf('契约里 %s %s -> %s (%s) 没有 example', strtoupper($method), $template, $status, $mediaType),
        );

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode(json_encode($content->example, \JSON_THROW_ON_ERROR), true, 512, \JSON_THROW_ON_ERROR);

        return $decoded;
    }

    private static function responseFrom(string $method, string $template, int $status, string $mediaType): ResponseInterface
    {
        return self::psrResponse(
            $status,
            $mediaType,
            self::exampleOf($method, $template, $status, $mediaType),
            self::headersFor($method, $template, $status),
        );
    }

    /**
     * 契约给这个响应声明的响应头，取各自的 example 值。
     *
     * 必须真的带上：`X-Request-Id` 是 `required: true`，`429` 还必须同时带
     * `Retry-After` 与 `X-RateLimit-Remaining`（§7.5）。少带一个，正向用例就会
     * 红在 header 校验上 —— 那也正是我们希望它能查出来的东西。
     *
     * @return array<string, string>
     */
    private static function headersFor(string $method, string $template, int $status): array
    {
        $headers = [];

        foreach (self::specResponse($method, $template, $status)->headers ?? [] as $name => $header) {
            if (!$header instanceof \cebe\openapi\spec\Header || null === $header->example) {
                continue;
            }

            $headers[(string) $name] = (string) json_decode(json_encode($header->example, \JSON_THROW_ON_ERROR), true, 512, \JSON_THROW_ON_ERROR);
        }

        return $headers;
    }

    private static function specResponse(string $method, string $template, int $status): SpecResponse
    {
        $paths = self::contractValidator()->getSchema()->paths;
        $pathItem = $paths[$template] ?? null;

        self::assertNotNull($pathItem, '契约里没有路径 '.$template);

        /** @var Operation|null $operation */
        $operation = $pathItem->getOperations()[strtolower($method)] ?? null;

        self::assertNotNull($operation, \sprintf('契约里 %s 没有 %s 操作', $template, strtoupper($method)));

        $response = $operation->responses[(string) $status] ?? null;

        self::assertInstanceOf(
            SpecResponse::class,
            $response,
            \sprintf('契约里 %s %s 没有声明 %d', strtoupper($method), $template, $status),
        );

        return $response;
    }

    private static function mediaTypeOf(string $method, string $template, int $status, string $mediaType): MediaType
    {
        $content = self::specResponse($method, $template, $status)->content[$mediaType] ?? null;

        self::assertInstanceOf(
            MediaType::class,
            $content,
            \sprintf('契约里 %s %s -> %d 没有 %s', strtoupper($method), $template, $status, $mediaType),
        );

        return $content;
    }

    /**
     * @param array<string, mixed>  $body
     * @param array<string, string> $headers
     */
    private static function psrResponse(int $status, string $mediaType, array $body, array $headers): ResponseInterface
    {
        $factory = new Psr17Factory();

        $response = $factory->createResponse($status)
            ->withHeader('Content-Type', $mediaType)
            ->withBody($factory->createStream(json_encode($body, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES)));

        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }
}
