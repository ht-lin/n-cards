<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Shared\Domain\Error\ErrorCode;
use App\Shared\Domain\Http\ApiSurface;
use App\Shared\Infrastructure\Http\AuthenticationListener;
use App\Shared\Infrastructure\Http\OnboardingListener;
use App\Tests\Api\Support\ProblemDetailsAssertions;
use App\Tests\Api\Support\RequiresOtpStack;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RouterInterface;

/**
 * T-108 的验收标准本体：**参数化遍历全部 `/v1` 端点**，断言 onboarding 未完成的
 * 用户只能访问白名单里的三个（§5.2）。
 *
 * ============================================================================
 * 为什么是行为测试，不是像 AuthenticationCoverageTest 那样的「对账」
 * ============================================================================
 * {@see AuthenticationCoverageTest} 拿 `PUBLIC_ROUTES` 与契约里的 `security: []`
 * 对账，因为「这个端点要不要 Bearer」在契约里有一个**独立的第二真相**可以对。
 * onboarding 白名单没有那个东西：契约里没有 `x-onboarding-exempt` 之类的标注，
 * 而为了对账去发明一个，只会多出一处要记得同步的地方。
 *
 * 这里改成真的发请求 —— 于是漂移在**两个方向**上都会红：
 *
 *   - 白名单里多一行 → 那条路由对未完成的用户可达 → 断言「应该是 403」失败；
 *   - 白名单里少一行 → 那三条之一变成 403 → 断言「不该是 403」失败。
 *
 * 而且它不需要任何人来登记：**新端点自动进入遍历**。
 *
 * ============================================================================
 * ⚠️ 给之后加端点的人（T-109 起）
 * ============================================================================
 * 新增一个 `/v1` 路由，本文件会自动开始要求它对未完成 onboarding 的用户返回
 * `403 username_required`。**那几乎总是对的**，通常不需要动这里。
 * 确实需要豁免的话要同时改三处：§5.2 的清单、
 * {@see OnboardingListener::EXEMPT_ROUTES}、以及本文件的断言 ——
 * 而「需要豁免」的门槛很高：那三条之外的任何端点，对一个还没有 username 的
 * 用户来说都没有意义。
 *
 * ============================================================================
 * 两个会咬人的测试环境事实（口径同 UsernameEndpointTest）
 * ============================================================================
 *  1. **每条请求换一个源 IP**。Redis 里的限流计数不在测试事务里、回滚不掉。
 *  2. 用户走**真实** `POST /v1/auth/otp/verify` 建出来 —— 那条路径建出的行
 *     `username` 恒为 NULL，正是本文件要的被测主体。
 */
#[CoversClass(OnboardingListener::class)]
final class OnboardingCoverageTest extends WebTestCase
{
    use ProblemDetailsAssertions;
    use RequiresOtpStack;

    private const CODE = '551204';

    /**
     * §5.2 / §6.3.1 注 2 / 契约 `User.onboarding_complete` 三处逐字列出的三条。
     *
     * ⚠️ **预期取自规格，不取自 {@see OnboardingListener::EXEMPT_ROUTES}** ——
     * 这是本文件能双向发现漂移的全部原因，与
     * {@see AuthenticationCoverageTest} 拿契约对实现是同一个做法：
     *
     *   - 实现里少一行 → 那条路由变成 403 → 「不该是 403」的断言红；
     *   - 实现里**多**一行 → 那条路由放行了，但它不在本列表里 →
     *     「应该是 403」的断言红。
     *
     * 拿实现的常量当预期的话，第二种漂移会被完全吞掉（多出来的那行既是
     * 实现也是预期，测试恒绿）—— 而它恰恰是权限洞的那一种。
     *
     * 要往这里加一行，先改 §5.2 与契约：这是产品清单，不是实现细节。
     */
    private const SPEC_EXEMPT = ['me_get', 'me_username_set', 'auth_logout'];

    /**
     * 路径参数的占位值。用什么都行 —— 拦截器在 `kernel.request` 上跑，
     * 请求根本到不了控制器，也就永远不会去查这个 id 存不存在。
     */
    private const PATH_PARAM = '0192f3a1-b2c3-7d4e-8f01-23456789abcd';

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
    // 验收标准
    // ========================================================================

    /**
     * 每一条已注册的、需要鉴权的 `/v1` 路由，对 onboarding 未完成的用户
     * 要么是白名单三条之一（不返回 `username_required`），要么返回它。
     */
    #[DataProvider('protectedProductApiRoutes')]
    public function testAnIncompleteUserReachesNothingButTheThreeExemptRoutes(string $name, string $method, string $path): void
    {
        $token = $this->register();

        $response = $this->send($method, self::fill($path), $token);

        if (\in_array($name, self::SPEC_EXEMPT, true)) {
            self::assertNotSame(
                Response::HTTP_FORBIDDEN,
                $response->getStatusCode(),
                \sprintf(
                    "%s %s（路由 %s）是 §5.2 的三条豁免路由之一，却被拦住了。\n"
                    ."它们是注册中间态唯一的出路 —— 挡住任何一条，用户就永远完成不了 onboarding。\n"
                    ."多半是 OnboardingListener::EXEMPT_ROUTES 里少了它，或者名字拼错了。\n响应：%s",
                    $method,
                    $path,
                    $name,
                    (string) $response->getContent(),
                ),
            );

            return;
        }

        self::assertIsProblemDetails($response, ErrorCode::UsernameRequired);
    }

    // ========================================================================
    // 白名单本身
    // ========================================================================

    /**
     * ⚠️ 表里每一个名字都必须是一条**真实注册的**路由。
     *
     * 一个拼错的名字（`me_username`，少了 `_set`）在别处**几乎没有症状**：
     * 它只是永远匹配不上，于是那条路由悄悄变回「受管」——
     * 也就是注册中间态唯一的出口被自己堵死。上面那条参数化用例确实会红，
     * 但它说的是「me_username_set 被拦住了」，而真正的原因在这张表里。
     * 这一条直接指出它。
     *
     * 顺带也拦住往表里塞 `probe_*` 夹具：那些路由不存在于 dev/prod 容器里
     * （口径同 {@see AuthenticationCoverageTest::testThePublicListContainsNoTestFixtures()}）。
     */
    public function testEveryExemptRouteIsARegisteredRoute(): void
    {
        self::bootKernel();

        /** @var RouterInterface $router */
        $router = self::getContainer()->get('router');

        $registered = array_keys($router->getRouteCollection()->all());

        self::ensureKernelShutdown();

        foreach (OnboardingListener::EXEMPT_ROUTES as $route) {
            self::assertContains(
                $route,
                $registered,
                \sprintf('EXEMPT_ROUTES 里的「%s」不是一条已注册的路由 —— 拼错的名字永远匹配不上，于是那个端点被静默拦住。', $route),
            );
        }
    }

    // ========================================================================
    // §5.2 的三条清单之外，还有一条必须可达
    // ========================================================================

    /**
     * `POST /v1/auth/token/refresh` **不在**§5.2 的豁免清单里，但它免鉴权，
     * 于是从来不带 `AuthContext` 走到拦截器 —— 结构上就放行了。
     *
     * 这条测试钉住的正是那个「结构上」：真要有人把身份判定挪到鉴权之前、
     * 或者给公开路由也塞一个 `AuthContext`，一个卡在 onboarding 的用户
     * 会在 15 分钟后连令牌都换不了，于是连那三条豁免路由也够不着 ——
     * 账号在没有任何错误提示的情况下报废。
     */
    public function testAnIncompleteUserCanStillRefreshItsTokens(): void
    {
        $challenge = $this->seedChallenge(self::CODE);

        $verified = self::decode($this->send('POST', '/v1/auth/otp/verify', null, self::verifyBody($challenge->id()->toString())));

        self::assertFalse($verified['user']['onboarding_complete'], '刚注册的用户必须处于 §5.2 的注册中间态');

        $refreshed = $this->send(
            'POST',
            '/v1/auth/token/refresh',
            null,
            json_encode(['refresh_token' => $verified['refresh_token']], \JSON_THROW_ON_ERROR),
        );

        self::assertSame(Response::HTTP_OK, $refreshed->getStatusCode(), (string) $refreshed->getContent());
    }

    // ========================================================================
    // 数据源
    // ========================================================================

    /**
     * 全部**需要鉴权**的 `/v1` 路由。
     *
     * 遍历路由表而不是契约，理由同 {@see AuthenticationCoverageTest}：
     * 契约里有些端点还没实现（`/cards*` 归 T-109/T-110），
     * 以契约为准的话这个测试会为一个不存在的路由发请求并拿到 404。
     *
     * 排除两类：
     *   - `probe_*` 夹具（不在契约里，也不该在）；
     *   - `AuthenticationListener::PUBLIC_ROUTES` —— 它们免鉴权，永远不带
     *     `AuthContext`，因此结构上就在拦截器之外。它们**不是**被豁免的，
     *     是根本不适用；混进来只会让本文件对「豁免」这个词失去判别力。
     *     其中最要紧的那条（`auth_token_refresh`）另有
     *     {@see testAnIncompleteUserCanStillRefreshItsTokens()} 单独钉着。
     *
     * ⚠️ 返回数组而不是 `yield` 一个生成器：本方法要自己起一个内核来读路由表，
     * 而 `WebTestCase::createClient()`（`setUp()` 里的第一件事）在已有内核时会抛
     * 「Booting the kernel before calling createClient() is not supported」。
     * 生成器的收尾代码只在被完整迭代时才跑，数组则保证 `ensureKernelShutdown()`
     * 一定执行。
     *
     * @return array<string, array{string, string, string}> 用例名 => [路由名, 方法, 路径模板]
     */
    public static function protectedProductApiRoutes(): array
    {
        self::bootKernel();

        /** @var RouterInterface $router */
        $router = self::getContainer()->get('router');

        $cases = [];

        foreach ($router->getRouteCollection() as $name => $route) {
            $path = $route->getPath();

            if (!ApiSurface::isProductApiPath($path)) {
                continue;
            }

            if (str_starts_with($name, 'probe_')) {
                continue;
            }

            if (\in_array($name, AuthenticationListener::PUBLIC_ROUTES, true)) {
                continue;
            }

            foreach ($route->getMethods() ?: ['GET'] as $method) {
                $cases[$method.' '.$path] = [$name, $method, $path];
            }
        }

        self::ensureKernelShutdown();

        return $cases;
    }

    // ========================================================================
    // helpers
    // ========================================================================

    /**
     * 走**真实**的 `POST /v1/auth/otp/verify` 注册一个用户，返回它的 access token。
     * 那条路径建出的行 `username` 恒为 NULL —— 本文件全篇要的就是这个状态。
     */
    private function register(): string
    {
        $challenge = $this->seedChallenge(self::CODE);

        $response = $this->send('POST', '/v1/auth/otp/verify', null, self::verifyBody($challenge->id()->toString()));

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());

        $body = self::decode($response);

        self::assertNull($body['user']['username'], '刚注册的用户必须处于 §5.2 的注册中间态');

        return (string) $body['access_token'];
    }

    /**
     * 路径模板里的 `{param}` 一律填一个固定 UUID。
     */
    private static function fill(string $path): string
    {
        return (string) preg_replace('/\{[^}]+\}/', self::PATH_PARAM, $path);
    }

    private static function verifyBody(string $challengeId): string
    {
        return json_encode([
            'challenge_id' => $challengeId,
            'code' => self::CODE,
            'device' => [
                'id' => self::uuid(),
                'platform' => 'android',
                'model' => 'Pixel 7a',
                'os_version' => '14',
                'app_version' => '1.4.0',
            ],
        ], \JSON_THROW_ON_ERROR);
    }

    private function send(string $method, string $path, ?string $accessToken, ?string $body = null): Response
    {
        $server = [
            'HTTP_X_CLIENT' => 'android/1.4.0 (26)',
            // ⚠️ 每条请求一个新 IP —— 见类注释。
            'REMOTE_ADDR' => self::uniqueIp(),
        ];

        // 写方法一律带一个空 JSON 对象。它对本文件毫无意义（请求在
        // kernel.request 上就被拦了），但缺了 Content-Type 的话，
        // 万一某条路由**没有**被拦住，它会以 415 而不是 200 暴露出来 ——
        // 而 415 与 403 一样不是 200，断言就抓不到那个漏洞了。
        if (\in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            $server['CONTENT_TYPE'] = 'application/json';
            $body ??= '{}';
        }

        if (null !== $accessToken) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$accessToken;
        }

        $this->client->request($method, $path, server: $server, content: $body ?? '');

        return $this->client->getResponse();
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x70);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);

        $hex = bin2hex($bytes);

        return \sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20, 12));
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
