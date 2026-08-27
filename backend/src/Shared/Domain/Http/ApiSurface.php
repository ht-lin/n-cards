<?php

declare(strict_types=1);

namespace App\Shared\Domain\Http;

/**
 * 「这条路径属于产品 API 吗？」—— 所有 HTTP 横切规则的唯一开关。
 *
 * ============================================================================
 * 为什么是正向白名单，而不是「豁免 /health/*」
 * ============================================================================
 * T-004 的任务书写的是「`ClientVersionListener` 必须把 `/health/*` 排除在外」。
 * 照字面实现是一份**黑名单**，而黑名单的问题是：下一条非 `/v1` 路由必然被漏掉。
 * 任务书自己也承认这点 ——「同样的豁免逻辑将来还要覆盖 T-007 之后的任何非 `/v1` 端点」。
 *
 * 所以这里把它反过来：只有 `/v1/` 下的路径才是产品 API，才受 `X-Client` 必填、
 * 幂等键、Problem Details 等约束。`/health/live`、`/health/ready` 以及将来任何
 * 非 `/v1` 端点**自动**豁免，不需要任何人记得去登记。
 *
 * 真正的强制点是 `tests/Api/RouteInventoryTest`：它断言每条注册路由要么在 `/v1/` 下，
 * 要么在一份显式白名单里。新增一条非 `/v1` 路由而不主动登记 → CI 红。
 *
 * ============================================================================
 * 为什么在 Domain 而不是 Infrastructure\Http
 * ============================================================================
 * deptrac 里 `Shared.Infrastructure` 与 `Shared.Http` **互相看不见**（两边的允许列表里
 * 都没有对方）。而这条规则同时要被 `Shared\Infrastructure\Http` 的四个监听器和
 * `Shared\Http` 的控制器基类用到 —— `Shared.Domain` 是唯一的公共地面。
 *
 * 它是纯字符串判断，没有任何框架概念，放在 Domain 也说得通。
 */
final class ApiSurface
{
    /**
     * 产品 API 的路径前缀（§6.1：基址 `https://api.ncards.de/v1`）。
     *
     * 带尾斜杠是刻意的：裸 `/v1` 本身不是端点，而 `/v1foo` 不该被当成产品 API。
     */
    public const V1_PREFIX = '/v1/';

    private function __construct()
    {
    }

    /**
     * @param string $pathInfo `Request::getPathInfo()` —— 不含 query string，也不含
     *                         前端代理剥掉的 base path
     */
    public static function isProductApiPath(string $pathInfo): bool
    {
        return str_starts_with($pathInfo, self::V1_PREFIX);
    }
}
