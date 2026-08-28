<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Shared\Domain\Error\DomainException;
use App\Shared\Domain\Error\ErrorCode;
use App\Shared\Infrastructure\Http\ClientVersionListener;
use App\Tests\Api\Support\ProblemDetailsAssertions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * `X-Client` 的强制与**豁免**（§6.1、T-004 的头号事故点）。
 *
 * ============================================================================
 * ⚠️ 本文件里最重要的一条是 testHealthEndpointsAreExempt
 * ============================================================================
 * T-004 的任务书、backend/README.md、HealthController 的类注释，三处都留了同一条警告：
 * 漏掉 `/health/*` 的豁免，两个探活端点会在本任务合入当天集体变 400，
 * 连带 compose 起栈与 §14.3 的 staging 部署健康检查一起失效。
 *
 * 那条测试红了 = 整个栈的健康检查要挂。别改它去迁就实现。
 */
#[CoversClass(ClientVersionListener::class)]
final class ClientVersionEnforcementTest extends WebTestCase
{
    use ProblemDetailsAssertions;

    // ========================================================================
    // 强制（/v1/*）
    // ========================================================================

    public function testProductApiRequiresTheHeader(): void
    {
        $client = self::createClient();
        $client->catchExceptions(true);

        $client->request('GET', '/v1/_probe/echo');

        $body = self::assertIsProblemDetails($client->getResponse(), ErrorCode::ValidationFailed);

        self::assertSame(
            [['field' => 'X-Client', 'code' => 'required', 'message' => 'The X-Client header is required.']],
            $body['errors'],
            '要明说是哪个 header 缺了 —— 光一个 400 会让客户端开发者无从下手',
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedHeaders(): iterable
    {
        yield 'platform only' => ['android'];
        yield 'no build' => ['android/1.4.0'];
        yield 'non-numeric build' => ['android/1.4.0 (beta)'];
        yield 'uppercase platform' => ['Android/1.4.0 (26)'];
        yield 'two-part version' => ['android/1.4 (26)'];
    }

    #[DataProvider('malformedHeaders')]
    public function testMalformedHeaderIsRejected(string $header): void
    {
        $client = self::createClient();
        $client->catchExceptions(true);

        $client->request('GET', '/v1/_probe/echo', server: ['HTTP_X_CLIENT' => $header]);

        $body = self::assertIsProblemDetails($client->getResponse(), ErrorCode::ValidationFailed);

        self::assertSame('X-Client', $body['errors'][0]['field']);
        self::assertSame('invalid_format', $body['errors'][0]['code']);
    }

    /**
     * 只有空白的 header 等同于**没发**，报 `required` 而不是 `invalid_format`。
     *
     * 这个区分对客户端开发者有实际意义：`required` 说的是「你没发」，
     * `invalid_format` 说的是「你发了但格式不对」—— 两种 bug 的排查方向不同。
     */
    public function testWhitespaceOnlyHeaderCountsAsMissing(): void
    {
        $client = self::createClient();
        $client->catchExceptions(true);

        $client->request('GET', '/v1/_probe/echo', server: ['HTTP_X_CLIENT' => '   ']);

        $body = self::assertIsProblemDetails($client->getResponse(), ErrorCode::ValidationFailed);

        self::assertSame('required', $body['errors'][0]['code']);
    }

    public function testWellFormedHeaderIsParsedAndInjected(): void
    {
        $client = self::createClient();

        $client->request('GET', '/v1/_probe/echo', server: ['HTTP_X_CLIENT' => 'android/1.4.0 (26)']);

        self::assertResponseIsSuccessful();

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        // 探针把 ClientVersion 作为**控制器参数**收到，顺带证明
        // ClientVersionValueResolver 是通的。
        self::assertSame('android/1.4.0 (26)', $body['client']);
    }

    /**
     * §6.1：426 client_too_old → 客户端弹强制升级墙。
     *
     * 判定在 T-004 的监听器里，而阈值来自 `%ncards.min_supported_client%` ——
     * T-112 的 `GET /v1/config` 会读**同一个参数**下发。覆盖那个参数就能今天就测到这条分支。
     */
    public function testClientBelowTheMinimumSupportedVersionIsRejected(): void
    {
        $client = self::createClient();
        $client->catchExceptions(true);
        $client->disableReboot();

        self::getContainer()->set(
            ClientVersionListener::class,
            new ClientVersionListener('android/2.0.0 (100)'),
        );

        $client->request('GET', '/v1/_probe/echo', server: ['HTTP_X_CLIENT' => 'android/1.4.0 (26)']);

        self::assertIsProblemDetails($client->getResponse(), ErrorCode::ClientTooOld);
    }

    // ========================================================================
    // 配置错误
    // ========================================================================

    /**
     * ⚠️ `MIN_SUPPORTED_CLIENT` 配错是**服务端**故障，不能表现成客户端的 400。
     *
     * `ClientVersion::parse` 抛的是 validation_failed + FieldError('X-Client')，
     * 那套语义是给客户端发来的 header 准备的。构造期原样冒出去的话：
     *   - 一个环境变量的拼写错误（比如漏了 `android/` 前缀）会让每个请求收到 400，
     *     指着客户端说「你的 X-Client 格式不对」；
     *   - `/health/live` 与 `/health/ready` 同样 400 —— EventDispatcher::sortListeners()
     *     在调用任何监听器之前就实例化了全部监听器，所以豁免判断根本没机会跑，
     *     compose healthcheck 与 §14.3 的 Ansible 部署一起失败；
     *   - validation_failed 按 info 记日志，§14.4 的 5xx 告警一声不响。
     *
     * 换成 LogicException 后归到 internal_error(500)：健康检查照挡流量，告警照响，
     * 而且错误不再赖到客户端头上。
     *
     * @return iterable<string, array{string}>
     */
    public static function malformedMinimumVersions(): iterable
    {
        yield 'missing platform prefix' => ['1.4.0'];
        yield 'missing build' => ['android/1.4.0'];
        yield 'empty' => [''];
    }

    #[DataProvider('malformedMinimumVersions')]
    public function testMalformedMinimumSupportedVersionIsAServerError(string $configured): void
    {
        self::expectException(\LogicException::class);
        self::expectExceptionMessage('MIN_SUPPORTED_CLIENT is not a valid client version');

        new ClientVersionListener($configured);
    }

    /**
     * 配置错误绝不能伪装成 DomainException —— 那是 4xx 的家族，会把服务端故障
     * 渲染成客户端错误，并把日志级别降到 info。
     */
    public function testMalformedMinimumIsNotADomainException(): void
    {
        try {
            new ClientVersionListener('1.4.0');
            self::fail('配错的最低版本必须抛异常');
        } catch (\Throwable $e) {
            self::assertNotInstanceOf(
                DomainException::class,
                $e,
                '服务端配置错误不能走 DomainException —— 那会变成一个 400，并且不触发 §14.4 的 5xx 告警',
            );
        }
    }

    // ========================================================================
    // 豁免（一切非 /v1）
    // ========================================================================

    /**
     * ⚠️⚠️ T-004 的头号事故点。
     *
     * 探活端点的调用方是 Docker healthcheck、Caddy 与 §14.3 部署流程里的 Ansible，
     * 它们**不带** `X-Client`。这条红了，`docker compose up` 与 staging 部署会一起失效。
     *
     * @return iterable<string, array{string}>
     */
    public static function exemptPaths(): iterable
    {
        yield 'health live' => ['/health/live'];
        yield 'health ready' => ['/health/ready'];
    }

    #[DataProvider('exemptPaths')]
    public function testHealthEndpointsAreExempt(string $path): void
    {
        $client = self::createClient();

        // 刻意**不**带 X-Client —— 这正是 Docker / Caddy / Ansible 的行为。
        $client->request('GET', $path);

        self::assertNotSame(
            Response::HTTP_BAD_REQUEST,
            $client->getResponse()->getStatusCode(),
            $path.' 不该要求 X-Client。ClientVersionListener 的豁免写反了 —— '
            .'见 ApiSurface::isProductApiPath()。这会让 compose healthcheck 与 §14.3 的部署健康检查一起失效。',
        );
    }

    /**
     * 未匹配到任何路由的非 `/v1` 路径也必须豁免 —— 说明判定确实按路径前缀，
     * 而不是靠路由结果（那样在 404 之前就没机会豁免了）。
     */
    public function testUnknownNonV1PathIsExemptToo(): void
    {
        $client = self::createClient();
        $client->catchExceptions(true);

        $client->request('GET', '/metrics');

        // 应该是 404（路由不存在），而不是 400（缺 header）。
        self::assertSame(Response::HTTP_NOT_FOUND, $client->getResponse()->getStatusCode());
    }

    /**
     * ⚠️ ClientVersionListener 跑在 RouterListener **之前**（40 > 32），
     * 所以 `/v1` 下不存在的路由会先因缺 header 报 400，而不是 404。
     *
     * 这是**刻意**的：否则客户端看到的是「端点不存在」，排查方向直接被带偏。
     */
    public function testMissingHeaderBeatsRoutingInsideV1(): void
    {
        $client = self::createClient();
        $client->catchExceptions(true);

        $client->request('GET', '/v1/definitely-not-a-route');

        self::assertIsProblemDetails($client->getResponse(), ErrorCode::ValidationFailed);
    }
}
