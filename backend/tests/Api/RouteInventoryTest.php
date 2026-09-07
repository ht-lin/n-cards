<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Shared\Domain\Http\ApiSurface;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Routing\RouterInterface;

/**
 * 「豁免」规则的**真正**强制点。
 *
 * ============================================================================
 * 为什么需要这个测试
 * ============================================================================
 * ApiSurface 把「豁免 /health/*」反转成了「只有 /v1/* 受约束」，所以新增一条
 * 非 `/v1` 路由**自动**被豁免 —— 这是好事，但也意味着这类路由可以悄悄溜进来，
 * 没有人再想一想「它到底该不该在 /v1 之外」。
 *
 * 于是这里立一张显式清单：每条注册路由要么在 `/v1/` 下，要么在
 * {@see NON_PRODUCT_ROUTES} 里。加一条非 `/v1` 路由而不登记 → CI 红，
 * 加的人被迫在 PR 里说明理由。
 *
 * §13.6 的 API 演进规则只管 `/v1` 内部；`/v1` **之外**有什么，靠的就是这张清单。
 */
#[CoversClass(ApiSurface::class)]
final class RouteInventoryTest extends WebTestCase
{
    /**
     * 刻意不在 `/v1` 下的路由，以及各自的理由。
     *
     * 往这里加条目前先问一句：这个端点真的不该在 `/v1` 下吗？
     * 「不受 §13.6 演进约束」「不出现在 openapi.yaml 里」「无需 X-Client」
     * 三条要同时成立。
     *
     * @var array<string, string> 路由名 => 理由
     */
    private const NON_PRODUCT_ROUTES = [
        // T-003。调用方是 Docker healthcheck / Caddy / Ansible（§14.2、§14.3），
        // 不是产品 API，不带 X-Client。
        'health_live' => 'T-003 探活端点，§6.2 明确要求不在 /v1 下',
        'health_ready' => 'T-003 就绪端点，同上',
    ];

    /** `when@dev` 的错误页（config/routes/framework.yaml），只在 dev 存在。 */
    private const DEV_ONLY_PREFIX = '/_error';

    public function testEveryRouteIsEitherProductApiOrExplicitlyExempt(): void
    {
        self::createClient();

        /** @var RouterInterface $router */
        $router = self::getContainer()->get('router');

        $unregistered = [];

        foreach ($router->getRouteCollection() as $name => $route) {
            $path = $route->getPath();

            if (ApiSurface::isProductApiPath($path)) {
                continue;
            }

            if (str_starts_with($path, self::DEV_ONLY_PREFIX)) {
                continue;
            }

            if (\array_key_exists($name, self::NON_PRODUCT_ROUTES)) {
                continue;
            }

            $unregistered[$name] = $path;
        }

        self::assertSame(
            [],
            $unregistered,
            "以下路由既不在 /v1 下，也没有登记进 RouteInventoryTest::NON_PRODUCT_ROUTES：\n"
            .json_encode($unregistered, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR)
            ."\n\n非 /v1 的端点会自动豁免 X-Client、幂等等全部横切规则（见 ApiSurface）。"
            ."\n如果这是有意的，把它加进那份清单并写明理由；否则把它挪到 /v1 下。",
        );
    }

    /**
     * 反过来查：清单里列的路由必须真的存在。
     *
     * 删了路由却留着豁免条目，下次有人新建同名路由时会拿到一份它并不需要的豁免。
     */
    public function testExemptionListHasNoStaleEntries(): void
    {
        self::createClient();

        /** @var RouterInterface $router */
        $router = self::getContainer()->get('router');
        $collection = $router->getRouteCollection();

        foreach (self::NON_PRODUCT_ROUTES as $name => $reason) {
            self::assertNotNull(
                $collection->get($name),
                \sprintf('豁免清单里的路由 %s 已不存在（登记理由：%s），请删掉这条', $name, $reason),
            );
        }
    }

    /**
     * ⚠️ **T-106 的验收标准前半条**：「`GET` 与 `HEAD` 不改变 `consumed_at`」。
     *
     * 任务卡把它写成一条集成测试，但落地页根本不在后端 —— 它是一份由 Caddy
     * 直接吐出的静态 HTML（`infra/caddy/site/l/`，ADR-0016）。于是那条要求
     * 在这里变成一条更强的断言：**后端在 `/l/` 下没有任何路由**，
     * 所以「GET 消费了令牌」在结构上无法发生，不是靠某条用例守着。
     *
     * `/l/*` 是 App Links 的域（`APP_PUBLIC_BASE_URL`），今天有三个用途：
     * `/l/magic/<token>`（T-106）、`/l/devices`（T-104 的「这不是我」）、
     * `/l/security`（T-105 的安全提醒）。三者都必须是静态的：
     * 企业邮件安全网关会自动 GET 邮件里的每一个链接（§7.1）。
     *
     * ⚠️ 真要在后端加一条 `/l/` 路由的话，先读 ADR-0016 的 Alternatives，
     * 那里写了为什么它被否掉。
     */
    public function testTheBackendServesNothingUnderTheAppLinksPath(): void
    {
        self::createClient();

        /** @var RouterInterface $router */
        $router = self::getContainer()->get('router');

        $offenders = [];

        foreach ($router->getRouteCollection() as $name => $route) {
            if (str_starts_with($route->getPath(), '/l/')) {
                $offenders[$name] = $route->getPath();
            }
        }

        self::assertSame(
            [],
            $offenders,
            "以下路由落在 App Links 的 /l/ 域下：\n"
            .json_encode($offenders, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR)
            ."\n\n那个域只放静态落地页（infra/caddy/site/l/）。邮件安全网关会自动 GET"
            ."\n邮件里的每个链接（§7.1）—— 后端一旦在那里有代码，「GET 不改变状态」"
            ."\n就从结构保证退化成了一条要靠人记住的约定。理由见 ADR-0016。",
        );
    }

    /**
     * 探活端点必须留在 `/health/` 下 —— §6.2 明确要求它们不在 `/v1` 下，
     * 而 compose 的 healthcheck 与 Caddy 都按这个路径硬编码。
     */
    public function testHealthEndpointsKeepTheirPaths(): void
    {
        self::createClient();

        /** @var RouterInterface $router */
        $router = self::getContainer()->get('router');

        foreach (['health_live', 'health_ready'] as $name) {
            $route = $router->getRouteCollection()->get($name);

            self::assertNotNull($route);
            self::assertStringStartsWith('/health/', $route->getPath());
            self::assertFalse(ApiSurface::isProductApiPath($route->getPath()));
        }
    }

    /**
     * 测试夹具绝不能出现在 dev/prod 的路由表里。
     */
    public function testProbeFixtureIsTestOnly(): void
    {
        self::createClient();

        /** @var RouterInterface $router */
        $router = self::getContainer()->get('router');

        // test 环境里应该有 —— 否则 Api 套件里那些 /v1 测试是在测空气。
        //
        // 它只在 config/services.yaml 的 `when@test:` 段里注册，dev/prod 容器里
        // 连服务都不存在（`bin/console --env=dev debug:router` 里搜不到 probe）。
        // 这里断言它带着 `/v1/_probe/` 这个一眼可辨的前缀 —— 万一哪天漏进了
        // dev 的路由表，也能立刻认出来是什么东西。
        $route = $router->getRouteCollection()->get('probe_echo');

        self::assertNotNull($route);
        self::assertStringStartsWith('/v1/_probe/', $route->getPath());
    }
}
