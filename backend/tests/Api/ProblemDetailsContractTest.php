<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Shared\Domain\Error\ErrorCode;
use App\Shared\Infrastructure\Http\ApiProblemExceptionListener;
use App\Tests\Api\Support\ProblemDetailsAssertions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * T-004 验收标准的端到端形态：**每个**错误码经真实 HTTP 往返后仍符合
 * `docs/api/schemas/problem-details.schema.json`。
 *
 * 与 ApiProblemFactoryTest 的分工：那个是纯单测，验渲染逻辑本身；
 * 这个走完整的内核 → 监听器链 → 序列化，验的是「装起来之后确实是这样」。
 */
#[CoversClass(ApiProblemExceptionListener::class)]
final class ProblemDetailsContractTest extends WebTestCase
{
    use ProblemDetailsAssertions;

    private const CLIENT = 'android/1.4.0 (26)';

    /**
     * @return iterable<string, array{ErrorCode}>
     */
    public static function everyErrorCode(): iterable
    {
        foreach (ErrorCode::cases() as $case) {
            yield $case->value => [$case];
        }
    }

    #[DataProvider('everyErrorCode')]
    public function testEveryErrorCodeValidatesAgainstTheSchema(ErrorCode $code): void
    {
        $client = self::apiClient();

        $client->request('GET', '/v1/_probe/fail/'.$code->value, server: ['HTTP_X_CLIENT' => self::CLIENT]);

        $body = self::assertIsProblemDetails($client->getResponse(), $code);

        self::assertSame('/v1/_probe/fail/'.$code->value, $body['instance']);
        self::assertNotNull($body['request_id'], '每个错误响应都必须能被追踪');
    }

    #[DataProvider('everyErrorCode')]
    public function testEveryErrorResponseIsUncacheableAndEchoesTheRequestId(ErrorCode $code): void
    {
        $client = self::apiClient();

        $client->request('GET', '/v1/_probe/fail/'.$code->value, server: ['HTTP_X_CLIENT' => self::CLIENT]);

        $response = $client->getResponse();

        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        // 响应头与 body 里的 request_id 必须是同一个 —— 否则客户端报上来的那个
        // 在日志里搜不到。
        self::assertSame($response->headers->get('X-Request-Id'), $body['request_id']);
    }

    /**
     * §6.1 的例子形状，端到端复现一次。
     */
    public function testValidationFailedCarriesFieldErrors(): void
    {
        $client = self::apiClient();

        $client->request('GET', '/v1/_probe/fail/validation_failed', server: ['HTTP_X_CLIENT' => self::CLIENT]);

        $body = self::assertIsProblemDetails($client->getResponse(), ErrorCode::ValidationFailed);

        self::assertArrayHasKey('errors', $body);
        self::assertSame(
            [['field' => 'title', 'code' => 'too_long', 'message' => 'Title must be at most 100 characters.']],
            $body['errors'],
        );
    }

    public function testRevisionConflictCarriesCurrentState(): void
    {
        $client = self::apiClient();

        $client->request('GET', '/v1/_probe/fail/revision_conflict', server: ['HTTP_X_CLIENT' => self::CLIENT]);

        $body = self::assertIsProblemDetails($client->getResponse(), ErrorCode::RevisionConflict);

        self::assertSame(['revision' => 42], $body['current']);
    }

    public function testCodesWithoutDetailsOmitTheOptionalMembers(): void
    {
        $client = self::apiClient();

        $client->request('GET', '/v1/_probe/fail/not_found', server: ['HTTP_X_CLIENT' => self::CLIENT]);

        $body = self::assertIsProblemDetails($client->getResponse(), ErrorCode::NotFound);

        self::assertArrayNotHasKey('errors', $body);
        self::assertArrayNotHasKey('current', $body);
    }

    /**
     * ⚠️ 安全：未映射的异常必须变成通用 500，绝不回显原始消息。
     *
     * 探针抛的是一条带主机名与端口的假 SQLSTATE 消息 —— 那正是真实 Doctrine
     * 异常的形状。
     *
     * ⚠️ 测试内核跑在 `kernel.debug = true` 下，所以响应里**会**有 `debug` 成员，
     * 里面确实带着原始消息 —— 那是刻意的（dev 可调试）。prod 的 APP_DEBUG=0，
     * 该成员根本不会生成，由 ApiProblemFactoryTest::testDebugMemberIsAbsentInProduction
     * 单测覆盖。
     *
     * 这里验的是另一半、也是更容易写错的一半：**除 `debug` 之外的每一个成员都干净**。
     * 把 debug 摘掉再断言，比整体 assertStringNotContainsString 精确得多 ——
     * 后者在 debug 环境下只能被迫放弃检查。
     */
    public function testUnmappedExceptionsBecomeAGenericInternalError(): void
    {
        $client = self::apiClient();
        $client->catchExceptions(true);

        $client->request('GET', '/v1/_probe/boom', server: ['HTTP_X_CLIENT' => self::CLIENT]);

        $response = $client->getResponse();

        self::assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame('internal_error', $body['code']);
        self::assertSame('An unexpected error occurred.', $body['detail'], 'detail 必须是固定文案');

        unset($body['debug']);
        $withoutDebug = json_encode($body, \JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString('10.0.0.5', $withoutDebug);
        self::assertStringNotContainsString('SQLSTATE', $withoutDebug);
        self::assertStringNotContainsString('RuntimeException', $withoutDebug);
    }

    /**
     * 框架自己抛的 404（RouterListener）也要走 Problem Details，
     * 不能出现「业务错误一个格式、框架错误另一个格式」。
     */
    public function testFrameworkNotFoundIsAlsoProblemDetails(): void
    {
        $client = self::apiClient();
        $client->catchExceptions(true);

        $client->request('GET', '/v1/does-not-exist', server: ['HTTP_X_CLIENT' => self::CLIENT]);

        self::assertIsProblemDetails($client->getResponse(), ErrorCode::NotFound);
    }

    public function testFrameworkMethodNotAllowedIsAlsoProblemDetails(): void
    {
        $client = self::apiClient();
        $client->catchExceptions(true);

        // probe_echo 只接受 GET。
        $client->request('POST', '/v1/_probe/echo', server: ['HTTP_X_CLIENT' => self::CLIENT]);

        self::assertIsProblemDetails($client->getResponse(), ErrorCode::MethodNotAllowed);
    }

    private static function apiClient(): KernelBrowser
    {
        $client = self::createClient();
        $client->catchExceptions(true);

        return $client;
    }
}
