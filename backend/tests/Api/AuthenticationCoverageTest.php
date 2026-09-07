<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Shared\Domain\Http\ApiSurface;
use App\Shared\Infrastructure\Http\AuthenticationListener;
use App\Tests\Api\Support\OpenApiContract;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * 把「哪些端点免鉴权」这件事从**两处约定**变成**一条对账**（T-105）。
 *
 * ============================================================================
 * ⚠️ 为什么需要这个测试
 * ============================================================================
 * 免鉴权的清单存在于两个地方：
 *
 *   - 契约：`docs/api/openapi.yaml` 里带 `security: []` 的操作；
 *   - 实现：{@see AuthenticationListener::PUBLIC_ROUTES}。
 *
 * 两者漂了的话，症状是**单向静默**的：
 *
 *   - 实现里多一条 → 那个端点在生产里不再需要 token，而**所有功能测试照常绿**
 *     （它们本来就带着 token 跑）。这是一个权限洞，且没有任何东西会报警。
 *   - 实现里少一条 → 那个端点变成必须鉴权，客户端全线 401。
 *     这条至少会被功能测试抓到。
 *
 * 第一种才是这个文件真正要挡的东西。它在 T-105 里有一个具体的形状：
 * 把白名单写成路径前缀 `/v1/auth/` 会把 `POST /v1/auth/logout` 一起放过去 ——
 * 而那个端点是 auth 组里**唯一**需要 Bearer 的。
 *
 * ============================================================================
 * 对账的方向：以**已注册的路由**为准
 * ============================================================================
 * 遍历路由表而不是遍历契约，是因为契约里有些端点还没实现
 * （`POST /v1/auth/magic/consume` 归 T-106）。以契约为准的话，
 * 这个测试会为一个还不存在的路由要求一条白名单，而那条白名单会先于实现存在 ——
 * 也就是提前打开一个洞。
 */
#[CoversClass(AuthenticationListener::class)]
final class AuthenticationCoverageTest extends KernelTestCase
{
    use OpenApiContract;

    /**
     * 每一条已注册的 `/v1` 路由，其「要不要 Bearer」必须与契约逐条一致。
     */
    public function testEveryProductApiRouteAgreesWithTheContractOnAuthentication(): void
    {
        $mismatches = [];

        foreach (self::productApiRoutes() as $name => [$method, $path]) {
            $publicInCode = \in_array($name, AuthenticationListener::PUBLIC_ROUTES, true);
            $publicInContract = self::isPublicInContract($method, $path);

            if ($publicInCode === $publicInContract) {
                continue;
            }

            $mismatches[] = \sprintf(
                '%s %s（路由 %s）：契约说%s，AuthenticationListener::PUBLIC_ROUTES 说%s',
                $method,
                $path,
                $name,
                $publicInContract ? '免鉴权' : '要 Bearer',
                $publicInCode ? '免鉴权' : '要 Bearer',
            );
        }

        self::assertSame(
            [],
            $mismatches,
            "免鉴权清单在契约与实现之间漂了：\n  ".implode("\n  ", $mismatches)
            ."\n\n新增免鉴权端点要同时改两处：docs/api/openapi.yaml 的 `security: []`，"
            .'与 AuthenticationListener::PUBLIC_ROUTES。',
        );
    }

    /**
     * ⚠️ 单独钉死这一条，因为它是最容易被「路径前缀」写法误伤的那个。
     *
     * 它与上面那条不重复：上面是对账，这条是一句**断言**——
     * 哪天有人同时改了契约与白名单（比如为了「让登出更方便」），
     * 对账仍然是绿的，而这条会红。
     */
    public function testLogoutIsNotOnThePublicList(): void
    {
        self::assertNotContains(
            'auth_logout',
            AuthenticationListener::PUBLIC_ROUTES,
            'POST /v1/auth/logout 是 auth 组里唯一需要 Bearer 的端点（§7.1 / 契约）。'
            .'把它放进白名单等于让任何人都能撤销任何会话，而所有测试照常绿。',
        );
    }

    /**
     * 白名单里不该有 `probe_` 开头的东西 —— 那些是测试夹具，
     * 走的是 `ncards.auth.public_routes.test_only`（生产恒为空）。
     */
    public function testThePublicListContainsNoTestFixtures(): void
    {
        foreach (AuthenticationListener::PUBLIC_ROUTES as $route) {
            self::assertStringStartsNotWith(
                'probe_',
                $route,
                '夹具路由属于 config/packages/ncards_auth.yaml 的 when@test 段，不属于生产白名单。',
            );
        }
    }

    /**
     * 契约里那个操作有没有 `security: []` 覆盖掉全局的 `bearerAuth`。
     *
     * ============================================================================
     * ⚠️ 直接读 YAML，不走 cebe/openapi 的对象树
     * ============================================================================
     * 那棵树把 `Operation::$security` 标注成 `array<SecurityRequirement>`，
     * 而它在「没写 security」时**实际返回 null** —— 也就是说 PHPDoc 与运行时
     * 不一致。照着它写 `null !== $spec->security`，PHPStan 会判定这个比较恒真
     * 并报错；而把比较去掉，测试就再也分不出「没写」与「写了空数组」，
     * 于是**每个端点都会被判成免鉴权**，这个文件恒绿且毫无价值。
     *
     * 而这里要区分的恰好就是那两者：
     *   - 键**不存在** → 继承全局的 `bearerAuth`，要 Bearer；
     *   - 键存在且是 `[]` → 显式覆盖，免鉴权。
     *
     * 源文件里这个区别是明摆着的，所以就读源文件。
     */
    private static function isPublicInContract(string $method, string $path): bool
    {
        // 用 contractOperation() 把路由路径解析成契约里的路径模板
        // （它顺带断言了「这个端点在契约里存在」）。
        $template = self::contractOperation($method, $path)->path();

        $operation = self::contract()['paths'][$template][strtolower($method)] ?? null;

        self::assertIsArray($operation, \sprintf('契约里没有 %s %s。', strtoupper($method), $template));

        return \array_key_exists('security', $operation) && [] === $operation['security'];
    }

    /**
     * @return array{paths: array<string, array<string, mixed>>}
     */
    private static function contract(): array
    {
        /** @var array{paths: array<string, array<string, mixed>>} $parsed */
        $parsed = Yaml::parseFile(self::contractPath());

        return $parsed;
    }

    /**
     * @return iterable<string, array{string, string}> 路由名 => [方法, 路径模板]
     */
    private static function productApiRoutes(): iterable
    {
        self::bootKernel();

        /** @var RouterInterface $router */
        $router = self::getContainer()->get('router');

        foreach ($router->getRouteCollection() as $name => $route) {
            $path = $route->getPath();

            if (!ApiSurface::isProductApiPath($path)) {
                continue;
            }

            // 夹具路由（`/v1/_probe/*`）不在契约里，也不该在。
            if (str_starts_with($name, 'probe_')) {
                continue;
            }

            foreach ($route->getMethods() ?: ['GET'] as $method) {
                // ⚠️ 路径**带** `/v1` 前缀原样传给 PathFinder ——
                // 它自己会按 `servers` 解析掉那一段（口径同 OtpVerifyEndpointTest）。
                // 手工剥掉的话它一条都匹配不上，而报错会说「契约里没有这个端点」，
                // 把人引向「是不是忘了改契约」这个错误方向。
                yield $name => [$method, $path];
            }
        }
    }
}
