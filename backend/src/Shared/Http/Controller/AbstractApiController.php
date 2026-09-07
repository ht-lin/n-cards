<?php

declare(strict_types=1);

namespace App\Shared\Http\Controller;

use App\Shared\Domain\Client\ClientVersion;
use App\Shared\Domain\Error\DomainException;
use App\Shared\Domain\Error\ErrorCode;
use App\Shared\Domain\Http\AuthContext;
use App\Shared\Domain\Http\RequestAttributes;
use App\Shared\Http\Pagination\Page;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `/v1/*` 控制器的基类（§12.2 的 `Shared/Http/AbstractApiController.php`）。
 *
 * ============================================================================
 * 为什么不继承 FrameworkBundle 的 AbstractController
 * ============================================================================
 * deptrac 其实允许（`Framework.Http` 收集器包含 `Bundle\FrameworkBundle\Controller\`），
 * 所以这是主动选择，不是被规则逼的。理由：`AbstractController` 会拖进一个
 * `ServiceSubscriberInterface` 容器、一堆 Twig 味的 helper、`denyAccessUnlessGranted`
 * （security-bundle 没装）以及 session/flash 语义 —— 在一个 token 认证的 JSON API 里
 * 全是噪声。T-003 的 `HealthController` 已经立了「裸 final class + 私有 json()」的先例。
 *
 * ============================================================================
 * 刻意**不**提供字段校验
 * ============================================================================
 * `symfony/validator` 没装。模块暂时抛
 * `DomainException::validationFailed(...FieldError)`。装不装 validator 是 T-1xx 的决定 ——
 * 在这里塞一个半成品，会留下一个七个模块都用上了、之后很难改的形状。
 *
 * ============================================================================
 * 两种 content type，互不重叠
 * ============================================================================
 *   成功 → `application/json; charset=utf-8`（本类）
 *   错误 → `application/problem+json`（ApiProblemFactory）
 */
abstract class AbstractApiController
{
    /** 请求体的 JSON 嵌套深度上限。业务载荷都是扁平的。 */
    private const MAX_BODY_DEPTH = 32;

    /**
     * 成功响应。
     *
     * @param array<string, string> $headers
     * @param bool                  $noStore 默认 true —— 每个 `/v1` 响应都是用户特定的，
     *                                       §7.4 不希望任何中间层缓存。T-112 的
     *                                       `/v1/config` 是唯一预期的例外
     */
    protected function json(mixed $data, int $status = Response::HTTP_OK, array $headers = [], bool $noStore = true): JsonResponse
    {
        $response = new JsonResponse($data, $status, $headers);

        $response->setEncodingOptions(
            // UNESCAPED_UNICODE：德语变音符号不能变成 ä —— 卡片标题是用户输入，
            // 转义后既难读也让 Android 侧的字节长度校验对不上。
            // PRESERVE_ZERO_FRACTION：避免 1.0 被编成 1 而让客户端的类型解析崩掉。
            \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION,
        );

        // JsonResponse 默认发裸 `application/json`，而 §6.1 要求带 charset。
        $response->headers->set('Content-Type', 'application/json; charset=utf-8');

        if ($noStore) {
            $response->headers->set('Cache-Control', 'no-store');
        }

        return $response;
    }

    /**
     * 201 + `Location`。
     */
    protected function created(mixed $data, string $location): JsonResponse
    {
        return $this->json($data, Response::HTTP_CREATED, ['Location' => $location]);
    }

    protected function noContent(): Response
    {
        $response = new Response('', Response::HTTP_NO_CONTENT);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    /**
     * @param Page<mixed> $page
     */
    protected function page(Page $page): JsonResponse
    {
        return $this->json($page);
    }

    /**
     * 解析 JSON 请求体，畸形输入的策略收敛在这一处。
     *
     * ⚠️ 报错文案**绝不**回显 `json_last_error_msg()` 或字节偏移量：
     * 那会泄露解析器内部状态，而对一个被截断的加密载荷来说，偏移量附近的内容
     * 本身就是载荷片段。
     *
     * @return array<string, mixed>
     *
     * @throws DomainException `unsupported_media_type` / `malformed_request`
     */
    protected function decodeBody(Request $request): array
    {
        $contentType = strtolower(explode(';', (string) $request->headers->get('Content-Type'))[0]);

        if ('application/json' !== trim($contentType)) {
            throw new DomainException(ErrorCode::UnsupportedMediaType, 'The request body must be sent as application/json.');
        }

        $raw = $request->getContent();

        if ('' === trim($raw)) {
            throw new DomainException(ErrorCode::MalformedRequest, 'Request body must not be empty.');
        }

        try {
            $decoded = json_decode($raw, true, self::MAX_BODY_DEPTH, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new DomainException(ErrorCode::MalformedRequest, 'Request body is not valid JSON.');
        }

        // 顶层必须是 JSON 对象。列表与标量都不是合法的请求体，而
        // `json_decode('[1,2]', true)` 会给出一个 PHP 数组，不拦的话会一路走到
        // 业务代码里才以一个费解的类型错误炸掉。
        if (!\is_array($decoded) || array_is_list($decoded)) {
            throw new DomainException(ErrorCode::MalformedRequest, 'Request body must be a JSON object.');
        }

        /* @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * 本次请求的客户端版本（由 ClientVersionListener 解析）。
     *
     * @throws \LogicException 属性不存在时。那意味着这个控制器被路由到了 `/v1` 之外 ——
     *                         是**接线错误**，不是客户端错误，所以不能是 DomainException
     */
    protected function clientVersion(Request $request): ClientVersion
    {
        $client = $request->attributes->get(RequestAttributes::CLIENT_VERSION);

        if (!$client instanceof ClientVersion) {
            throw new \LogicException('No client version on the request. A controller extending AbstractApiController must be routed under /v1/ so that ClientVersionListener runs.');
        }

        return $client;
    }

    /**
     * 本次请求认证出的身份（由 AuthenticationListener 从 access token 里取出，T-105）。
     *
     * ⚠️ 拿到它**不**代表这个会话此刻仍然有效 —— §7.1 不做 access token 黑名单，
     * 撤销后最长 15 分钟内它仍然验得过。需要那个保证的端点自己查表，
     * 详见 {@see AuthContext} 的类注释。
     *
     * @throws \LogicException 属性不存在时。那意味着这个控制器被路由到了一个
     *                         免鉴权的路由上（`AuthenticationListener::PUBLIC_ROUTES`），
     *                         或根本不在 `/v1` 下 —— 两者都是**接线错误**，
     *                         不是客户端错误，所以不能是 DomainException。
     *                         口径与上面的 `clientVersion()` 完全一致
     */
    protected function authContext(Request $request): AuthContext
    {
        $context = $request->attributes->get(RequestAttributes::AUTH_CONTEXT);

        if (!$context instanceof AuthContext) {
            throw new \LogicException('No auth context on the request. A controller calling authContext() must be routed under /v1/ and must not be listed in AuthenticationListener::PUBLIC_ROUTES.');
        }

        return $context;
    }

    /**
     * 本次请求的追踪 id（由 RequestIdListener 生成或透传）。
     */
    protected function requestId(Request $request): string
    {
        $id = $request->attributes->get(RequestAttributes::REQUEST_ID);

        if (!\is_string($id)) {
            throw new \LogicException('No request id on the request; RequestIdListener did not run.');
        }

        return $id;
    }
}
