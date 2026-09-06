<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Module\Identity\Http\OtpRequestController;
use App\Shared\Domain\Error\ErrorCode;
use App\Tests\Api\Support\OpenApiContract;
use App\Tests\Api\Support\ProblemDetailsAssertions;
use App\Tests\Api\Support\RequiresOtpStack;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * `POST /v1/auth/otp/request` 在**真实 HTTP** 上的形状（T-103）。
 *
 * 这是仓库里第一条被契约测试真正校验的产品端点 —— 在此之前
 * `OpenApiContract` 只能拿契约自己的 example 当夹具（T-007 的
 * `OpenApiContractHarnessTest`）。
 *
 * ⚠️ 防枚举那条（两条路径不可区分）不在这里，在
 * {@see OtpEnumerationResistanceTest}。这里只验「端点本身是不是照契约干活」。
 */
#[CoversClass(OtpRequestController::class)]
final class OtpRequestEndpointTest extends WebTestCase
{
    use OpenApiContract;
    use ProblemDetailsAssertions;
    use RequiresOtpStack;

    /**
     * ⚠️ 路由带 `/v1`，契约里的路径不带（`servers[].url` 带）。
     * 两者都要写对 —— `assertResponseMatchesContract()` 收的是**真实路径**，
     * 由校验器的 PathFinder 消化这个落差。
     */
    private const PATH = '/v1/auth/otp/request';

    protected function setUp(): void
    {
        $this->bootOtpStack();
    }

    protected function tearDown(): void
    {
        $this->rollbackOtpStack();

        parent::tearDown();
    }

    // ========================================================================
    // 正向
    // ========================================================================

    public function testReturns202WithTheContractedBody(): void
    {
        $response = $this->request();

        self::assertSame(Response::HTTP_ACCEPTED, $response->getStatusCode());
        self::assertSame('application/json; charset=utf-8', $response->headers->get('Content-Type'));
        // §7.4：每个 /v1 响应都是用户特定的，不许中间层缓存。
        // 用 contains 而不是 same：Symfony 的 Response::prepare() 会再补一个
        // `private`，最终发出去的是 `no-store, private`。
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        $body = self::decode($response);

        self::assertSame(['challenge_id', 'expires_at', 'resend_after_seconds'], array_keys($body));
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $body['challenge_id'],
            'challenge_id 应该是一个 UUIDv7',
        );
        // §7.1：重发间隔 60 秒，且与 otp_request_email 的 1/min 窗口同源
        // （那条一致性由 OtpPolicyConsistencyTest 钉住）。
        self::assertSame(60, $body['resend_after_seconds']);
    }

    /**
     * §13.1 契约优先在响应方向上的强制点：真实响应必须过
     * `docs/api/openapi.yaml` 的 schema 校验。
     */
    public function testTheResponseSatisfiesTheOpenApiContract(): void
    {
        self::assertResponseMatchesContract('post', self::PATH, $this->request());
    }

    /**
     * §6.1：时间一律 RFC 3339 UTC。§7.1：有效期 10 分钟。
     */
    public function testExpiresAtIsTenMinutesOutInRfc3339Utc(): void
    {
        $before = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $body = self::decode($this->request());

        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $body['expires_at']);

        $expiresAt = new \DateTimeImmutable($body['expires_at']);
        $ttl = $expiresAt->getTimestamp() - $before->getTimestamp();

        // 上下各留 5 秒，覆盖跨秒与填充带来的抖动。
        self::assertGreaterThanOrEqual(595, $ttl);
        self::assertLessThanOrEqual(605, $ttl);
    }

    public function testAcceptsEnglishAsWell(): void
    {
        self::assertSame(Response::HTTP_ACCEPTED, $this->request(locale: 'en')->getStatusCode());
    }

    // ========================================================================
    // 请求体的畸形输入 —— 全部经 AbstractApiController / OtpRequestPayload
    // ========================================================================

    public function testRejectsANonJsonContentType(): void
    {
        $response = $this->send('{"email":"a@b.de","locale":"de"}', contentType: 'text/plain');

        self::assertIsProblemDetails($response, ErrorCode::UnsupportedMediaType);
    }

    /**
     * @param non-empty-string $body
     */
    #[DataProvider('malformedBodies')]
    public function testRejectsAMalformedBody(string $body): void
    {
        self::assertIsProblemDetails($this->send($body), ErrorCode::MalformedRequest);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedBodies(): iterable
    {
        yield 'not json' => ['{'];
        yield 'a json list' => ['[{"email":"a@b.de"}]'];
        yield 'a bare scalar' => ['"hello"'];
        yield 'whitespace only' => ['   '];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[DataProvider('invalidPayloads')]
    public function testRejectsAnInvalidPayload(array $body, string $field, string $code): void
    {
        $response = $this->send(json_encode($body, \JSON_THROW_ON_ERROR));

        $problem = self::assertIsProblemDetails($response, ErrorCode::ValidationFailed);

        self::assertArrayHasKey('errors', $problem);
        self::assertContains(
            ['field' => $field, 'code' => $code],
            array_map(
                static fn (array $error): array => ['field' => $error['field'], 'code' => $error['code']],
                $problem['errors'],
            ),
        );
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string, string}>
     */
    public static function invalidPayloads(): iterable
    {
        yield 'email missing' => [['locale' => 'de'], 'email', 'required'];
        yield 'email malformed' => [['email' => 'nope', 'locale' => 'de'], 'email', 'invalid_format'];
        yield 'locale missing' => [['email' => 'anna@example.de'], 'locale', 'required'];
        yield 'locale unsupported' => [['email' => 'anna@example.de', 'locale' => 'fr'], 'locale', 'invalid_format'];
        // docs/api/README.md：契约在请求方向上比实现宽，未知字段服务端主动拒。
        yield 'unknown field' => [
            ['email' => 'anna@example.de', 'locale' => 'de', 'device_id' => 'x'],
            'device_id',
            'unknown_field',
        ];
    }

    /**
     * 一个畸形请求的 problem body 也必须过契约里声明的 400 响应。
     */
    public function testTheValidationProblemSatisfiesTheContract(): void
    {
        self::assertResponseMatchesContract(
            'post',
            self::PATH,
            $this->send('{"email":"nope","locale":"de"}'),
        );
    }

    // ========================================================================
    // 横切：这些一行代码都没写，全部来自 T-004 的监听器链
    // ========================================================================

    /**
     * `ClientVersionListener`。这条不是重复 T-004 的用例 ——
     * 它验的是**本端点确实落在 `/v1/` 面内**，也就是四个监听器都会跑。
     * 路由少写一个 `/v1` 前缀的话，这条会绿着变红（400 变 202）。
     */
    public function testRequiresTheXClientHeader(): void
    {
        $this->client->request(
            'POST',
            self::PATH,
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{"email":"anna@example.de","locale":"de"}',
        );

        $problem = self::assertIsProblemDetails($this->client->getResponse(), ErrorCode::ValidationFailed);

        self::assertSame('X-Client', $problem['errors'][0]['field']);
    }

    /**
     * `RequestIdListener`：客户端给了就回显，是排障时把 App 日志与服务端日志
     * 对上的唯一线索。
     */
    public function testEchoesTheRequestId(): void
    {
        $requestId = '0192f3a1-b2c3-7d4e-8f01-23456789abcd';

        $response = $this->request(headers: ['HTTP_X_REQUEST_ID' => $requestId]);

        self::assertSame($requestId, $response->headers->get('X-Request-Id'));
    }

    /**
     * `IdempotencyMiddleware`：同一个 Idempotency-Key 重放拿到**同一个**
     * challenge_id，而不是新建一条挑战。
     *
     * ⚠️ 顺带钉住一条不那么显眼的好性质：重放在优先级 8 短路，控制器根本不执行，
     * 于是 §7.5 的 email 维度配额**不被消耗**。反过来的话，一个网络不稳、
     * 自动重试的客户端会把自己的 1/min 烧掉，然后收到 429。
     */
    public function testReplaysTheSameChallengeForTheSameIdempotencyKey(): void
    {
        // ⚠️ 两次请求必须来自**同一个** IP。认证落地之前
        // `AnonymousIdempotencyScopeResolver` 返回 null，`IdempotencyMiddleware`
        // 于是回落到 IP 作用域 —— 本文件默认每条请求换一个源 IP（躲 20/h），
        // 那样两次请求会落进两个不同的作用域，永远重放不了。
        $sameOrigin = [
            // ⚠️ key 也必须每次跑都是新的。幂等记录在 Redis 里存 **24 小时**，
            // 而它**不在**测试事务里、回滚不掉 —— 写死一个 key 的话，第二次跑套件
            // 会拿这个 key 配上一个不同的请求体（邮箱是随机的），
            // 得到 422 idempotency_key_reused，症状是「昨天绿今天红」。
            'HTTP_IDEMPOTENCY_KEY' => self::uniqueIdempotencyKey(),
            // 两次请求必须来自**同一个** IP：认证落地之前
            // `AnonymousIdempotencyScopeResolver` 返回 null，`IdempotencyMiddleware`
            // 于是回落到 IP 作用域，而本文件默认每条请求换一个源 IP（躲 20/h）。
            'REMOTE_ADDR' => '203.0.113.200',
        ];

        // 同一个 key 必须配同一个请求体，否则是 422 idempotency_key_reused。
        $body = json_encode(['email' => self::uniqueEmail(), 'locale' => 'de'], \JSON_THROW_ON_ERROR);

        $first = self::decode($this->send($body, headers: $sameOrigin));
        $second = $this->send($body, headers: $sameOrigin);

        self::assertSame(Response::HTTP_ACCEPTED, $second->getStatusCode(), (string) $second->getContent());
        self::assertSame('true', $second->headers->get('Idempotency-Replayed'));
        self::assertSame($first['challenge_id'], self::decode($second)['challenge_id']);
    }

    // ========================================================================
    // 支撑
    // ========================================================================

    /**
     * @param array<string, string> $headers
     */
    private function request(string $locale = 'de', array $headers = []): Response
    {
        return $this->send(
            json_encode(['email' => self::uniqueEmail(), 'locale' => $locale], \JSON_THROW_ON_ERROR),
            headers: $headers,
        );
    }

    /**
     * @param array<string, string> $headers
     */
    private function send(string $body, string $contentType = 'application/json', array $headers = []): Response
    {
        $this->client->request(
            'POST',
            self::PATH,
            server: [
                'CONTENT_TYPE' => $contentType,
                'HTTP_X_CLIENT' => 'android/1.4.0 (26)',
                // 每条请求换一个源 IP，否则整组用例会撞上 `otp_request_ip` 的 20/h
                // （理由与做法见 RequiresOtpStack::uniqueIp()）。
                'REMOTE_ADDR' => self::uniqueIp(),
                ...$headers,
            ],
            content: $body,
        );

        return $this->client->getResponse();
    }

    /**
     * 一个随机的 UUIDv4 —— `IdempotencyMiddleware` 只要求是合法 UUID。
     */
    private static function uniqueIdempotencyKey(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);

        return implode('-', [
            bin2hex(substr($bytes, 0, 4)),
            bin2hex(substr($bytes, 4, 2)),
            bin2hex(substr($bytes, 6, 2)),
            bin2hex(substr($bytes, 8, 2)),
            bin2hex(substr($bytes, 10, 6)),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(Response $response): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
