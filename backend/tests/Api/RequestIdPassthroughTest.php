<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Shared\Domain\Identity\Uuid;
use App\Shared\Infrastructure\Http\RequestIdListener;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * §6.1「追踪」：`X-Request-Id` 客户端可选提供，否则服务端生成，响应回显。
 */
#[CoversClass(RequestIdListener::class)]
final class RequestIdPassthroughTest extends WebTestCase
{
    private const CLIENT = 'android/1.4.0 (26)';

    public function testClientSuppliedIdIsEchoedBack(): void
    {
        $client = self::createClient();
        $supplied = '01941f29-7c00-70ab-8000-0000000000aa';

        $client->request('GET', '/v1/_probe/echo', server: [
            'HTTP_X_CLIENT' => self::CLIENT,
            'HTTP_X_REQUEST_ID' => $supplied,
        ]);

        self::assertSame($supplied, $client->getResponse()->headers->get('X-Request-Id'));

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame($supplied, $body['request_id'], '控制器读到的必须是同一个 id');
    }

    public function testMissingIdIsGeneratedAsUuidV7(): void
    {
        $client = self::createClient();

        $client->request('GET', '/v1/_probe/echo', server: ['HTTP_X_CLIENT' => self::CLIENT]);

        $generated = (string) $client->getResponse()->headers->get('X-Request-Id');

        self::assertTrue(Uuid::isValid($generated), '生成的 request id 必须是合法 UUID');
        self::assertSame(7, Uuid::fromString($generated)->version(), '应当是 UUIDv7（按时间有序，便于日志排序）');
    }

    /**
     * ⚠️ 安全：这个 header 会进结构化日志与响应头。
     *
     * 含换行的值能在日志里**伪造出一整条记录**（日志注入），也能做 header 拆分。
     * 所以非法值一律丢弃并重新生成 —— 但**不**报 400：客户端发了个畸形追踪 id
     * 不该让业务请求失败。
     *
     * @return iterable<string, array{string}>
     */
    public static function hostileIds(): iterable
    {
        yield 'crlf injection' => ["a\r\nInjected: 1"];
        yield 'newline' => ["a\nb"];
        yield 'space' => ['has space'];
        yield 'quote' => ['a"b'];
        yield 'json breakout' => ['a","level":"emergency","msg":"fake'];
        yield 'too long' => [str_repeat('a', 129)];
        yield 'empty' => [''];
    }

    #[DataProvider('hostileIds')]
    public function testHostileIdIsRejectedAndReplaced(string $hostile): void
    {
        $client = self::createClient();

        $client->request('GET', '/v1/_probe/echo', server: [
            'HTTP_X_CLIENT' => self::CLIENT,
            'HTTP_X_REQUEST_ID' => $hostile,
        ]);

        $echoed = (string) $client->getResponse()->headers->get('X-Request-Id');

        self::assertNotSame($hostile, $echoed, '非法的 request id 绝不能被原样回显');
        self::assertTrue(Uuid::isValid($echoed), '被拒之后必须回落到自己生成的 UUID');
    }

    /**
     * 长度恰好在上限上应当通过 —— 守卫是 `> 128` 而不是 `>= 128`。
     * W3C traceparent 是 55 字符，留足余量。
     */
    public function testIdAtTheLengthBoundaryIsAccepted(): void
    {
        $client = self::createClient();
        $atLimit = str_repeat('a', 128);

        $client->request('GET', '/v1/_probe/echo', server: [
            'HTTP_X_CLIENT' => self::CLIENT,
            'HTTP_X_REQUEST_ID' => $atLimit,
        ]);

        self::assertSame($atLimit, $client->getResponse()->headers->get('X-Request-Id'));
    }

    /**
     * 非 `/v1` 端点也要有 request_id —— §14.3 的部署健康检查失败时要能追。
     * 这个监听器刻意**不**按 /v1 过滤。
     */
    public function testHealthEndpointsAlsoGetARequestId(): void
    {
        $client = self::createClient();

        $client->request('GET', '/health/live');

        self::assertNotNull(
            $client->getResponse()->headers->get('X-Request-Id'),
            '探活端点的日志同样需要 request_id',
        );
    }

    /**
     * 错误响应也必须带这个 header —— 出错的请求恰恰是最需要被追查的。
     */
    public function testErrorResponsesCarryTheHeaderToo(): void
    {
        $client = self::createClient();
        $client->catchExceptions(true);

        $client->request('GET', '/v1/_probe/fail/not_found', server: ['HTTP_X_CLIENT' => self::CLIENT]);

        self::assertNotNull($client->getResponse()->headers->get('X-Request-Id'));
    }
}
