<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Monolog;

use App\Shared\Domain\Http\RequestAttributes;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * 把 `request_id` / `route` / `method` 注入**每一条**日志记录。
 *
 * T-004 交付物里 `RequestIdListener` 那条写的是「`X-Request-Id` 透传/生成，
 * **写入所有日志**」—— 后半句就落在这里。生成 id 只是第一步，如果它不进日志，
 * 客户端报障时拿着一个 request_id 过来，运维在 Loki 里什么也搜不到。
 *
 * §14.4 规定日志字段固定为 `ts, level, msg, request_id, user_id, route, duration_ms`。
 * 本 processor 负责其中的 `request_id` 与 `route`：
 *   - `user_id`     → 待补。T-105 已经把认证接上了（`AuthContext` 在 request
 *                     attributes 里），所以这一条现在只差一次编辑；
 *                     ⚠️ 补的时候注意 §8.2：日志里放的应该是 user id 这个**假名**，
 *                     不是邮箱
 *   - `duration_ms` → T-405 的可观测性任务补
 *
 * ⚠️ **不覆盖调用方显式提供的同名 key**。业务代码写
 * `$logger->info('...', ['route' => 'x'])` 时它自己的值优先 —— 这里是兜底，不是权威。
 */
final readonly class RequestContextProcessor implements ProcessorInterface
{
    public function __construct(private RequestStack $requestStack)
    {
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        // 子请求（ESI / forward）也要拿主请求的 id，否则一次请求会散成两条互不关联的日志。
        $request = $this->requestStack->getMainRequest();

        if (null === $request) {
            // CLI 场景（bin/console、messenger worker）没有请求 —— 不加字段，
            // 也不要塞一个假的 request_id 进去。
            return $record;
        }

        $context = $record->context;

        $requestId = $request->attributes->get(RequestAttributes::REQUEST_ID);

        if (\is_string($requestId) && !\array_key_exists('request_id', $context)) {
            $context['request_id'] = $requestId;
        }

        $route = $request->attributes->get('_route');

        if (\is_string($route) && !\array_key_exists('route', $context)) {
            $context['route'] = $route;
        }

        if (!\array_key_exists('method', $context)) {
            $context['method'] = $request->getMethod();
        }

        return $record->with(context: $context);
    }
}
