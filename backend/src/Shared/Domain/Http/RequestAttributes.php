<?php

declare(strict_types=1);

namespace App\Shared\Domain\Http;

/**
 * 横切监听器写进 `Request::$attributes` 的键名。
 *
 * 集中定义的理由很实际：写入方在 `Shared\Infrastructure\Http`，读取方在
 * `Shared\Http\Controller`（以及将来每个模块的控制器与日志 processor）。
 * 键名是裸字符串，散落各处必然会漂 —— 而漂了之后的症状是「读出来是 null」，
 * 没有任何编译期或运行期的报错。
 *
 * ============================================================================
 * 为什么在 Domain 而不是 Infrastructure\Http
 * ============================================================================
 * 与 {@see ApiSurface} 同一个原因：deptrac 里 `Shared.Infrastructure` 与
 * `Shared.Http` **互相看不见**（两边的允许列表里都没有对方），而这些常量
 * 两边都要用 —— `Shared.Domain` 是唯一的公共地面。
 *
 * 它们是裸字符串常量，不含任何框架概念，放 Domain 也说得通。
 *
 * 前缀 `_ncards_` 是为了不和 Symfony 自己的 `_route` / `_controller` / `_locale`
 * 撞车，也让 `debug:router` 的输出里一眼看出哪些是我们加的。
 */
final class RequestAttributes
{
    /** 由 {@see RequestIdListener} 写入，恒存在于 `/v1/*` 与非 `/v1` 的所有请求上。 */
    public const REQUEST_ID = '_ncards_request_id';

    /** 由 {@see ClientVersionListener} 写入，**只在 `/v1/*` 上存在**（§6.1 的豁免）。 */
    public const CLIENT_VERSION = '_ncards_client_version';

    /**
     * 由 {@see IdempotencyMiddleware} 写入，且**只在本次请求真正抢到了幂等锁时**存在。
     *
     * 这个「只在抢到时写」的约定是承重的：`onResponse` 靠它判断该不该释放锁，
     * 于是撞上别人在途请求而返回 409 的那条路径永远不会误删别人的键。
     */
    public const IDEMPOTENCY_KEY = '_ncards_idempotency_key';

    private function __construct()
    {
    }
}
