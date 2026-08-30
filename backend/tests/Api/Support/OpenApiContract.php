<?php

declare(strict_types=1);

namespace App\Tests\Api\Support;

use League\OpenAPIValidation\PSR7\Exception\ValidationFailed;
use League\OpenAPIValidation\PSR7\OperationAddress;
use League\OpenAPIValidation\PSR7\PathFinder;
use League\OpenAPIValidation\PSR7\ResponseValidator;
use League\OpenAPIValidation\PSR7\ValidatorBuilder;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Assert;
use Psr\Http\Message\ResponseInterface;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Component\HttpFoundation\Response;

/**
 * 对着 `docs/api/openapi.yaml` 校验真实 HTTP 响应（T-007 / §13.1 第 3 条）。
 *
 * ============================================================================
 * 它与 ProblemDetailsAssertions 的分工
 * ============================================================================
 * {@see ProblemDetailsAssertions} 只管错误响应的形状，直接对着 T-004 交付的
 * `docs/api/schemas/problem-details.schema.json` 校验。本 trait 管的是**整份契约**：
 * 路径能不能匹配到操作、状态码在不在契约里、响应头齐不齐、body 合不合 schema。
 *
 * 两者不重复：openapi.yaml 的 `components/schemas/Problem` 是一个指向那份 JSON
 * Schema 的 `$ref`，所以走本 trait 校验错误响应时，最终用的仍然是同一份 schema。
 *
 * ============================================================================
 * ⚠️ 契约里的路径**不带** `/v1`
 * ============================================================================
 * `servers[].url` 是 `https://api.n-cards.de/v1`，所以契约里写的是 `/cards`，
 * 而后端路由是 `/v1/cards`。这个落差由 {@see PathFinder} 消化 —— 它会拿
 * servers 的 base path 去试着剥前缀（这正是它存在的理由）。
 *
 * 所以本 trait 的方法一律收**真实的请求路径**（`/v1/cards/{...}`），
 * 调用方不需要自己记得去掉 `/v1`。真要写错了，`contractOperation()` 会失败并
 * 明确告诉你契约里有哪些路径。
 *
 * ============================================================================
 * 为什么整份契约只解析一次
 * ============================================================================
 * 解析 + 解引用 openapi.yaml（含那份外部 `$ref` 的 JSON Schema）不便宜，而
 * `Api` 套件里每个用例都要用。`ValidatorBuilder` 本身没有跨实例缓存，所以在这里
 * 用一个静态字段兜住 —— PHPUnit 的进程内所有用例共享同一份。
 */
trait OpenApiContract
{
    private static ?ResponseValidator $contractResponseValidator = null;

    private static ?PsrHttpFactory $psrHttpFactory = null;

    /**
     * 契约文件的绝对路径。
     */
    protected static function contractPath(): string
    {
        $path = realpath(__DIR__.'/../../../../docs/api/openapi.yaml');

        Assert::assertIsString($path, 'docs/api/openapi.yaml 不存在 —— 它是 API 的唯一真相源（§13.1）');

        return $path;
    }

    protected static function contractValidator(): ResponseValidator
    {
        return self::$contractResponseValidator ??= (new ValidatorBuilder())
            ->fromYamlFile(self::contractPath())
            ->getResponseValidator();
    }

    /**
     * 把一个**真实的**请求路径（含 `/v1`）解析成契约里的操作地址。
     *
     * @param string $method 大小写不敏感
     * @param string $path   如 `/v1/cards/0192f3a1-...`；query string 会被忽略
     */
    protected static function contractOperation(string $method, string $path): OperationAddress
    {
        $matches = (new PathFinder(self::contractValidator()->getSchema(), $path, strtolower($method)))->search();

        Assert::assertNotEmpty(
            $matches,
            \sprintf(
                "契约里没有 %s %s。\n".
                '要么这个端点还没进 docs/api/openapi.yaml（§13.1：必须**先**改契约），'.
                "要么路径写错了。\n契约里现有的路径：\n  %s",
                strtoupper($method),
                $path,
                implode("\n  ", array_keys(self::contractValidator()->getSchema()->paths->getPaths())),
            ),
        );

        // 两个路径模板同时匹配（如 `/cards/{cardId}` 与 `/cards/placement`）意味着
        // 契约本身有歧义 —— 那不是测试该猜的事，直接报出来。
        Assert::assertCount(
            1,
            $matches,
            \sprintf('%s %s 在契约里匹配到多个操作，契约有歧义', strtoupper($method), $path),
        );

        return $matches[0];
    }

    /**
     * 断言响应符合契约。不符即 fail，并把校验器的原因原样带出来。
     */
    protected static function assertResponseMatchesContract(
        string $method,
        string $path,
        Response|ResponseInterface $response,
    ): void {
        $operation = self::contractOperation($method, $path);

        try {
            self::contractValidator()->validate($operation, self::toPsr7($response));
        } catch (ValidationFailed $e) {
            Assert::fail(\sprintf(
                "响应与契约不符：%s %s（契约操作 %s）\n%s\n实际响应体：\n%s",
                strtoupper($method),
                $path,
                $operation->path(),
                self::explain($e),
                self::bodyOf($response),
            ));
        }
    }

    /**
     * 反向断言：这个响应**必须**被契约拒绝。
     *
     * T-007 的验收标准是「一个故意与契约不符的响应能让契约测试失败」——
     * 只有正向断言的话，一个悄悄退化成空跑的校验器会一直是绿的。
     *
     * @param string $because 期望被拒的理由，出现在失败信息里
     */
    protected static function assertResponseViolatesContract(
        string $method,
        string $path,
        Response|ResponseInterface $response,
        string $because,
    ): void {
        $operation = self::contractOperation($method, $path);

        try {
            self::contractValidator()->validate($operation, self::toPsr7($response));
        } catch (ValidationFailed) {
            return;
        }

        Assert::fail(\sprintf(
            "契约校验器**接受**了一个本该被拒的响应：%s %s\n期望被拒的理由：%s\n实际响应体：\n%s",
            strtoupper($method),
            $path,
            $because,
            self::bodyOf($response),
        ));
    }

    private static function toPsr7(Response|ResponseInterface $response): ResponseInterface
    {
        if ($response instanceof ResponseInterface) {
            return $response;
        }

        $factory = self::$psrHttpFactory ??= new PsrHttpFactory(new Psr17Factory());

        return $factory->createResponse($response);
    }

    private static function bodyOf(Response|ResponseInterface $response): string
    {
        if ($response instanceof ResponseInterface) {
            $response->getBody()->rewind();

            return $response->getBody()->getContents();
        }

        return (string) $response->getContent();
    }

    /**
     * 把嵌套的 previous 链摊平 —— 校验失败的**真正**原因（哪个字段、为什么）
     * 总是在链条最里面，只打最外层那句会得到「Body does not match schema」这种
     * 没有信息量的话。
     */
    private static function explain(\Throwable $e): string
    {
        $lines = [];

        for ($current = $e; null !== $current; $current = $current->getPrevious()) {
            $lines[] = '  '.$current::class.': '.$current->getMessage();
        }

        return implode("\n", $lines);
    }
}
