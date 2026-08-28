<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared\Http;

use App\Shared\Infrastructure\Http\ApiProblemExceptionListener;
use App\Shared\Infrastructure\Http\ClientVersionListener;
use App\Shared\Infrastructure\Http\IdempotencyMiddleware;
use App\Shared\Infrastructure\Http\RequestIdListener;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * 把四个横切监听器的**相对顺序**从注释变成契约。
 *
 * ============================================================================
 * 为什么需要这个测试
 * ============================================================================
 * 优先级是用 `#[AsEventListener(priority: ...)]` 写在各自的类文件里的 —— 分散、
 * 没有任何一处能看到全貌。而这些顺序全都是承重的：
 *
 *   - RequestIdListener 必须**最先**，否则后面任何监听器抛的异常都没有 request_id
 *   - ClientVersionListener 必须早于 RouterListener，否则 `/v1/typo` 缺 header 会先 404
 *   - IdempotencyMiddleware 必须晚于 RouterListener，否则 `POST /v1/typo` 会白烧一个键
 *   - ApiProblemExceptionListener 必须早于 ErrorListener 的两个回调（见它的类注释）
 *
 * 随手改一个数字，功能测试大概率还是绿的 —— 只有这里会红。
 */
#[CoversNothing]
final class ListenerOrderTest extends KernelTestCase
{
    private static function dispatcher(): EventDispatcherInterface
    {
        self::bootKernel();

        /** @var EventDispatcherInterface $dispatcher */
        $dispatcher = self::getContainer()->get('event_dispatcher');

        return $dispatcher;
    }

    /**
     * @return list<string> 形如 `Fqcn::method`，按执行顺序
     */
    private static function listenersFor(string $event): array
    {
        $names = [];

        foreach (self::dispatcher()->getListeners($event) as $listener) {
            if (!\is_array($listener)) {
                continue;
            }

            $object = $listener[0] ?? null;
            $method = $listener[1] ?? null;

            if (!\is_object($object) || !\is_string($method)) {
                continue;
            }

            $names[] = $object::class.'::'.$method;
        }

        return $names;
    }

    private static function assertRunsBefore(string $earlier, string $later, string $event): void
    {
        $order = self::listenersFor($event);

        $earlierIndex = array_search($earlier, $order, true);
        $laterIndex = array_search($later, $order, true);

        self::assertIsInt($earlierIndex, $earlier.' 没有注册在 '.$event." 上。\n实际顺序：\n".implode("\n", $order));
        self::assertIsInt($laterIndex, $later.' 没有注册在 '.$event." 上。\n实际顺序：\n".implode("\n", $order));

        self::assertLessThan(
            $laterIndex,
            $earlierIndex,
            \sprintf("%s 必须早于 %s。\n实际顺序：\n%s", $earlier, $later, implode("\n", $order)),
        );
    }

    // ========================================================================
    // kernel.request
    // ========================================================================

    /**
     * request_id 必须在任何**会碰请求**的监听器之前生成 —— 后面谁抛异常，
     * problem body 与日志里都得有它。
     *
     * 唯一排在它前面的是 `DebugHandlersListener::configure`（优先级 2048，
     * 且只在 debug 下注册）：它配置的是 PHP 的错误处理器，不读也不写请求，
     * 更不会抛出会变成 API 响应的异常。所以断言写成「第一个**应用**监听器」
     * 而不是「第一个监听器」—— 后者会因为一个与我们无关的调试设施而误报。
     */
    public function testRequestIdIsGeneratedBeforeAnyListenerThatTouchesTheRequest(): void
    {
        $order = self::listenersFor(KernelEvents::REQUEST);

        $ours = array_search(RequestIdListener::class.'::onRequest', $order, true);
        self::assertIsInt($ours, "RequestIdListener 没有注册。\n实际顺序：\n".implode("\n", $order));

        $harmlessBefore = ['Symfony\Component\HttpKernel\EventListener\DebugHandlersListener::configure'];

        foreach (\array_slice($order, 0, $ours) as $earlier) {
            self::assertContains(
                $earlier,
                $harmlessBefore,
                \sprintf(
                    "%s 跑在了 RequestIdListener 之前。\n它一旦抛异常，那条错误就没有 request_id 可以追。\n实际顺序：\n%s",
                    $earlier,
                    implode("\n", $order),
                ),
            );
        }
    }

    /**
     * 具体地：早于 Symfony 优先级最高的那个真正处理请求的监听器。
     */
    public function testRequestIdRunsBeforeSymfonyRequestValidation(): void
    {
        self::assertRunsBefore(
            RequestIdListener::class.'::onRequest',
            'Symfony\Component\HttpKernel\EventListener\ValidateRequestListener::onKernelRequest',
            KernelEvents::REQUEST,
        );
    }

    /**
     * 也早于我们自己另外三个 —— 它们抛的 400/409/422 都必须可追踪。
     */
    public function testRequestIdRunsBeforeOurOtherListeners(): void
    {
        foreach ([ClientVersionListener::class.'::onRequest', IdempotencyMiddleware::class.'::onRequest'] as $later) {
            self::assertRunsBefore(RequestIdListener::class.'::onRequest', $later, KernelEvents::REQUEST);
        }
    }

    /**
     * ⚠️ ClientVersionListener 早于 RouterListener。
     *
     * 反过来的话，`/v1/typo` 缺 X-Client 会先 404 —— 客户端看到「端点不存在」
     * 而不是「你漏了 header」，排查方向直接被带偏。
     */
    public function testClientVersionRunsBeforeRouting(): void
    {
        self::assertRunsBefore(
            ClientVersionListener::class.'::onRequest',
            'Symfony\Component\HttpKernel\EventListener\RouterListener::onKernelRequest',
            KernelEvents::REQUEST,
        );
    }

    /**
     * ⚠️ IdempotencyMiddleware 晚于路由：`POST /v1/typo` 应该直接 404，
     * 不该碰 Redis、不该烧掉一个幂等键。
     */
    public function testIdempotencyRunsAfterRouting(): void
    {
        self::assertRunsBefore(
            'Symfony\Component\HttpKernel\EventListener\RouterListener::onKernelRequest',
            IdempotencyMiddleware::class.'::onRequest',
            KernelEvents::REQUEST,
        );
    }

    /**
     * 也晚于 ClientVersionListener —— 一个即将因缺 X-Client 而 400 的请求
     * 不该先抢占一个幂等键。
     */
    public function testIdempotencyRunsAfterClientVersion(): void
    {
        self::assertRunsBefore(
            ClientVersionListener::class.'::onRequest',
            IdempotencyMiddleware::class.'::onRequest',
            KernelEvents::REQUEST,
        );
    }

    // ========================================================================
    // kernel.response
    // ========================================================================

    /**
     * 幂等先落库/释放，RequestId 再盖 header。
     */
    public function testIdempotencyFinalisesBeforeTheRequestIdHeaderIsStamped(): void
    {
        self::assertRunsBefore(
            IdempotencyMiddleware::class.'::onResponse',
            RequestIdListener::class.'::onResponse',
            KernelEvents::RESPONSE,
        );
    }

    // ========================================================================
    // kernel.exception —— 最关键的一组
    // ========================================================================

    /**
     * ⚠️⚠️ 必须早于 `ErrorListener::logKernelException`（优先级 0）。
     *
     * `ErrorListener::resolveLogLevel()` 对任何非 HttpExceptionInterface 的 throwable
     * 一律返回 CRITICAL，而 DomainException 恰好不是。跑在它后面的话，
     * 每一个正常的 409 revision_conflict 都会被记成 CRITICAL，
     * §14.4 的「API 5xx 率高」告警会被日常流量淹掉。
     */
    public function testProblemListenerRunsBeforeSymfonyLogsTheException(): void
    {
        self::assertRunsBefore(
            ApiProblemExceptionListener::class.'::onException',
            'Symfony\Component\HttpKernel\EventListener\ErrorListener::logKernelException',
            KernelEvents::EXCEPTION,
        );
    }

    /**
     * ⚠️⚠️ 必须早于 `ErrorListener::onKernelException`（优先级 -128）。
     *
     * 那个方法结尾是**无条件**的 `$event->setResponse(...)` —— 它从不检查响应
     * 是否已被设置。跑在它后面（或忘了 stopPropagation）的话，
     * 我们精心构造的 Problem Details 会被静默换成框架的错误页，且不会有任何报错。
     */
    public function testProblemListenerRunsBeforeSymfonyRendersTheErrorPage(): void
    {
        self::assertRunsBefore(
            ApiProblemExceptionListener::class.'::onException',
            'Symfony\Component\HttpKernel\EventListener\ErrorListener::onKernelException',
            KernelEvents::EXCEPTION,
        );
    }

    /**
     * 它应该是第一个 —— 我们会 stopPropagation，后面的都不该跑。
     */
    public function testProblemListenerIsFirstOnException(): void
    {
        $order = self::listenersFor(KernelEvents::EXCEPTION);

        self::assertSame(
            ApiProblemExceptionListener::class.'::onException',
            $order[0] ?? null,
            "ApiProblemExceptionListener 必须是第一个 kernel.exception 监听器。\n实际顺序：\n".implode("\n", $order),
        );
    }

    // 刻意**不**写「优先级常量等于某个字面量」的测试 —— 那是恒真的，
    // 挡不住任何东西。上面那些顺序断言才是真正的契约：它们从容器里读出
    // **实际**的执行序，改了任何一个数字都会红。
}
