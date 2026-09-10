<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Shared\Application\Config\ClientConfigProvider;
use App\Shared\Domain\Config\MaintenanceMessageKey;
use App\Shared\Domain\Error\ErrorCode;
use App\Shared\Http\Controller\ConfigController;
use App\Shared\Infrastructure\Http\ClientVersionListener;
use App\Tests\Api\Support\OpenApiContract;
use App\Tests\Api\Support\ProblemDetailsAssertions;
use App\Tests\Double\Time\FrozenClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * `GET /v1/config` 的端到端形态（T-112 的两条验收标准）。
 *
 * ============================================================================
 * 为什么不用 RequiresOtpStack
 * ============================================================================
 * 这个端点免鉴权、无状态、不碰库。那个 trait 会起 Postgres / Redis / Vault 并造一个
 * 真实用户 —— 全是噪声，而且会让「配置端点坏了」与「Vault 封印了」两种失败在
 * 同一条红线上分不开。形状照 `HealthEndpointTest` 的裸 `WebTestCase`。
 *
 * 唯一的区别：本端点在 `/v1/` 下，所以每个请求**都得带** `X-Client`
 * （`/health/*` 不在 `/v1` 下，那个文件不需要）。
 *
 * ============================================================================
 * ⚠️ 旧客户端在本端点上拿到的是 426，而这正是被测行为
 * ============================================================================
 * 见 {@see ConfigController} 的类注释：426 本身就是 T-158 强制升级墙的信号源，
 * 客户端不需要先读到 `latest_client`。
 * {@see testAnOldClientIsRejectedHereToo()} 把这件事钉成一条断言，
 * 免得将来有人「顺手修掉」它。
 */
#[CoversClass(ConfigController::class)]
final class ConfigEndpointTest extends WebTestCase
{
    use OpenApiContract;
    use ProblemDetailsAssertions;

    private const PATH = '/v1/config';
    private const CLIENT = 'android/1.4.0 (26)';

    private const START = '2026-09-15T03:00:00+02:00';
    private const END = '2026-09-15T04:00:00+02:00';
    private const START_MILLIS = 1789434000_000; // 2026-09-15T01:00:00Z

    // ========================================================================
    // 验收标准 1：契约测试
    // ========================================================================

    public function testServesTheConfiguredValuesAndMatchesTheContract(): void
    {
        $client = self::createClient();

        $response = self::get($client);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
        self::assertResponseMatchesContract('get', self::PATH, $response);

        $body = self::decode($response);

        // .env.test 的值。断言具体数字而不是「是个字符串」—— 后者对「两个字段
        // 接错了参数」这种手误完全没有判别力。
        self::assertSame('android/0.0.0 (1)', $body['min_supported_client']);
        self::assertSame('android/0.1.0 (1)', $body['latest_client']);
        self::assertNotSame(
            $body['min_supported_client'],
            $body['latest_client'],
            'min 与 latest 取值相同的话，「两个字段都接到 %ncards.min_supported_client%」这种手误测不出来',
        );

        self::assertSame(['active' => false, 'message_key' => null, 'retry_after' => null], $body['maintenance']);
    }

    /**
     * ⚠️ `feature_flags` 必须是 `{}`，不是 `[]`。
     *
     * PHP 的空数组会被 `json_encode` 编成 `[]`，而契约说这里是对象 ——
     * Android 侧（Kotlin `Map<String, …>`）对着 `[]` 会反序列化失败。
     * 这个 bug 在服务端这侧**完全没有症状**。
     *
     * 断言原始 JSON 子串是必须的：`json_decode(..., true)` 之后 `[]` 与 `{}`
     * 在 PHP 里都是空数组，分不出来。
     */
    public function testFeatureFlagsIsAnEmptyObjectNotAnEmptyArray(): void
    {
        $client = self::createClient();

        $raw = (string) self::get($client)->getContent();

        self::assertStringContainsString('"feature_flags":{}', $raw);
        self::assertStringNotContainsString('"feature_flags":[]', $raw);
    }

    /**
     * 反向断言：一个故意不符契约的响应必须被校验器拒掉。
     *
     * 没有这一条的话，一个悄悄退化成空跑的校验器会让上面那条契约断言恒绿
     * （口径同 `OpenApiContract::assertResponseViolatesContract()` 的注释与 T-007）。
     */
    public function testTheContractValidatorActuallyRejectsAWrongShape(): void
    {
        self::assertResponseViolatesContract(
            'get',
            self::PATH,
            new Response('{"min_supported_client":42}', Response::HTTP_OK, ['Content-Type' => 'application/json; charset=utf-8']),
            'min_supported_client 是字符串，不是整数；而且 latest_client / maintenance / feature_flags 全缺',
        );
    }

    // ========================================================================
    // 验收标准 2：旧 X-Client → 426
    // ========================================================================

    /**
     * 任务卡：「用旧 `X-Client` 请求**任意端点**返回 426」—— 包括本端点自己。
     *
     * T-004 已经在 `/v1/_probe/echo` 上覆盖了同一条判定
     * （`ClientVersionEnforcementTest::testClientBelowTheMinimumSupportedVersionIsRejected`）。
     * 这一条不是重复：那个是**夹具**路由，只在 `when@test` 的容器里存在；
     * 本条跑在第一个**真实**的 `/v1` 路由上，而且它顺带钉住「不给 /v1/config
     * 开豁免」这个决定 —— 哪天有人加了豁免，这条会红。
     */
    public function testAnOldClientIsRejectedHereToo(): void
    {
        $client = self::createClient();
        $client->catchExceptions(true);
        $client->disableReboot();

        self::getContainer()->set(
            ClientVersionListener::class,
            new ClientVersionListener('android/2.0.0 (100)'),
        );

        self::assertIsProblemDetails(self::get($client), ErrorCode::ClientTooOld);
    }

    /**
     * ⚠️⚠️ 同源性：被拒的门槛与下发的数字必须是**同一个**配置值。
     *
     * `config/services.yaml` 的注释原话：「两处必须同源，否则会出现『服务端拒了
     * 但客户端不知道该升到哪个版本』」。
     *
     * 这里把两个读者都指向 `android/2.0.0 (100)`，然后：
     *   - 一个刚好达标的客户端拿到 200，且响应里的 `min_supported_client`
     *     逐字等于那个值；
     *   - 一个差一个 build 的客户端拿到 426。
     *
     * 把 `ClientConfigProvider` 改成读一个独立参数，这条会红。
     */
    public function testTheServedMinimumIsTheSameValueThatGetsEnforced(): void
    {
        $minimum = 'android/2.0.0 (100)';

        $client = self::createClient();
        $client->catchExceptions(true);
        $client->disableReboot();

        self::getContainer()->set(ClientVersionListener::class, new ClientVersionListener($minimum));
        self::getContainer()->set(
            ClientConfigProvider::class,
            new ClientConfigProvider($minimum, $minimum, null, null, new FrozenClock()),
        );

        $accepted = self::get($client, $minimum);

        self::assertSame(Response::HTTP_OK, $accepted->getStatusCode(), (string) $accepted->getContent());
        self::assertSame($minimum, self::decode($accepted)['min_supported_client']);

        // 差一个 build 就被拒 —— 证明下发的那个数字就是门槛本身。
        self::assertIsProblemDetails(self::get($client, 'android/2.0.0 (99)'), ErrorCode::ClientTooOld);
    }

    // ========================================================================
    // 免鉴权
    // ========================================================================

    /**
     * ⚠️ 不带 `Authorization` 头也是 200。
     *
     * 只靠 `AuthenticationCoverageTest` 的对账不足以证明这件事：那个测试比的是
     * 「白名单里有没有这一行」与「契约里有没有 `security: []`」，两边**同时**
     * 写错的话它仍然是绿的。这一条直接打一个裸请求。
     */
    public function testNoBearerTokenIsRequired(): void
    {
        $client = self::createClient();

        $client->request('GET', self::PATH, server: ['HTTP_X_CLIENT' => self::CLIENT]);

        self::assertResponseIsSuccessful();
        self::assertArrayNotHasKey('HTTP_AUTHORIZATION', $client->getRequest()->server->all());
    }

    /**
     * 带一个垃圾 token 照样是 200 —— 免鉴权端点**不验**它收到的 token。
     *
     * 这一条防的是「把鉴权做成『有就验，没有就放过』」那种实现：
     * 那样的话一个本地会话已经过期的客户端会在启动时拿到 401，
     * 于是它连「该不该弹升级墙」都问不出来。
     */
    public function testAGarbageBearerTokenIsIgnoredRatherThanRejected(): void
    {
        $client = self::createClient();

        $client->request('GET', self::PATH, server: [
            'HTTP_X_CLIENT' => self::CLIENT,
            'HTTP_AUTHORIZATION' => 'Bearer not-a-real-token',
        ]);

        self::assertResponseIsSuccessful();
    }

    // ========================================================================
    // X-Client 仍然必填
    // ========================================================================

    /**
     * 免鉴权**不等于**免 `X-Client`（§6.1：所有 `/v1` 请求必填）。
     */
    public function testTheClientHeaderIsStillRequired(): void
    {
        $client = self::createClient();
        $client->catchExceptions(true);

        $client->request('GET', self::PATH);

        $body = self::assertIsProblemDetails($client->getResponse(), ErrorCode::ValidationFailed);

        self::assertSame('X-Client', $body['errors'][0]['field']);
        self::assertSame('required', $body['errors'][0]['code']);
    }

    // ========================================================================
    // 缓存头 —— 全仓库唯一一处
    // ========================================================================

    /**
     * ⚠️⚠️ `Vary: X-Client` 是承重的。
     *
     * 响应**体**与 `X-Client` 无关，但**状态码**与它强相关（过旧 → 426）。
     * 少了这个头，一个只按 URL 做键的共享缓存会把缓存下来的 200 喂给旧客户端 ——
     * 强制升级墙就再也不出现了，而且没有任何错误可查。
     *
     * ⚠️ 按指令断言而不是按字面量：Symfony 的 `ResponseHeaderBag` 会把
     * `public, max-age=60` 重排成 `max-age=60, public`（语义相同，RFC 9111
     * 的指令无序）。按字面量写的断言会在无关的框架升级上红。
     */
    public function testThisIsTheOnlyCacheableV1Response(): void
    {
        $client = self::createClient();

        $headers = self::get($client)->headers;

        self::assertTrue($headers->hasCacheControlDirective('public'));
        self::assertSame('60', $headers->getCacheControlDirective('max-age'));
        self::assertFalse(
            $headers->hasCacheControlDirective('no-store'),
            '本端点是 §7.4 的 no-store 默认值唯一的例外（见 AbstractApiController::json() 的 $noStore）',
        );
        self::assertSame('X-Client', $headers->get('Vary'));
    }

    /**
     * 426 那一侧**不可缓存** —— `ApiProblemFactory` 给所有 problem 响应打了
     * `no-store`。这一条与上面那条合起来才是完整的缓存语义：
     * 一个被缓存的 426 会把新客户端也挡在门外。
     */
    public function testTheRejectionIsNeverCacheable(): void
    {
        $client = self::createClient();
        $client->catchExceptions(true);
        $client->disableReboot();

        self::getContainer()->set(
            ClientVersionListener::class,
            new ClientVersionListener('android/2.0.0 (100)'),
        );

        self::assertTrue(self::get($client)->headers->hasCacheControlDirective('no-store'));
    }

    // ========================================================================
    // 维护公告
    // ========================================================================

    /**
     * 三态各一条，每条都再过一次契约校验 —— `message_key` 的两个取值必须都在
     * 契约的 `enum` 列表里，漏一个的症状是「公告期的响应不符契约」，
     * 而那件事只有在真的有公告时才会发生（也就是生产）。
     *
     * @return iterable<string, array{int, array<string, mixed>}>
     */
    public static function maintenanceStates(): iterable
    {
        yield '已公告（24 小时内，尚未开始）' => [
            self::START_MILLIS - 3600_000,
            ['active' => false, 'message_key' => MaintenanceMessageKey::Scheduled->value, 'retry_after' => null],
        ];

        yield '进行中' => [
            self::START_MILLIS + 1800_000,
            ['active' => true, 'message_key' => MaintenanceMessageKey::InProgress->value, 'retry_after' => 1800],
        ];

        yield '窗口还在 24 小时以外' => [
            self::START_MILLIS - 90000_000,
            ['active' => false, 'message_key' => null, 'retry_after' => null],
        ];
    }

    /**
     * @param array<string, mixed> $expected
     */
    #[DataProvider('maintenanceStates')]
    public function testMaintenanceIsServedAndStaysWithinTheContract(int $nowMillis, array $expected): void
    {
        $client = self::createClient();
        $client->disableReboot();

        self::getContainer()->set(
            ClientConfigProvider::class,
            new ClientConfigProvider(
                'android/0.0.0 (1)',
                'android/0.1.0 (1)',
                self::START,
                self::END,
                new FrozenClock($nowMillis),
            ),
        );

        $response = self::get($client);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame($expected, self::decode($response)['maintenance']);
        self::assertResponseMatchesContract('get', self::PATH, $response);
    }

    // ========================================================================
    // helpers
    // ========================================================================

    private static function get(KernelBrowser $client, string $clientVersion = self::CLIENT): Response
    {
        $client->request('GET', self::PATH, server: ['HTTP_X_CLIENT' => $clientVersion]);

        return $client->getResponse();
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(Response $response): array
    {
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        return $body;
    }
}
