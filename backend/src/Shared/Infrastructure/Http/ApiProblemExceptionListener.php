<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

use App\Shared\Domain\Error\DomainException;
use App\Shared\Domain\Error\ErrorCode;
use App\Shared\Domain\Error\RateLimitExceeded;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * 把任何异常翻译成 RFC 9457 Problem Details（§6.1、T-004 交付物）。
 *
 * ============================================================================
 * ⚠️ 优先级 16 + stopPropagation()，两个都是必需的
 * ============================================================================
 * Symfony 的 `ErrorListener`（vendor/symfony/http-kernel/EventListener/ErrorListener.php）
 * 在 `kernel.exception` 上注册了两个回调：
 *
 *     ['logKernelException', 0]
 *     ['onKernelException', -128]
 *
 * 而 `onKernelException` 的结尾是**无条件**的 `$event->setResponse($response)` ——
 * 它从不检查响应是否已经被设置过。所以任何优先级高于 -128 却不 stopPropagation 的
 * 监听器，产出的响应都会被静默覆盖掉，而且不会有任何报错。
 *
 * 跑在 16（而不是 1）的第二个理由是 `logKernelException` 在 0：
 * `ErrorListener::resolveLogLevel()` 对任何非 `HttpExceptionInterface` 的 throwable
 * 一律返回 **CRITICAL**。`DomainException` 恰好不是 `HttpExceptionInterface`，
 * 于是每一个正常的 `409 revision_conflict`、每一次 viewer 撞到 `403 insufficient_role`
 * 都会被记成 CRITICAL，直接把 §14.4 的「API 5xx 率高 > 1% 持续 5min」告警淹掉。
 * 抢在它前面 + stopPropagation，日志级别就由 {@see ErrorCode::logLevel()} 说了算。
 *
 * ============================================================================
 * ⚠️ 绝不要给 DomainException 配 framework.exceptions.<class>.status_code
 * ============================================================================
 * 那个配置会让 `logKernelException` 把 throwable 重新包成一个裸 `HttpException`
 * （见 ErrorListener 里的 `HttpException::fromStatusCode(...)`），
 * `errors` 与 `current` 全部丢失。在优先级 16 下这条目前是 moot 的，
 * 但陷阱离得只有一行配置，所以写在这里。
 *
 * ============================================================================
 * 三个信息不外泄的点
 * ============================================================================
 * 1. `instance` 只用 `getPathInfo()`，**绝不**用 `getRequestUri()`。
 * 2. 未映射的异常一律 500 + 固定文案，原始消息只进日志。
 * 3. `debug` 成员只在 `kernel.debug` 下出现。
 */
#[AsEventListener(event: KernelEvents::EXCEPTION, method: 'onException', priority: self::PRIORITY)]
final readonly class ApiProblemExceptionListener
{
    /**
     * 高于 `ErrorListener::logKernelException`(0) 与 `onKernelException`(-128)。
     *
     * 这个数字由 tests/Integration/Shared/Http/ListenerOrderTest 钉死 ——
     * 属性把优先级散在各个文件里，那个测试是唯一能防止后来者随手改序的东西。
     */
    public const PRIORITY = 16;

    public function __construct(
        private ApiProblemFactory $factory,
        private ?LoggerInterface $logger = null,
    ) {
    }

    public function onException(ExceptionEvent $event): void
    {
        $request = $event->getRequest();
        $throwable = $event->getThrowable();
        // RequestIdListener 在 kernel.request 优先级 512 处写入，也就是最先 ——
        // 所以除非异常发生在它之前（实际做不到），这里总能取到值。取不到时为 null，
        // 响应里 request_id 就是 null 而不是缺失，客户端的解析形状不变。
        $requestId = RequestIdListener::readFrom($request);

        try {
            $response = $this->render($throwable, $request, $requestId);
        } catch (\Throwable $renderFailure) {
            // 渲染自己炸了。绝不能让它冒泡成一条没有 request_id 的裸 500。
            $this->logger?->error('Failed to render a problem response', [
                'request_id' => $requestId,
                'exception' => $renderFailure,
            ]);

            $response = ApiProblemFactory::fallback($requestId);
        }

        $event->setResponse($response);

        // ⚠️ 没有这一行，上面所有工作都会被 ErrorListener 在 -128 处覆盖掉。
        $event->stopPropagation();
    }

    private function render(\Throwable $throwable, Request $request, ?string $requestId): JsonResponse
    {
        // §3.8-C4：`GET /v1/users/lookup?username=anna_b` 若用 getRequestUri()，
        // username 会被写进每个错误体、每条日志与每个 Sentry 报告 —— 那是
        // username 枚举的泄露面。getPathInfo() 不含 query string。
        $instance = $request->getPathInfo();

        if ($throwable instanceof DomainException) {
            $code = $throwable->errorCode();

            $this->log($code, $throwable, $requestId, $instance);

            return $this->factory->toResponse(
                $code,
                $throwable->detail(),
                $instance,
                $requestId,
                $throwable->fieldErrors(),
                $throwable->current(),
                $throwable,
                self::headersFor($code, $throwable),
            );
        }

        if ($throwable instanceof HttpExceptionInterface) {
            $code = self::mapHttpStatus($throwable->getStatusCode());

            $this->log($code, $throwable, $requestId, $instance);

            return $this->factory->toResponse(
                $code,
                // 框架抛的 404/405 消息（"No route found for ..."）是安全的开发者文案，
                // 但 500 一类可能带内部细节 —— 所以只在 4xx 时透传。
                $code->isClientError() ? $throwable->getMessage() : null,
                $instance,
                $requestId,
                [],
                [],
                $throwable,
                self::stringHeaders($throwable->getHeaders()),
            );
        }

        // 完全未映射的异常：固定文案 + 完整日志。
        $this->log(ErrorCode::InternalError, $throwable, $requestId, $instance);

        return $this->factory->toResponse(
            ErrorCode::InternalError,
            'An unexpected error occurred.',
            $instance,
            $requestId,
            [],
            [],
            $throwable,
        );
    }

    /**
     * 按 code 的语义选日志级别，而不是让 Symfony 一律记 CRITICAL。
     */
    private function log(ErrorCode $code, \Throwable $throwable, ?string $requestId, string $instance): void
    {
        $this->logger?->log($code->logLevel(), 'Request failed with {code}', [
            'code' => $code->value,
            'status' => $code->httpStatus(),
            'request_id' => $requestId,
            'instance' => $instance,
            'exception' => $throwable,
        ]);
    }

    /**
     * HTTP 状态码 → §6.1 错误码。
     *
     * 只映射框架真正会抛的那几个（404 来自 RouterListener，405 来自方法不匹配，
     * 415/413 来自请求体处理）。其余一律归到 internal_error —— 与其猜一个语义，
     * 不如老实说「服务端没预料到」。
     */
    private static function mapHttpStatus(int $status): ErrorCode
    {
        return match ($status) {
            400 => ErrorCode::MalformedRequest,
            401 => ErrorCode::TokenInvalid,
            403 => ErrorCode::InsufficientRole,
            404 => ErrorCode::NotFound,
            405 => ErrorCode::MethodNotAllowed,
            413 => ErrorCode::PayloadTooLarge,
            415 => ErrorCode::UnsupportedMediaType,
            429 => ErrorCode::RateLimited,
            503 => ErrorCode::ServiceUnavailable,
            default => ErrorCode::InternalError,
        };
    }

    /**
     * §6.1：429 与 503 的响应**必须**带 `Retry-After`；
     * 409 idempotency_in_progress 也带（客户端马上重试就能拿到回放）。
     *
     * §7.5 对限流额外要求 `X-RateLimit-Remaining`。
     *
     * ============================================================================
     * 两层：精确值优先，静态默认兜底
     * ============================================================================
     * T-006 的 {@see RateLimitExceeded} 带着 Lua 脚本算出的**精确**等待秒数与剩余次数
     * （多维度时已经合并过：`Retry-After` 取更长的、`remaining` 取更小的）。
     *
     * 但 `rate_limited` 不是只有限流器会抛 —— 上游代理返回的 429（经
     * `mapHttpStatus()` 落到同一个 code）就没有这两个数。所以 match 的
     * `ErrorCode::RateLimited` 分支必须留着：**这两个 header 是 §7.5 的 MUST，
     * 缺失比给一个保守值更糟**（客户端会直接放弃退避）。
     *
     * @return array<string, string>
     */
    private static function headersFor(ErrorCode $code, \Throwable $throwable): array
    {
        if ($throwable instanceof RateLimitExceeded) {
            return [
                'Retry-After' => (string) $throwable->retryAfterSeconds(),
                'X-RateLimit-Remaining' => (string) $throwable->remaining(),
            ];
        }

        return match ($code) {
            ErrorCode::IdempotencyInProgress => ['Retry-After' => '1'],
            ErrorCode::RateLimited => ['Retry-After' => '5', 'X-RateLimit-Remaining' => '0'],
            ErrorCode::ServiceUnavailable => ['Retry-After' => '5'],
            default => [],
        };
    }

    /**
     * @param array<string, mixed> $headers
     *
     * @return array<string, string>
     */
    private static function stringHeaders(array $headers): array
    {
        $result = [];

        foreach ($headers as $name => $value) {
            if (\is_scalar($value)) {
                $result[$name] = (string) $value;
            }
        }

        return $result;
    }
}
