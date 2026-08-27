<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

use App\Shared\Domain\Error\ErrorCode;
use App\Shared\Domain\Error\FieldError;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * RFC 9457 Problem Details 的渲染器（§6.1）。
 *
 * ============================================================================
 * 为什么从监听器里拆出来
 * ============================================================================
 * 验收标准是「单测覆盖**每个**错误码的响应形状」。如果渲染逻辑长在
 * `ApiProblemExceptionListener` 里，那条测试就得为 26 个 code 各启一次内核。
 * 拆开之后 `ApiProblemFactoryTest` 是纯单测，跑 26 个 code 只要几毫秒 ——
 * 而端到端形态另有 `tests/Api/ProblemDetailsContractTest` 用真实 HTTP 兜底。
 *
 * ============================================================================
 * 成员顺序是契约的一部分
 * ============================================================================
 * `type, title, status, code, detail, instance, request_id [, errors] [, current] [, debug]`
 * —— 与 §6.1 的例子逐字一致。JSON 对象的键序在语义上无关紧要，但人读日志、
 * 读 curl 输出、读 Sentry 时非常在意，而且固定顺序让契约测试能做精确断言。
 */
final readonly class ApiProblemFactory
{
    /** 出现在 `debug` 里的栈帧数上限。够定位，又不至于让错误体膨胀成几十 KB。 */
    private const DEBUG_FRAMES = 5;

    public function __construct(
        #[Autowire('%ncards.problem_type_base_uri%')]
        private string $typeBaseUri,
        #[Autowire('%kernel.debug%')]
        private bool $debug,
    ) {
    }

    /**
     * @param string|null          $detail      英文开发者文案；留空时回落到 code 的 title
     * @param string               $instance    **必须**是 `Request::getPathInfo()`，见下方警告
     * @param list<FieldError>     $errors      字段级错误；为空时该成员不出现
     * @param array<string, mixed> $current     服务端当前状态（§5.4.3）；为空时该成员不出现
     * @param \Throwable|null      $debugSource 仅在 kernel.debug 下用于生成 `debug` 成员
     *
     * @return array<string, mixed>
     */
    public function build(
        ErrorCode $code,
        ?string $detail,
        string $instance,
        ?string $requestId,
        array $errors = [],
        array $current = [],
        ?\Throwable $debugSource = null,
    ): array {
        $problem = [
            'type' => $code->typeUri($this->typeBaseUri),
            'title' => $code->title(),
            'status' => $code->httpStatus(),
            'code' => $code->value,
            // 回落到 title 而不是另立一张 detail 对照表 —— 多一张表就多一处要测、
            // 要维护、且会和 title 说着说着就不一致的东西。
            'detail' => null === $detail || '' === $detail ? $code->title().'.' : $detail,
            'instance' => $instance,
            'request_id' => $requestId,
        ];

        // 为空时**省略**而不是发 []/{}。T-007 的 schema 因此不能把它们标成 required，
        // T-010 的 Kotlin 模型必须给默认值 —— 这两条都记在 docs/api/README.md 里。
        if ([] !== $errors) {
            // $errors 已声明为 list —— DomainException 的构造已经做过 array_values，
            // 这里不必再来一次。
            $problem['errors'] = array_map(
                static fn (FieldError $e): array => $e->jsonSerialize(),
                $errors,
            );
        }

        if ([] !== $current) {
            $problem['current'] = $current;
        }

        if ($this->debug && null !== $debugSource) {
            $problem['debug'] = self::debugInfo($debugSource);
        }

        return $problem;
    }

    /**
     * 同上，外加一个可以直接返回的 `JsonResponse`。
     *
     * @param list<FieldError>      $errors
     * @param array<string, mixed>  $current
     * @param array<string, string> $headers 额外响应头（`Retry-After` 之类）
     */
    public function toResponse(
        ErrorCode $code,
        ?string $detail,
        string $instance,
        ?string $requestId,
        array $errors = [],
        array $current = [],
        ?\Throwable $debugSource = null,
        array $headers = [],
    ): JsonResponse {
        return self::render(
            $this->build($code, $detail, $instance, $requestId, $errors, $current, $debugSource),
            $code->httpStatus(),
            $headers,
        );
    }

    /**
     * 兜底响应：渲染过程本身炸了时用。
     *
     * 在异常监听器**内部**抛出的异常会产生一个没有 request_id 的裸 500 ——
     * 那是最糟的调试体验（生产上一条 500 日志，没有任何线索能关联到具体请求）。
     * 所以这里给一个不依赖任何外部状态的最小 problem body。
     */
    public static function fallback(?string $requestId): JsonResponse
    {
        return self::render([
            'type' => 'about:blank',
            'title' => ErrorCode::InternalError->title(),
            'status' => ErrorCode::InternalError->httpStatus(),
            'code' => ErrorCode::InternalError->value,
            'detail' => 'An unexpected error occurred.',
            'instance' => '',
            'request_id' => $requestId,
        ], ErrorCode::InternalError->httpStatus());
    }

    /**
     * @param array<string, mixed>  $problem
     * @param array<string, string> $headers
     */
    private static function render(array $problem, int $status, array $headers = []): JsonResponse
    {
        $response = new JsonResponse($problem, $status, $headers);

        $response->setEncodingOptions(
            // UNESCAPED_SLASHES：不然 type URI 会变成 https:\/\/api.ncards.de\/...，
            // 和 §6.1 的例子对不上，客户端做字符串比较也会踩坑。
            // UNESCAPED_UNICODE：detail 是 ASCII，但 errors[].message 将来可能带用户
            // 提供的字段名，转义成 \uXXXX 只会让日志难读。
            \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR,
        );

        // RFC 9457 的媒体类型**不带 charset 参数**（JSON 按定义就是 UTF-8）。
        // §6.1 那句全局的 `application/json; charset=utf-8` 只适用于成功响应 ——
        // 这处澄清一并写进了规格。
        $response->headers->set('Content-Type', 'application/problem+json');

        // 错误响应绝不可缓存。缓存住的 429/503 会在故障恢复后继续伤人。
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private static function debugInfo(\Throwable $e): array
    {
        return [
            'class' => $e::class,
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => array_map(
                static fn (array $frame): string => \sprintf(
                    '%s:%s',
                    $frame['file'] ?? '[internal]',
                    (string) ($frame['line'] ?? '?'),
                ),
                \array_slice($e->getTrace(), 0, self::DEBUG_FRAMES),
            ),
        ];
    }
}
