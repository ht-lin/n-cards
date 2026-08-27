<?php

declare(strict_types=1);

namespace App\Tests\Fixture\Http;

use App\Shared\Domain\Client\ClientVersion;
use App\Shared\Domain\Error\DomainException;
use App\Shared\Domain\Error\ErrorCode;
use App\Shared\Http\Controller\AbstractApiController;
use App\Shared\Http\Pagination\CursorPaginator;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * **仅 test 环境**的 `/v1` 探针端点。
 *
 * ============================================================================
 * 为什么需要它
 * ============================================================================
 * T-004 交付的是横切层，本身**不含任何 `/v1` 路由**（第一个真实端点 `/v1/config`
 * 属于 T-112）。但 T-004 的验收标准要求「契约测试能对 Problem Details schema
 * 校验通过」—— 没有一个 `/v1` 端点，就没法验证监听器链在真实 HTTP 上的行为：
 * X-Client 强制、幂等、Problem Details 渲染、游标分页，一个都测不到。
 *
 * ============================================================================
 * 它不可能泄漏到 dev/prod
 * ============================================================================
 * 注册在 config/services.yaml 的 `when@test:` 段里，dev/prod 容器里根本不存在
 * 这个服务，于是 `#[Route]` 的 `routing.controller` 标签也不存在，路由不会被注册。
 * tests/Api/RouteInventoryTest 会顺带断言这一点。
 *
 * 路由挂在 `/v1/_probe/` 下：下划线前缀让它在 `debug:router` 里一眼可辨，
 * 而 `/v1/` 前缀是必需的 —— 横切监听器只对 `/v1/*` 生效（ApiSurface）。
 */
final class ProbeApiController extends AbstractApiController
{
    public function __construct(private readonly CursorPaginator $paginator)
    {
    }

    /**
     * 回显被监听器解析出来的东西。
     */
    #[Route('/v1/_probe/echo', name: 'probe_echo', methods: ['GET'])]
    public function echo(Request $request, ClientVersion $client): JsonResponse
    {
        // $client 由 ClientVersionValueResolver 注入 —— 顺带证明那个解析器是通的。
        return $this->json([
            'client' => (string) $client,
            'request_id' => $this->requestId($request),
            'client_ip' => $request->getClientIp(),
            'is_secure' => $request->isSecure(),
        ]);
    }

    /**
     * 按 code 抛对应的 DomainException —— 驱动全错误码的契约测试。
     */
    #[Route('/v1/_probe/fail/{code}', name: 'probe_fail', methods: ['GET'])]
    public function fail(string $code): never
    {
        $errorCode = ErrorCode::from($code);

        throw match ($errorCode) {
            // 带 errors[] 的形态
            ErrorCode::ValidationFailed => DomainException::validationFailed(
                new \App\Shared\Domain\Error\FieldError(
                    'title',
                    \App\Shared\Domain\Error\FieldErrorCode::TooLong,
                    'Title must be at most 100 characters.',
                ),
            ),
            // 带 current{} 的形态
            ErrorCode::RevisionConflict => DomainException::revisionConflict(['revision' => 42]),
            default => new DomainException($errorCode),
        };
    }

    /**
     * 抛一个**非** DomainException，验证「未映射异常 → 500 且不泄露原始消息」。
     */
    #[Route('/v1/_probe/boom', name: 'probe_boom', methods: ['GET'])]
    public function boom(): never
    {
        throw new \RuntimeException('SQLSTATE[08006] connection to 10.0.0.5:5432 failed');
    }

    /**
     * 游标分页。数据是固定的整数序列，方便断言页边界。
     */
    #[Route('/v1/_probe/list', name: 'probe_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $pageRequest = $this->paginator->pageRequest($request);

        $after = 0;

        if (null !== $pageRequest->cursor) {
            $payload = $pageRequest->cursor->payload();
            $after = \is_int($payload['after'] ?? null) ? $payload['after'] : 0;
        }

        // 模拟仓储：从 $after 之后取 fetchLimit() 行，总共 500 行。
        $rows = [];

        for ($i = $after + 1; $i <= 500 && \count($rows) < $pageRequest->fetchLimit(); ++$i) {
            $rows[] = $i;
        }

        return $this->page(
            $this->paginator->paginate($rows, $pageRequest, static fn (int $row): array => ['after' => $row]),
        );
    }

    /**
     * 回显请求体 —— 驱动 decodeBody() 与幂等的测试。
     */
    #[Route('/v1/_probe/echo-body', name: 'probe_echo_body', methods: ['POST'])]
    public function echoBody(Request $request): JsonResponse
    {
        return $this->json(['received' => $this->decodeBody($request)]);
    }

    /**
     * 按查询参数返回指定状态码，验证幂等只持久化 2xx。
     */
    #[Route('/v1/_probe/status', name: 'probe_status', methods: ['POST'])]
    public function status(Request $request): Response
    {
        $status = (int) $request->query->get('status', '200');

        if (Response::HTTP_NO_CONTENT === $status) {
            return $this->noContent();
        }

        return $this->json(['status' => $status, 'nonce' => uniqid('', true)], $status);
    }
}
