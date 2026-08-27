<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

use App\Shared\Application\Idempotency\IdempotencyScopeResolverInterface;
use App\Shared\Application\Idempotency\IdempotencyStoreInterface;
use App\Shared\Application\Idempotency\IdempotencyStoreUnavailable;
use App\Shared\Domain\Error\DomainException;
use App\Shared\Domain\Error\ErrorCode;
use App\Shared\Domain\Error\FieldError;
use App\Shared\Domain\Error\FieldErrorCode;
use App\Shared\Domain\Http\ApiSurface;
use App\Shared\Domain\Http\RequestAttributes;
use App\Shared\Domain\Identity\Uuid;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * `Idempotency-Key` 的处理（§6.1：所有 POST 支持，Redis 存 24h）。
 *
 * 名字按 T-004 的交付物清单保留叫 Middleware，但 Symfony 没有 HTTP middleware 概念 ——
 * 实际是一对 kernel 事件监听器。
 *
 * ============================================================================
 * 适用条件：四个都满足才介入
 * ============================================================================
 * 主请求 + POST + `/v1/*` + 带 `Idempotency-Key`。
 *
 * ⚠️ §6.1 的原文是所有 POST **支持**这个 header，不是必填。所以**缺失即放行**。
 * 全局强制会立刻打死还没发这个 header 的客户端（比如 `POST /v1/auth/otp/request`）。
 * 需要强制的具体端点由 T-1xx 各自决定。
 *
 * ============================================================================
 * 状态机
 * ============================================================================
 * ```
 * claim(key, fingerprint = sha256(body), lockTtl = 60)
 *
 *   null                        → 抢到；标记 attribute；放行
 *   record.fingerprint ≠ 本次    → 422 idempotency_key_reused
 *   record 未完成                → 409 idempotency_in_progress + Retry-After: 1
 *   record 已完成                → 回放（+ Idempotency-Replayed: true）
 * ```
 *
 * 422 与 409 的选择不是随手挑的：§5.4.3 已经规定 Android 的 outbox
 * 「4xx（除 409/429）不重试」。于是客户端**一行代码都不用改**，就会重试 409
 * （在途，稍后能拿到回放）、快速失败 422（同键不同 body = 客户端 bug，重试永远不会成功）。
 *
 * ============================================================================
 * ⚠️ 只持久化 2xx
 * ============================================================================
 * 回放一个 `401 token_expired` 会**永久打死** §6.1 规定的「静默刷新后重试一次」——
 * 重试带着同一个键，于是永远拿回那个 401。同理回放 `422 validation_failed`
 * 会打死「改字段再提交」。
 * 而 `409 already_exists` 重跑一次是无害的：数据库会确定性地再给一个 409。
 * 真正需要去重的只有**成功的创建**。
 *
 * ============================================================================
 * ⚠️ 两段 TTL 不要混
 * ============================================================================
 * claim 的锁是 **60 秒**，不是 24 小时。进程半路死掉时 kernel.response 不会触发，
 * complete/release 都不会被调用 —— 用 24 小时会把那个键锁死一整天。
 * 24 小时只作用于**已完成**的记录，那才是 §6.1 说的那个 24h。
 */
#[AsEventListener(event: KernelEvents::REQUEST, method: 'onRequest', priority: self::REQUEST_PRIORITY)]
#[AsEventListener(event: KernelEvents::RESPONSE, method: 'onResponse', priority: self::RESPONSE_PRIORITY)]
final readonly class IdempotencyMiddleware
{
    public const HEADER = 'Idempotency-Key';

    /**
     * 回放响应上的标记头。
     *
     * ⚠️ 这是**我们自己发明的** —— IETF 的 Idempotency-Key draft 没有标准化任何
     * 这类 header。必须写进 T-007 的契约与 T-010 的拦截器，否则客户端无从区分
     * 「真的执行了」与「拿到了回放」。
     */
    public const REPLAYED_HEADER = 'Idempotency-Replayed';

    /**
     * **晚于** RouterListener(32)：`POST /v1/typo` 应该直接 404，
     * 不该碰 Redis、不该烧掉一个键。也晚于 ClientVersionListener(40)，
     * 所以一个即将因缺 X-Client 而 400 的请求不会先抢占键。
     */
    public const REQUEST_PRIORITY = 8;

    /** 早于 RequestIdListener 的 -512：先落库/释放，再盖 header。 */
    public const RESPONSE_PRIORITY = -256;

    /** 在途锁的 TTL。见类注释「两段 TTL」。 */
    public const LOCK_TTL_SECONDS = 60;

    /** 已完成记录的 TTL —— §6.1 的 24h。 */
    public const RECORD_TTL_SECONDS = 86400;

    /** 回放时还原的响应头白名单。 */
    private const REPLAYABLE_HEADERS = ['content-type', 'location', 'etag'];

    public function __construct(
        private IdempotencyStoreInterface $store,
        private IdempotencyScopeResolverInterface $scopeResolver,
        #[Autowire('%ncards.idempotency.max_body_bytes%')]
        private int $maxBodyBytes,
        private ?LoggerInterface $logger = null,
    ) {
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        if (!self::applies($request)) {
            return;
        }

        $key = $this->storageKey($request);
        $fingerprint = hash('sha256', $request->getContent());

        try {
            $record = $this->store->claim($key, $fingerprint, self::LOCK_TTL_SECONDS);
        } catch (IdempotencyStoreUnavailable $e) {
            // fail-open。理由见 IdempotencyStoreUnavailable 的类注释：
            // fail-closed 会把一次 Redis 抖动放大成 100% 写入不可用（含登录）。
            // 故障对运维可见 —— RedisHealthCheck 会把 /health/ready 翻成 503。
            $this->logger?->error('Idempotency store unavailable; processing without idempotency', [
                'request_id' => RequestIdListener::readFrom($request),
                'exception' => $e,
            ]);

            return;
        }

        if (null === $record) {
            // 抢到了。**只有这条路径**写 attribute —— 于是下面 onResponse 里
            // 「释放锁」永远不会误删别人的键。
            $request->attributes->set(RequestAttributes::IDEMPOTENCY_KEY, $key);

            return;
        }

        if ($record->fingerprint !== $fingerprint) {
            throw new DomainException(ErrorCode::IdempotencyKeyReused, 'This Idempotency-Key was already used with a different request body.');
        }

        if (!$record->completed) {
            throw new DomainException(ErrorCode::IdempotencyInProgress, 'A request with this Idempotency-Key is still being processed.');
        }

        $event->setResponse(self::replay($record->status ?? Response::HTTP_OK, $record->headers, $record->body ?? ''));
        $event->stopPropagation();
    }

    public function onResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        $key = $request->attributes->get(RequestAttributes::IDEMPOTENCY_KEY);

        if (!\is_string($key)) {
            // 我们没抢到这个键（或者压根没走幂等路径）—— 什么都别动。
            return;
        }

        $response = $event->getResponse();
        $status = $response->getStatusCode();
        $body = (string) $response->getContent();

        try {
            if ($status >= 200 && $status < 300 && \strlen($body) <= $this->maxBodyBytes) {
                $this->store->complete(
                    $key,
                    $status,
                    self::replayableHeaders($response),
                    $body,
                    self::RECORD_TTL_SECONDS,
                );

                return;
            }

            if (\strlen($body) > $this->maxBodyBytes) {
                $this->logger?->warning('Response too large to store for idempotent replay', [
                    'request_id' => RequestIdListener::readFrom($request),
                    'bytes' => \strlen($body),
                ]);
            }

            // 非 2xx（或体积超限）→ 释放锁，让客户端能用同一个键重试。
            $this->store->release($key);
        } catch (IdempotencyStoreUnavailable $e) {
            // 同样 fail-open：本次请求已经成功执行了，不能因为存不进 Redis 就报错。
            // 后果是这个键的 24h 去重窗口丢失，锁则会在 60 秒后自然过期。
            $this->logger?->error('Failed to finalise idempotency record', [
                'request_id' => RequestIdListener::readFrom($request),
                'exception' => $e,
            ]);
        }
    }

    private static function applies(Request $request): bool
    {
        return $request->isMethod(Request::METHOD_POST)
            && ApiSurface::isProductApiPath($request->getPathInfo())
            && $request->headers->has(self::HEADER);
    }

    /**
     * `ncards:idem:v1:{scope}:{sha256(METHOD pathInfo)[0..16]}:{key}`（前缀由存储加）。
     *
     * 键里含 method + path，所以「同一个键打不同端点」不可能撞车 ——
     * 指纹因此只需覆盖 body。
     */
    private function storageKey(Request $request): string
    {
        $rawKey = (string) $request->headers->get(self::HEADER);

        // §6.1 明写「`Idempotency-Key` header（UUID）」。非 UUID 直接拒绝：
        // 放任任意字符串会让键空间不可控，也会把非法字符带进 Redis 键。
        if (!Uuid::isValid($rawKey)) {
            throw DomainException::validationFailed(new FieldError(self::HEADER, FieldErrorCode::InvalidFormat, 'The Idempotency-Key header must be a UUID.'));
        }

        return \sprintf(
            '%s:%s:%s',
            $this->scope($request),
            substr(hash('sha256', $request->getMethod().' '.$request->getPathInfo()), 0, 16),
            strtolower($rawKey),
        );
    }

    /**
     * 作用域：认证用户优先，否则回落 IP。
     *
     * ⚠️ IP 回落依赖 `framework.trusted_proxies` 配置正确，否则
     * `getClientIp()` 对每个请求都返回 Caddy 容器的 IP，全世界的匿名客户端
     * 会挤进同一个作用域。见 config/packages/framework.yaml 的注释。
     */
    private function scope(Request $request): string
    {
        $scope = $this->scopeResolver->resolve();

        if (null !== $scope && '' !== $scope) {
            return $scope;
        }

        // IP 不直接进键：它是个人数据（§8），而 Redis 键会出现在慢查询日志与
        // `KEYS` 的输出里。哈希之后既能分组又不留原值。
        return 'ip:'.substr(hash('sha256', (string) $request->getClientIp()), 0, 16);
    }

    /**
     * @param array<string, string> $headers
     */
    private static function replay(int $status, array $headers, string $body): Response
    {
        $response = new Response($body, $status, $headers);

        $response->headers->set(self::REPLAYED_HEADER, 'true');
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    /**
     * @return array<string, string>
     */
    private static function replayableHeaders(Response $response): array
    {
        $stored = [];

        foreach (self::REPLAYABLE_HEADERS as $name) {
            $value = $response->headers->get($name);

            if (null !== $value) {
                $stored[$name] = $value;
            }
        }

        // 刻意**不**存 X-Request-Id：回放必须带**当次**请求的 id，
        // 否则两次不同的请求在日志里长得一模一样，关联直接断掉。
        // 也刻意不存 Set-Cookie —— 把一个人的会话 cookie 回放给另一个人是灾难。
        return $stored;
    }
}
