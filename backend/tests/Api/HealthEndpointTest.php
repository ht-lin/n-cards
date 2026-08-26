<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Shared\Application\Health\ReadinessProbe;
use App\Shared\Http\Controller\HealthController;
use App\Tests\Double\Health\RecordingHealthCheck;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * T-003 验收标准的端到端形态：`/health/live` 恒 200，`/health/ready` 在依赖不可用时 503。
 *
 * 这里用替换掉的 ReadinessProbe 来模拟「PG 挂了」，因此**不需要**真实数据库 ——
 * 真实 DBAL 连接路径由 tests/Integration/Health/DatabaseHealthCheckTest 覆盖，
 * 而「PG 停掉 → 503」的整栈验证在 infra/compose/README.md 里有可复制的命令。
 */
#[CoversClass(HealthController::class)]
final class HealthEndpointTest extends WebTestCase
{
    public function testLiveIsAlwaysOk(): void
    {
        $client = self::clientWith([new RecordingHealthCheck('postgres')]);
        $client->request('GET', '/health/live');

        self::assertResponseIsSuccessful();
        self::assertSame(['status' => 'ok'], self::decode($client));
    }

    /**
     * liveness 的语义是「重启我」。PG 挂了重启 app 容器毫无帮助，只会在故障期间
     * 制造重启风暴 —— 所以 /health/live 刻意不看任何外部依赖。
     */
    public function testLiveStaysOkEvenWhenADependencyIsDown(): void
    {
        $client = self::clientWith([new RecordingHealthCheck('postgres', 'connection refused')]);

        $client->request('GET', '/health/live');

        self::assertResponseIsSuccessful();
    }

    public function testReadyIsOkWhenEveryCheckPasses(): void
    {
        $client = self::clientWith([new RecordingHealthCheck('postgres')]);

        $client->request('GET', '/health/ready');

        self::assertResponseIsSuccessful();
        self::assertSame(['status' => 'ok'], self::decode($client));
    }

    public function testReadyIs503WhenADependencyIsDown(): void
    {
        $client = self::clientWith([new RecordingHealthCheck('postgres', 'connection refused')]);

        $client->request('GET', '/health/ready');

        self::assertResponseStatusCodeSame(Response::HTTP_SERVICE_UNAVAILABLE);
        self::assertSame(['status' => 'unavailable'], self::decode($client));
    }

    /**
     * §6.2：不暴露内部细节。响应体里不许出现组件名、异常消息或连接串 ——
     * 这两个端点在 staging/prod 上是公网可达的。
     */
    public function testFailureResponseLeaksNoInternals(): void
    {
        $client = self::clientWith([
            new RecordingHealthCheck('postgres', 'could not connect to 10.0.0.5:5432'),
        ]);

        $client->request('GET', '/health/ready');

        $body = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('postgres', $body);
        self::assertStringNotContainsString('10.0.0.5', $body);
        self::assertStringNotContainsString('could not connect', $body);
    }

    /**
     * 缓存住的 200 会让 §14.3 的部署健康检查在实例已经不健康之后继续认为它健康。
     */
    public function testResponsesAreNotCacheable(): void
    {
        $client = self::clientWith([new RecordingHealthCheck('postgres')]);

        foreach (['/health/live', '/health/ready'] as $path) {
            $client->request('GET', $path);
            self::assertStringContainsString(
                'no-store',
                (string) $client->getResponse()->headers->get('Cache-Control'),
                $path.' 必须带 Cache-Control: no-store',
            );
        }
    }

    /**
     * §6.2：探活端点**不在** /v1 下 —— 它们不是产品 API，不受 §13.6 的演进约束。
     */
    public function testEndpointsAreNotUnderV1(): void
    {
        self::createClient();

        $routes = self::getContainer()->get('router')->getRouteCollection();

        foreach (['health_live', 'health_ready'] as $name) {
            $route = $routes->get($name);
            self::assertNotNull($route, $name.' 路由必须存在');
            self::assertStringStartsWith('/health/', $route->getPath());
        }
    }

    /**
     * 建一个把 ReadinessProbe 换成受控替身的 client。
     *
     * `disableReboot()` 是必须的：KernelBrowser 默认每次 request 都重启内核，
     * 重启会把下面 set() 进去的替身丢掉 —— 循环发两次请求的用例会在第二次
     * 悄悄用回真实探针（真去连 PG）。
     *
     * @param list<RecordingHealthCheck> $checks
     */
    private static function clientWith(array $checks): KernelBrowser
    {
        $client = self::createClient();
        $client->disableReboot();
        self::getContainer()->set(ReadinessProbe::class, new ReadinessProbe($checks));

        return $client;
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(KernelBrowser $client): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
