<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Shared\Application\Idempotency\IdempotencyStoreInterface;
use App\Shared\Application\Idempotency\IdempotencyStoreUnavailable;
use App\Shared\Domain\Error\ErrorCode;
use App\Shared\Infrastructure\Http\IdempotencyMiddleware;
use App\Tests\Api\Support\ProblemDetailsAssertions;
use App\Tests\Double\Idempotency\InMemoryIdempotencyStore;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * `Idempotency-Key` 的状态机（§6.1）。
 *
 * 用 InMemoryIdempotencyStore 替身跑，所以不需要真 Redis；
 * 真实的 `SET NX EX` 原子性与 TTL 由
 * tests/Integration/Shared/Redis/RedisIdempotencyStoreTest 用真容器覆盖。
 */
#[CoversClass(IdempotencyMiddleware::class)]
final class IdempotencyTest extends WebTestCase
{
    use ProblemDetailsAssertions;

    private const CLIENT = 'android/1.4.0 (26)';
    private const KEY = '01941f29-7c00-70ab-8000-0000000000cc';

    private InMemoryIdempotencyStore $store;

    /**
     * @param array<string, string> $extraServer
     */
    private function client(array $extraServer = []): KernelBrowser
    {
        $client = self::createClient();
        $client->catchExceptions(true);
        // 不 disableReboot 的话，第二次 request 会重启内核并丢掉下面 set() 进去的替身。
        $client->disableReboot();

        $this->store = new InMemoryIdempotencyStore();
        self::getContainer()->set(IdempotencyStoreInterface::class, $this->store);

        return $client;
    }

    /**
     * @param array<string, string> $extra
     *
     * @return array<string, string>
     */
    private static function server(array $extra = []): array
    {
        return array_merge([
            'HTTP_X_CLIENT' => self::CLIENT,
            'CONTENT_TYPE' => 'application/json',
        ], $extra);
    }

    // ========================================================================
    // 不介入的情形
    // ========================================================================

    /**
     * §6.1 的原文是所有 POST **支持**这个 header，不是必填 —— 缺失即放行。
     *
     * 全局强制会立刻打死还没发这个 header 的客户端。
     */
    public function testRequestWithoutTheHeaderIsUntouched(): void
    {
        $client = $this->client();

        $client->request('POST', '/v1/_probe/echo-body', server: self::server(), content: '{"a":1}');

        self::assertResponseIsSuccessful();
        self::assertSame([], $this->store->records, '没带幂等键就不该碰存储');
        self::assertNull($client->getResponse()->headers->get(IdempotencyMiddleware::REPLAYED_HEADER));
    }

    /**
     * 只覆盖 POST。GET 天然幂等，没必要花一次 Redis 往返。
     */
    public function testGetRequestsAreUntouched(): void
    {
        $client = $this->client();

        $client->request('GET', '/v1/_probe/echo', server: self::server([
            'HTTP_IDEMPOTENCY_KEY' => self::KEY,
        ]));

        self::assertResponseIsSuccessful();
        self::assertSame([], $this->store->records);
    }

    /**
     * §6.1 明写「`Idempotency-Key` header（UUID）」。
     */
    public function testNonUuidKeyIsRejected(): void
    {
        $client = $this->client();

        $client->request('POST', '/v1/_probe/echo-body', server: self::server([
            'HTTP_IDEMPOTENCY_KEY' => 'not-a-uuid',
        ]), content: '{"a":1}');

        $body = self::assertIsProblemDetails($client->getResponse(), ErrorCode::ValidationFailed);

        self::assertSame('Idempotency-Key', $body['errors'][0]['field']);
        self::assertSame('invalid_format', $body['errors'][0]['code']);
    }

    // ========================================================================
    // 正常路径
    // ========================================================================

    public function testFirstRequestClaimsTheKeyAndStoresTheResponse(): void
    {
        $client = $this->client();

        $client->request('POST', '/v1/_probe/echo-body', server: self::server([
            'HTTP_IDEMPOTENCY_KEY' => self::KEY,
        ]), content: '{"a":1}');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $this->store->records);

        $record = array_values($this->store->records)[0];
        self::assertTrue($record->completed, '2xx 响应必须落成已完成记录');
        self::assertSame(200, $record->status);
    }

    public function testSecondIdenticalRequestIsReplayed(): void
    {
        $client = $this->client();
        $server = self::server(['HTTP_IDEMPOTENCY_KEY' => self::KEY]);

        $client->request('POST', '/v1/_probe/echo-body', server: $server, content: '{"a":1}');
        $first = (string) $client->getResponse()->getContent();

        $client->request('POST', '/v1/_probe/echo-body', server: $server, content: '{"a":1}');
        $second = $client->getResponse();

        self::assertSame($first, (string) $second->getContent(), '回放必须逐字节相同');
        self::assertSame(
            'true',
            $second->headers->get(IdempotencyMiddleware::REPLAYED_HEADER),
            '回放必须可辨认 —— 否则客户端无从区分「真的执行了」与「拿到了回放」',
        );
    }

    /**
     * ⚠️ 请求耗时超过 60 秒的在途锁 TTL 时，重试仍必须拿到**回放**而不是 422。
     *
     * 早先的实现在存储的 complete() 里回读 Redis 重建指纹。键还在时一切正常，
     * 所以上面每一条测试都绿；但慢请求（慢 PG/Vault 调用、上游卡顿）走到那一步时
     * 锁已经过期，回读得到空指纹，已完成记录带着 `fp: ''` 落库。
     * 于是**同键同 body** 的正常重试撞上「指纹不符」，返回 422 idempotency_key_reused ——
     * 而按 §5.4.3，Android 的 outbox 不重试 409/429 之外的 4xx，客户端会永久放弃
     * 一笔服务端其实已经成功的操作。指纹改由中间件传入后，这条路径才闭合。
     */
    public function testReplayStillWorksWhenTheLockExpiredMidRequest(): void
    {
        $client = $this->client();
        $server = self::server(['HTTP_IDEMPOTENCY_KEY' => self::KEY]);

        // 第一次请求：处理期间在途锁过期。
        $this->store->lockExpiresBeforeComplete = true;
        $client->request('POST', '/v1/_probe/echo-body', server: $server, content: '{"a":1}');
        $first = (string) $client->getResponse()->getContent();
        $this->store->lockExpiresBeforeComplete = false;

        self::assertResponseIsSuccessful();

        // 客户端没收到响应，用同一个键、同一个 body 重试。
        $client->request('POST', '/v1/_probe/echo-body', server: $server, content: '{"a":1}');
        $retry = $client->getResponse();

        self::assertSame(
            'true',
            $retry->headers->get(IdempotencyMiddleware::REPLAYED_HEADER),
            '同键同 body 的重试必须是回放 —— 拿到 422 的话客户端会永久放弃一笔已成功的操作',
        );
        self::assertSame($first, (string) $retry->getContent());
    }

    /**
     * ⚠️ 回放**不能**带回第一次的 X-Request-Id。
     *
     * 带回去的话，两次不同的请求在日志里长得一模一样，关联直接断掉。
     */
    public function testReplayCarriesTheCurrentRequestId(): void
    {
        $client = $this->client();
        $server = self::server(['HTTP_IDEMPOTENCY_KEY' => self::KEY]);

        $client->request('POST', '/v1/_probe/echo-body', server: array_merge($server, [
            'HTTP_X_REQUEST_ID' => '01941f29-7c00-70ab-8000-000000000001',
        ]), content: '{"a":1}');

        $client->request('POST', '/v1/_probe/echo-body', server: array_merge($server, [
            'HTTP_X_REQUEST_ID' => '01941f29-7c00-70ab-8000-000000000002',
        ]), content: '{"a":1}');

        self::assertSame(
            '01941f29-7c00-70ab-8000-000000000002',
            $client->getResponse()->headers->get('X-Request-Id'),
            '回放响应必须带**当次**请求的 id',
        );
    }

    // ========================================================================
    // 冲突
    // ========================================================================

    /**
     * 同键不同 body ⇒ 422。
     *
     * 这是**客户端 bug**（性质同 §6.1 让客户端上报 Sentry 的 username_immutable），
     * 重试永远不会成功 —— 而 §5.4.3 规定 Android outbox「4xx（除 409/429）不重试」，
     * 所以选 422 让客户端天然地快速失败。
     */
    public function testSameKeyWithDifferentBodyIsRejected(): void
    {
        $client = $this->client();
        $server = self::server(['HTTP_IDEMPOTENCY_KEY' => self::KEY]);

        $client->request('POST', '/v1/_probe/echo-body', server: $server, content: '{"a":1}');
        $client->request('POST', '/v1/_probe/echo-body', server: $server, content: '{"a":2}');

        self::assertIsProblemDetails($client->getResponse(), ErrorCode::IdempotencyKeyReused);
    }

    /**
     * 并发在途 ⇒ 409 + Retry-After。
     *
     * 409 表示「与当前状态冲突，重试可能成功」—— 而 §5.4.3 的 outbox **会**重试 409，
     * 于是客户端一行代码不用改就会稍后拿到回放。
     */
    public function testConcurrentDuplicateGetsConflictWithRetryAfter(): void
    {
        $client = $this->client();
        $body = '{"a":1}';

        // 手工种一条在途记录，模拟「另一个进程正在处理同一个键」。
        $this->store->seedInProgress($this->expectedKey($body), hash('sha256', $body));

        $client->request('POST', '/v1/_probe/echo-body', server: self::server([
            'HTTP_IDEMPOTENCY_KEY' => self::KEY,
        ]), content: $body);

        self::assertIsProblemDetails($client->getResponse(), ErrorCode::IdempotencyInProgress);
        self::assertSame('1', $client->getResponse()->headers->get('Retry-After'));
    }

    // ========================================================================
    // 只持久化 2xx
    // ========================================================================

    /**
     * ⚠️ 回放一个 401 会**永久打死** §6.1 规定的「静默刷新后重试一次」——
     * 重试带着同一个键，于是永远拿回那个 401。
     */
    public function testNonSuccessResponsesReleaseTheKey(): void
    {
        $client = $this->client();

        $client->request('POST', '/v1/_probe/status?status=422', server: self::server([
            'HTTP_IDEMPOTENCY_KEY' => self::KEY,
        ]), content: '{}');

        self::assertSame(422, $client->getResponse()->getStatusCode());
        self::assertSame([], $this->store->records, '非 2xx 必须释放键，让客户端能重试');
        self::assertCount(1, $this->store->released);
    }

    public function testServerErrorsAlsoReleaseTheKey(): void
    {
        $client = $this->client();

        $client->request('POST', '/v1/_probe/status?status=503', server: self::server([
            'HTTP_IDEMPOTENCY_KEY' => self::KEY,
        ]), content: '{}');

        self::assertSame([], $this->store->records);
    }

    public function testNoContentResponsesAreStored(): void
    {
        $client = $this->client();

        $client->request('POST', '/v1/_probe/status?status=204', server: self::server([
            'HTTP_IDEMPOTENCY_KEY' => self::KEY,
        ]), content: '{}');

        self::assertSame(Response::HTTP_NO_CONTENT, $client->getResponse()->getStatusCode());
        self::assertCount(1, $this->store->records, '204 也是 2xx，同样要去重');
    }

    // ========================================================================
    // fail-open
    // ========================================================================

    /**
     * ⚠️ Redis 挂了 → 请求照常成功，只是没有幂等保护。
     *
     * fail-closed 会把一次 Redis 抖动放大成 100% 写入不可用（含登录），
     * 而 §9.2 的 99.5% 可用性在 30 天里只有 3.6 小时预算。
     * 故障对运维仍然可见 —— RedisHealthCheck 会把 /health/ready 翻成 503。
     *
     * ⚠️ T-006 的限流必须做**相反**的选择（fail-closed），那是安全控制。
     */
    public function testRequestSucceedsWhenTheStoreIsUnavailable(): void
    {
        $client = $this->client();
        $this->store->failWith = new IdempotencyStoreUnavailable('redis is down');

        $client->request('POST', '/v1/_probe/echo-body', server: self::server([
            'HTTP_IDEMPOTENCY_KEY' => self::KEY,
        ]), content: '{"a":1}');

        self::assertResponseIsSuccessful('存储不可用时必须 fail-open，而不是把写入全打掉');
    }

    /**
     * 复算中间件会用的存储键，供需要预先种记录的用例使用。
     */
    private function expectedKey(string $body): string
    {
        // 作用域：没有认证用户 → IP 维度。测试客户端的 IP 是 127.0.0.1。
        $scope = 'ip:'.substr(hash('sha256', '127.0.0.1'), 0, 16);
        $path = substr(hash('sha256', 'POST /v1/_probe/echo-body'), 0, 16);

        return \sprintf('%s:%s:%s', $scope, $path, self::KEY);
    }
}
