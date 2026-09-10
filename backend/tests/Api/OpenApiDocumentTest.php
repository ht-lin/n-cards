<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Shared\Domain\Client\ClientVersion;
use App\Shared\Domain\Config\MaintenanceMessageKey;
use App\Shared\Domain\Error\DomainException;
use App\Shared\Domain\Error\ErrorCode;
use App\Shared\Domain\Error\FieldErrorCode;
use App\Shared\Domain\Http\ApiSurface;
use App\Shared\Http\Pagination\CursorPaginator;
use App\Tests\Api\Support\OpenApiContract;
use League\OpenAPIValidation\PSR7\OperationAddress;
use League\OpenAPIValidation\PSR7\PathFinder;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * 契约文件本身的守卫 —— 不发 HTTP，只看 `docs/api/openapi.yaml` 说了什么。
 *
 * ============================================================================
 * 与另外两条测试的分工
 * ============================================================================
 * - {@see OpenApiContractHarnessTest}：校验器**能不能咬人**（正反夹具）。
 * - {@see \App\Tests\Unit\Shared\Domain\Error\ProblemDetailsSchemaTest}：
 *   T-004 那份 JSON Schema 与 PHP enum 的一致性。
 * - 本文件：契约与**这个仓库里其它已经写死的东西**是否还对得上 ——
 *   路由表、ErrorCode、ClientVersion、CursorPaginator。
 *
 * 这些断言单看每一条都很小，但它们守的是同一件事：**契约不是一份写完就没人看的
 * 文档**。§13.1 说它是唯一真相源，那就必须有东西在实现漂开它的时候立刻变红。
 */
#[CoversNothing]
final class OpenApiDocumentTest extends WebTestCase
{
    use OpenApiContract;

    /**
     * 契约的原始 YAML（**未解引用**）。
     *
     * 有些断言必须看没解开的样子 —— 典型的是 `components/schemas/Problem` 必须
     * 是一个 `$ref` 而不是一份拷贝，解引用之后就分不出这两种情况了。
     *
     * @return array<string, mixed>
     */
    private static function rawContract(): array
    {
        /** @var array<string, mixed> $parsed */
        $parsed = Yaml::parseFile(self::contractPath());

        return $parsed;
    }

    // ========================================================================
    // 文档基本面
    // ========================================================================

    public function testContractDeclaresOpenApi31(): void
    {
        // 不是 3.0：§13.1 第 1 条要求 3.1，而 3.1 才对齐 JSON Schema 2020-12 ——
        // 也才能 $ref 到 T-004 那份 draft 2020-12 的 problem-details.schema.json。
        self::assertSame('3.1.0', self::rawContract()['openapi']);
    }

    /**
     * servers 的 url 必须带 `/v1`，于是 paths 下不带。
     *
     * 这两件事必须同时成立：漏了 servers 的 `/v1`，契约描述的就是另一组 URL；
     * 在 paths 里又写一遍 `/v1`，实际 URL 会变成 `/v1/v1/cards`。
     */
    public function testServersCarryTheVersionPrefixAndPathsDoNot(): void
    {
        /** @var list<array<string, mixed>> $servers */
        $servers = self::rawContract()['servers'];

        self::assertNotEmpty($servers);

        foreach ($servers as $server) {
            self::assertStringEndsWith(
                rtrim(ApiSurface::V1_PREFIX, '/'),
                (string) $server['url'],
                'servers 的 url 必须以 /v1 结尾（§6.1 的基址）',
            );
        }

        /** @var array<string, mixed> $paths */
        $paths = self::rawContract()['paths'];

        foreach (array_keys($paths) as $path) {
            self::assertStringStartsNotWith(
                ApiSurface::V1_PREFIX,
                (string) $path,
                \sprintf('契约路径 %s 重复带了 /v1 —— servers 里已经有了，实际 URL 会变成 /v1/v1/…', $path),
            );
        }
    }

    /**
     * `/health/*` **不能**出现在契约里。
     *
     * 它们刻意不在 `/v1` 下（§6.2），调用方是 Docker healthcheck、Caddy 与
     * Ansible，不带 `X-Client`，也不受 §13.6 的演进约束。把它们写进契约会让
     * T-010 给它们生成客户端方法 —— 而那是运维接口，不是产品 API。
     * 与 {@see RouteInventoryTest} 的豁免清单是同一条边界的两面。
     */
    public function testHealthEndpointsAreNotInTheContract(): void
    {
        /** @var array<string, mixed> $paths */
        $paths = self::rawContract()['paths'];

        foreach (array_keys($paths) as $path) {
            self::assertStringStartsNotWith('/health', (string) $path);
        }
    }

    public function testEveryOperationHasAUniqueOperationId(): void
    {
        $ids = [];

        foreach (self::contractValidator()->getSchema()->paths->getPaths() as $template => $pathItem) {
            foreach ($pathItem->getOperations() as $method => $operation) {
                $where = \sprintf('%s %s', strtoupper((string) $method), $template);

                self::assertNotEmpty($operation->operationId, $where.' 缺少 operationId');
                self::assertArrayNotHasKey(
                    $operation->operationId,
                    $ids,
                    \sprintf('operationId「%s」重复：%s 与 %s', $operation->operationId, $where, $ids[$operation->operationId] ?? ''),
                );

                $ids[$operation->operationId] = $where;
            }
        }

        self::assertNotEmpty($ids, '契约里一个操作都没有');
    }

    // ========================================================================
    // Problem —— 三方一致性延伸到 yaml
    // ========================================================================

    /**
     * ⚠️ `components/schemas/Problem` 必须是一个**指向外部文件的 `$ref`**，
     * 不能是拷贝。
     *
     * 这条断言看的是**未解引用**的 yaml —— 一旦有人把 problem-details.schema.json
     * 的内容复制进 openapi.yaml，解引用后的结果一模一样，只有原始形态能分辨。
     * 而两份 `code` 枚举必然会漂，Android 的 ApiError sealed class 是照它生成的。
     */
    public function testProblemSchemaIsAReferenceToTheSharedFileNotACopy(): void
    {
        /** @var array<string, mixed> $components */
        $components = self::rawContract()['components'];
        /** @var array<string, mixed> $schemas */
        $schemas = $components['schemas'];
        /** @var array<string, mixed> $problem */
        $problem = $schemas['Problem'];

        self::assertSame(
            ['$ref' => './schemas/problem-details.schema.json'],
            $problem,
            'components/schemas/Problem 只能是一个指向 docs/api/schemas/problem-details.schema.json 的 $ref。'
            .'复制一份的话两边的 code 枚举必然会漂，而 T-010 的 ApiError 是照它生成的。',
        );
    }

    /**
     * 契约（解引用之后）里的 `code` 枚举与 `ErrorCode` **逐项相等**。
     *
     * ProblemDetailsSchemaTest 已经钉住了「JSON Schema 文件 ↔ PHP enum」。
     * 这条钉的是第三段：「openapi.yaml 解析出来的 ↔ PHP enum」——
     * 它能抓住上一条抓不到的情况，比如 $ref 指错了文件、或者解引用因为路径变化
     * 而悄悄失败并退化成一个空 schema。
     */
    public function testResolvedProblemCodeEnumMatchesErrorCode(): void
    {
        $problem = self::contractValidator()->getSchema()->components?->schemas['Problem'] ?? null;

        self::assertNotNull($problem, '契约里没有 components/schemas/Problem');

        $codeEnum = $problem->properties['code']->enum ?? null;

        self::assertIsArray($codeEnum, '解引用后的 Problem 没有 properties.code.enum —— $ref 可能没解开');
        self::assertSame(
            array_map(static fn (ErrorCode $c): string => $c->value, ErrorCode::cases()),
            $codeEnum,
            '契约里的 code 枚举与 App\Shared\Domain\Error\ErrorCode 不一致',
        );
    }

    public function testResolvedFieldErrorCodeEnumMatchesFieldErrorCode(): void
    {
        $problem = self::contractValidator()->getSchema()->components?->schemas['Problem'] ?? null;

        self::assertNotNull($problem);

        $fieldCodeEnum = $problem->properties['errors']->items->properties['code']->enum ?? null;

        self::assertIsArray($fieldCodeEnum);
        self::assertSame(
            array_map(static fn (FieldErrorCode $c): string => $c->value, FieldErrorCode::cases()),
            $fieldCodeEnum,
            '契约里的 errors[].code 枚举与 App\Shared\Domain\Error\FieldErrorCode 不一致',
        );
    }

    // ========================================================================
    // 契约 ↔ 已有实现
    // ========================================================================

    /**
     * 契约里的 `X-Client` pattern 与 `ClientVersion::PATTERN` 的**判定结果一致**。
     *
     * 不比较正则字符串本身 —— 后端那条带命名捕获组，写法必然不同，比字符串只会
     * 得到一条永远为假的断言。比行为才有意义：同一个 header 值，契约说合法、
     * 后端说非法（或反过来），就是真的不一致。
     *
     * 唯一的已知差异是后端会先 `trim()`，正则表达不了 —— 所以对照表里不放
     * 带首尾空格的样本。
     */
    #[DataProvider('clientHeaderSamples')]
    public function testXClientPatternAgreesWithClientVersion(string $header): void
    {
        /** @var array<string, mixed> $components */
        $components = self::rawContract()['components'];
        /** @var array<string, mixed> $parameters */
        $parameters = $components['parameters'];
        /** @var array<string, mixed> $xClient */
        $xClient = $parameters['XClient'];
        /** @var array<string, mixed> $schema */
        $schema = $xClient['schema'];

        $contractAccepts = 1 === preg_match('/'.str_replace('/', '\/', (string) $schema['pattern']).'/', $header);

        try {
            ClientVersion::parse($header);
            $backendAccepts = true;
        } catch (DomainException) {
            $backendAccepts = false;
        }

        self::assertSame(
            $backendAccepts,
            $contractAccepts,
            \sprintf(
                '「%s」：契约说%s，ClientVersion 说%s。'
                .'改 ClientVersion::PATTERN 时必须同步改契约里的 XClient.schema.pattern。',
                $header,
                $contractAccepts ? '合法' : '非法',
                $backendAccepts ? '合法' : '非法',
            ),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function clientHeaderSamples(): iterable
    {
        foreach ([
            'android/1.4.0 (26)',      // 规范形式
            'android/1.4.0(26)',       // 括号前无空格 —— 两边都该接受
            'ios/2.0.10 (1)',          // 二期的平台
            'android/1.4 (26)',        // 两段式版本 —— 两边都该拒绝
            'android/1.4.0',           // 缺 build
            'Android/1.4.0 (26)',      // 大写平台名
            'android/1.4.0 (26) extra', // 尾部有多余内容
            '',                        // 空
        ] as $header) {
            yield ('' === $header ? '(空)' : $header) => [$header];
        }
    }

    /**
     * 契约里 `Maintenance.message_key` 的 `enum` 与 `MaintenanceMessageKey` 逐项一致（T-112）。
     *
     * ⚠️ 漂了的症状是**静默的**，而且只在生产显形：客户端（T-158）按这个值分支
     * 渲染两种横幅，契约里少一个值 → 生成出来的 Kotlin 枚举里也少一个 →
     * 公告期的响应反序列化失败或落到 else 分支，于是横幅直接不显示。
     * 而这一切只会在真的有维护公告的那天发生。
     *
     * `null` 在契约的列表里是一个合法取值（「无公告」），PHP 侧用 `?enum` 表达，
     * 所以比较前要把它摘掉。
     */
    public function testMaintenanceMessageKeysAgreeWithTheContract(): void
    {
        /** @var array<string, mixed> $components */
        $components = self::rawContract()['components'];
        /** @var array<string, mixed> $schemas */
        $schemas = $components['schemas'];
        /** @var array<string, mixed> $maintenance */
        $maintenance = $schemas['Maintenance'];
        /** @var array<string, mixed> $properties */
        $properties = $maintenance['properties'];
        /** @var array<string, mixed> $messageKey */
        $messageKey = $properties['message_key'];

        /** @var list<string|null> $enum */
        $enum = $messageKey['enum'];

        self::assertContains(
            null,
            $enum,
            '`null` 必须是合法取值 —— 「无公告」是常态（无窗口、窗口在 24 小时以外、窗口已结束）。',
        );

        self::assertSame(
            MaintenanceMessageKey::values(),
            array_values(array_filter($enum, static fn (?string $value): bool => null !== $value)),
            '契约的 Maintenance.message_key 与 App\Shared\Domain\Config\MaintenanceMessageKey 漂了。'
            ."\n改枚举时必须同步改契约（§13.6 允许**新增**枚举值，不允许改名）。",
        );
    }

    /**
     * `components/schemas/ClientVersion` 与 `components/parameters/XClient` 的
     * pattern **逐字相同**（T-112）。
     *
     * ⚠️ 为什么是两份拷贝而不是一个 `$ref`：
     * {@see testXClientPatternAgreesWithClientVersion()} 直接读
     * `components.parameters.XClient.schema.pattern` 这一条原始路径，把它换成
     * `$ref` 之后那条与 `ClientVersion::PATTERN` 的逐例对账就读不到东西了。
     *
     * 两份拷贝加一条「必须相同」的断言，与一个 $ref 的效果等价，而且不需要在
     * 测试里实现 $ref 解析。
     */
    public function testClientVersionSchemaAndXClientShareOnePattern(): void
    {
        /** @var array<string, mixed> $components */
        $components = self::rawContract()['components'];
        /** @var array<string, mixed> $parameters */
        $parameters = $components['parameters'];
        /** @var array<string, mixed> $xClient */
        $xClient = $parameters['XClient'];
        /** @var array<string, mixed> $headerSchema */
        $headerSchema = $xClient['schema'];

        /** @var array<string, mixed> $schemas */
        $schemas = $components['schemas'];
        /** @var array<string, mixed> $clientVersion */
        $clientVersion = $schemas['ClientVersion'];

        self::assertSame(
            $headerSchema['pattern'],
            $clientVersion['pattern'],
            'ClientConfig 下发的版本串与 X-Client 收到的是同一个形态，pattern 必须逐字相同。',
        );
    }

    /**
     * 分页参数与 `CursorPaginator` 的常量一致。
     */
    public function testLimitParameterMatchesCursorPaginator(): void
    {
        /** @var array<string, mixed> $components */
        $components = self::rawContract()['components'];
        /** @var array<string, mixed> $parameters */
        $parameters = $components['parameters'];
        /** @var array<string, mixed> $limit */
        $limit = $parameters['Limit'];
        /** @var array<string, mixed> $schema */
        $schema = $limit['schema'];

        self::assertSame(CursorPaginator::DEFAULT_LIMIT, $schema['default'] ?? null);
        self::assertSame(1, $schema['minimum'] ?? null, 'limit=0 与负数是客户端 bug，后端返回 400');

        // ⚠️ 刻意**不**写 maximum：超过 MAX_LIMIT 的请求会被静默夹取到 200 而不是
        // 报错（见 CursorPaginator 的类注释）。写上 maximum 的话契约就在说
        // `limit=500` 非法 —— 而服务端明明会接受它。
        self::assertArrayNotHasKey(
            'maximum',
            $schema,
            'limit 超上限是**夹取**不是拒绝，写 maximum 会让契约与实现说两套话；'
            .'上限 '.CursorPaginator::MAX_LIMIT.' 写在 description 里。',
        );

        self::assertStringContainsString(
            (string) CursorPaginator::MAX_LIMIT,
            (string) $limit['description'],
            'description 里必须写明上限值',
        );
    }

    /**
     * ⚠️ **反向漂移守卫 —— §13.1「契约优先」在 CI 里的强制点。**.
     *
     * 路由表里每一条产品 API 路由都必须在契约里找得到。
     *
     * 今天这条断言是空过的：`/v1` 下只有 `when@test` 注册的探针，产品端点一个
     * 都还没实现（Auth 属于 T-103/104，Cards 属于 T-109）。但从第一个真实端点
     * 落地那天起，它就开始咬：先写 Controller、忘了改 openapi.yaml 的 PR 当场红。
     *
     * 这是 {@see RouteInventoryTest} 的对偶 —— 那条守「谁可以不在 `/v1` 下」，
     * 这条守「在 `/v1` 下的都必须在契约里」。
     */
    public function testEveryProductApiRouteIsDeclaredInTheContract(): void
    {
        self::createClient();

        /** @var RouterInterface $router */
        $router = self::getContainer()->get('router');

        $undeclared = [];

        foreach ($router->getRouteCollection() as $name => $route) {
            $path = $route->getPath();

            if (!ApiSurface::isProductApiPath($path)) {
                continue;
            }

            // 测试夹具（tests/Fixture/Http/ProbeApiController）。它只在 when@test
            // 下注册，dev/prod 的路由表里根本不存在 —— RouteInventoryTest 断言了
            // 这一点。它当然不该进契约。
            if (str_starts_with($path, self::PROBE_PREFIX)) {
                continue;
            }

            foreach ($route->getMethods() ?: ['GET'] as $method) {
                if ([] === $this->contractMatchesFor($method, $path)) {
                    $undeclared[] = \sprintf('%s %s（路由 %s）', $method, $path, $name);
                }
            }
        }

        self::assertSame(
            [],
            $undeclared,
            "以下 /v1 路由没有出现在 docs/api/openapi.yaml 里：\n  "
            .implode("\n  ", $undeclared)
            ."\n\n§13.1：任何接口变更**必须先改契约**，与实现在同一个 PR 内合入。"
            ."\n（如果这条路由不该是产品 API，它就不该在 /v1 下 —— 见 RouteInventoryTest。）",
        );
    }

    /** `when@test` 的探针前缀，见 tests/Fixture/Http/ProbeApiController。 */
    private const PROBE_PREFIX = '/v1/_probe/';

    /**
     * 路由路径里的 `{id}` 占位符换成合法样本值再去契约里找 —— PathFinder 匹配的是
     * 具体 URI，不是模板。
     *
     * @return list<OperationAddress>
     */
    private function contractMatchesFor(string $method, string $routePath): array
    {
        $concrete = (string) preg_replace('/\{[^}]+\}/', '0192f3a1-b2c3-7d4e-8f01-23456789abcd', $routePath);

        return array_values((new PathFinder(
            self::contractValidator()->getSchema(),
            $concrete,
            strtolower($method),
        ))->search());
    }
}
