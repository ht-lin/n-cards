<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

use App\Shared\Domain\Http\RequestAttributes;
use App\Shared\Domain\Identity\UuidGeneratorInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * `X-Request-Id` 的透传 / 生成 / 回显（§6.1「追踪」一行）。
 *
 * > 请求头 `X-Request-Id`（客户端可选提供，否则服务端生成），响应回显；写入所有日志。
 *
 * 「写入所有日志」的另一半在 {@see \App\Shared\Infrastructure\Monolog\RequestContextProcessor}。
 *
 * `infra/caddy/Caddyfile` 刻意**不**设这个 header，把生成权交给应用侧 ——
 * 那条注释现在成立了。
 *
 * ============================================================================
 * ⚠️ 优先级 512：必须是**最先**跑的请求监听器
 * ============================================================================
 * 后面任何一个监听器抛出的异常，都要能在 problem body 与日志里带上 request_id。
 * ClientVersionListener（40）缺 header 就抛 400 —— 如果 request_id 还没生成，
 * 那条 400 就是一次无法追查的失败。
 *
 * ⚠️ 与其他横切监听器不同，本监听器**不**按 `/v1/` 过滤：
 * `/health/*` 的日志同样需要 request_id（§14.3 的部署健康检查失败时要能追）。
 * 生成一个 UUID 的成本可以忽略。
 *
 * ============================================================================
 * ⚠️ 客户端提供的值必须先校验再使用
 * ============================================================================
 * 这个 header 会进两个危险的地方：结构化日志（**日志注入** —— 一个含 `\n` 的值
 * 能伪造出一整条日志记录）和响应头（**header 拆分**）。
 * 所以只接受 1–128 个 `[A-Za-z0-9_.:-]`，不合格就当没提供，直接生成一个。
 * 刻意不报 400：客户端发了个畸形追踪 id 不该让业务请求失败。
 */
#[AsEventListener(event: KernelEvents::REQUEST, method: 'onRequest', priority: self::REQUEST_PRIORITY)]
#[AsEventListener(event: KernelEvents::RESPONSE, method: 'onResponse', priority: self::RESPONSE_PRIORITY)]
final readonly class RequestIdListener
{
    public const HEADER = 'X-Request-Id';

    /**
     * 高于 Symfony 自己的全部请求监听器（最高的是 ValidateRequestListener 的 256）。
     *
     * 由 tests/Integration/Shared/Http/ListenerOrderTest 钉死。
     */
    public const REQUEST_PRIORITY = 512;

    /**
     * 尽量晚 —— 任何替换整个 Response 的监听器都不会把这个 header 弄丢；
     * 但仍早于 AbstractSessionListener 的 -1000。
     */
    public const RESPONSE_PRIORITY = -512;

    /** 够放 UUID(36) / ULID(26) / W3C traceparent(55)，又不至于撑爆日志行。 */
    private const MAX_LENGTH = 128;

    /** 保守的字符集：字母数字加 `_ . : -`，不含空白与控制字符。 */
    private const PATTERN = '/^[A-Za-z0-9_.:\-]+$/';

    public function __construct(private UuidGeneratorInterface $uuidGenerator)
    {
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        $request->attributes->set(
            RequestAttributes::REQUEST_ID,
            self::sanitise($request->headers->get(self::HEADER)) ?? $this->uuidGenerator->generate()->toString(),
        );
    }

    public function onResponse(ResponseEvent $event): void
    {
        $requestId = self::readFrom($event->getRequest());

        if (null === $requestId) {
            return;
        }

        $event->getResponse()->headers->set(self::HEADER, $requestId);
    }

    /**
     * 读取本次请求的 request_id。
     *
     * 子请求（forward / ESI）从主请求取 —— 否则一次请求会散成两条互不关联的日志。
     * 这里用 `attributes` 而不是服务上的可变字段：请求属性天然是每请求隔离的，
     * FrankenPHP worker 模式下没有任何需要重置的状态，也不会跨请求泄露。
     */
    public static function readFrom(Request $request): ?string
    {
        $id = $request->attributes->get(RequestAttributes::REQUEST_ID);

        return \is_string($id) ? $id : null;
    }

    private static function sanitise(?string $candidate): ?string
    {
        if (null === $candidate || '' === $candidate) {
            return null;
        }

        if (\strlen($candidate) > self::MAX_LENGTH) {
            return null;
        }

        return 1 === preg_match(self::PATTERN, $candidate) ? $candidate : null;
    }
}
