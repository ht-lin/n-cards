<?php

declare(strict_types=1);

namespace App\Shared\Domain\Http;

use App\Shared\Domain\Identity\Uuid;

/**
 * 「本次请求是谁、哪一次登录、哪台机器」—— 由
 * {@see \App\Shared\Infrastructure\Http\AuthenticationListener} 从验过签的 access token
 * 里取出，写进 `Request::$attributes[{@see RequestAttributes::AUTH_CONTEXT}]`（T-105）。
 *
 * ============================================================================
 * 为什么在 Shared\Domain 而不是 Shared\Infrastructure\Http
 * ============================================================================
 * 与 {@see RequestAttributes} 和 {@see ApiSurface} 逐字相同的理由：deptrac 里
 * `Shared.Infrastructure`（写入方）与 `Shared.Http`（读取方，`AbstractApiController`）
 * **互相看不见**，而各模块的 `*.Http` 也要能命名这个类型。`Shared.Domain` 是唯一的公共地面。
 *
 * 它只由三个 {@see Uuid} 组成，不含任何框架概念，放 Domain 也说得通。
 *
 * ============================================================================
 * ⚠️ 这三个 id 全部来自 token，**没有任何一个被查过库**
 * ============================================================================
 * §7.1：服务端不做 access token 黑名单。所以持有一个 AuthContext 意味着
 * 「这枚 token 是我们签的、还没过期」，**不**意味着：
 *
 *   - 这个 session 还没被撤销（可能刚被远程登出，最长 15 分钟的窗口）
 *   - 这个 device 还没被撤销（同上）
 *   - 这个 user 还存在（可能在删号流程里）
 *
 * 需要那些保证的端点必须自己去查 `sessions` / `devices` / `users`。
 * 设备管理页的三个端点就是这么做的：它们查 `devices` 行，而不是信 `deviceId`。
 *
 * 反过来说，`userId` 用作**归属判断的主语**是安全的（「这台设备是不是他的」），
 * 因为伪造它需要伪造签名。
 */
final readonly class AuthContext
{
    /**
     * @param Uuid $userId    JWT 的 `sub`
     * @param Uuid $sessionId JWT 的 `sid`，等于 `sessions.id`
     * @param Uuid $deviceId  JWT 的 `did`，等于 `devices.id`
     */
    public function __construct(
        public Uuid $userId,
        public Uuid $sessionId,
        public Uuid $deviceId,
    ) {
    }
}
