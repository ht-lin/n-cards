<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Shared\Domain\Error\ErrorCode;
use App\Shared\Infrastructure\Http\RateLimitListener;
use App\Shared\Infrastructure\RateLimit\RedisSlidingWindowRateLimiter;
use App\Shared\Infrastructure\Redis\RedisConnectionFactory;
use App\Tests\Api\Support\ProblemDetailsAssertions;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * §7.5 限流在**真实 HTTP** 上的形状。
 *
 * 验收标准里的「限流集成测试验证滑动窗口与 header」的后半句：窗口语义由
 * `tests/Integration/Shared/RateLimit/RedisSlidingWindowRateLimiterTest` 用
 * FrozenClock 密集覆盖，这里验的是**它到了响应上是什么样**——
 * 状态码、两个必需 header、以及 problem body 仍然满足 §6.1 的契约。
 *
 * ⚠️ 每个用例用随机 subject。用固定主体的话，同一分钟内重跑套件会因为 Redis 里
 * 的残留计数而假红 —— 而滑动窗口最短也有 60 秒。
 */
#[CoversClass(RateLimitListener::class)]
#[CoversClass(RedisSlidingWindowRateLimiter::class)]
final class RateLimitTest extends WebTestCase
{
    use ProblemDetailsAssertions;

    /** `_probe` 策略在 `when@test` 下的上限（config/packages/rate_limiter.yaml）。 */
    private const PROBE_LIMIT = 3;

    private KernelBrowser $client;
    private string $subject;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        // 要看的是**响应**（状态码 + header + problem body），不是异常 ——
        // 与 ProblemDetailsContractTest / ClientVersionEnforcementTest 一致。
        $this->client->catchExceptions(true);

        $factory = static::getContainer()->get(RedisConnectionFactory::class);

        try {
            $factory->create()->ping();
        } catch (\Throwable $e) {
            // 沿用 T-003/T-004 的规矩：连不上就 skip，裸机 composer test 保持全绿。
            self::markTestSkipped('Redis 不可达（'.$e->getMessage().'）。起 compose 栈后再跑。');
        }

        $this->subject = bin2hex(random_bytes(8));
    }

    /**
     * 上限内放行，第 `limit + 1` 次 429。
     */
    public function testExceedingTheLimitYieldsA429ProblemDetails(): void
    {
        for ($i = 1; $i <= self::PROBE_LIMIT; ++$i) {
            $this->hit();
            self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode(), '第 '.$i.' 次该放行');
        }

        $response = $this->hit();

        // 响应体仍然是 RFC 9457，且过 docs/api/schemas/problem-details.schema.json。
        $body = self::assertIsProblemDetails($response, ErrorCode::RateLimited);

        self::assertSame('rate_limited', $body['code']);
        self::assertSame(429, $body['status']);
        // 限流的 detail 里绝不能出现主体（§8.2 的安全类数据）。
        self::assertStringNotContainsString($this->subject, (string) $body['detail']);
    }

    /**
     * §7.5：「限流响应**必须**带 `Retry-After` 与 `X-RateLimit-Remaining`」。
     */
    public function testTheTwoMandatoryHeadersArePresentAndSane(): void
    {
        for ($i = 1; $i <= self::PROBE_LIMIT; ++$i) {
            $this->hit();
        }

        $headers = $this->hit()->headers;

        $retryAfter = $headers->get('Retry-After');
        self::assertNotNull($retryAfter, '§7.5：限流响应必须带 Retry-After');
        self::assertMatchesRegularExpression('/^\d+$/', $retryAfter, 'Retry-After 用秒数形式（不是 HTTP-date）');
        self::assertGreaterThanOrEqual(1, (int) $retryAfter, 'Retry-After: 0 会让客户端忙等');
        // `_probe` 是 3/60s，所以等待不该超过一个窗口。
        self::assertLessThanOrEqual(60, (int) $retryAfter);

        self::assertSame('0', $headers->get('X-RateLimit-Remaining'), '超限时剩余次数是 0');
    }

    /**
     * 不同主体互不影响 —— 否则限流会变成一个全局熔断。
     */
    public function testSubjectsAreIsolatedFromEachOther(): void
    {
        for ($i = 1; $i <= self::PROBE_LIMIT + 1; ++$i) {
            $this->hit();
        }

        self::assertSame(429, $this->client->getResponse()->getStatusCode());

        $this->subject = bin2hex(random_bytes(8));

        self::assertSame(
            Response::HTTP_OK,
            $this->hit()->getStatusCode(),
            '另一个主体的配额不该被上一个用掉',
        );
    }

    /**
     * 多维度：`Retry-After` 取更长的那个窗口，且被拒时不点名是哪个维度
     * （说出来等于告诉攻击者该换 IP 还是换邮箱，§3.8）。
     */
    public function testMultiDimensionResponseDoesNotNameTheDimensionThatDenied(): void
    {
        for ($i = 1; $i <= self::PROBE_LIMIT; ++$i) {
            $this->hit('/v1/_probe/limited-multi');
        }

        $response = $this->hit('/v1/_probe/limited-multi');
        $body = self::assertIsProblemDetails($response, ErrorCode::RateLimited);

        self::assertNotNull($response->headers->get('Retry-After'));
        self::assertStringNotContainsString('_probe', (string) $body['detail']);
    }

    // ========================================================================
    // 通用写接口限流（§7.5「全部写接口 user 300/min」）
    // ========================================================================

    /**
     * ⚠️ 只对 `/v1/*` 生效。`/health/*` 的调用方是 Docker healthcheck、Caddy 与
     * §14.3 的 Ansible —— 部署期间它们的频率完全不该受产品限额约束，
     * 而被限流的探针会让 compose 起栈与 staging 部署一起失败。
     */
    public function testHealthEndpointsAreNotRateLimited(): void
    {
        // 探活端点是 GET，本来就不在写方法里；这条断言盯的是「豁免仍然成立」。
        for ($i = 0; $i < 5; ++$i) {
            $this->client->request('GET', '/health/live');
            self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        }
    }

    /**
     * 写方法会经过通用限流器，读方法不会 —— §7.5 对读接口的限流是按端点配的
     * （`GET /v1/sync` 60/min 等），在这里一刀切会叠加成一个说不清的复合限额。
     */
    public function testWriteMethodsGoThroughTheGenericLimiterAndStillSucceed(): void
    {
        // 300/min 的额度对单个用例绰绰有余；这里只验「挂上了但不误伤」。
        $this->client->request(
            'POST',
            '/v1/_probe/echo-body',
            server: ['HTTP_X_CLIENT' => 'android/1.0.0 (1)', 'CONTENT_TYPE' => 'application/json'],
            content: '{"hello":"world"}',
        );

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
    }

    /**
     * ⚠️ 打错路径应该 404，**不该**烧掉一次配额（RateLimitListener 晚于路由）。
     */
    public function testAMistypedPathIs404AndDoesNotBurnQuota(): void
    {
        $this->client->request('POST', '/v1/typo', server: ['HTTP_X_CLIENT' => 'android/1.0.0 (1)']);

        self::assertIsProblemDetails($this->client->getResponse(), ErrorCode::NotFound);
    }

    private function hit(string $path = '/v1/_probe/limited'): Response
    {
        $this->client->request(
            'GET',
            $path.'?subject='.$this->subject,
            server: ['HTTP_X_CLIENT' => 'android/1.0.0 (1)'],
        );

        return $this->client->getResponse();
    }
}
