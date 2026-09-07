<?php

declare(strict_types=1);

namespace App\Module\Identity\Application\Session;

use App\Module\Identity\Domain\Repository\SessionRepositoryInterface;
use App\Module\Identity\Domain\ValueObject\SessionRevokedReason;
use App\Shared\Application\Metrics\MetricsInterface;
use App\Shared\Domain\Identity\Uuid;
use App\Shared\Domain\Time\ClockInterface;

/**
 * `POST /v1/auth/logout` 的编排（§7.1，T-105）。
 *
 * ============================================================================
 * 为什么它这么短，而且没有任何拒绝路径
 * ============================================================================
 * 走到这里的请求已经带着一枚验过签的 access token（`AuthenticationListener`），
 * 而 `sid` 是从那枚 token 里读出来的 —— 调用方**无法**登出别人的会话，
 * 那需要伪造签名。所以这里没有归属校验，也没有 403/404。
 *
 * ⚠️ 契约规定 204，且**幂等**：会话已撤销、已过期、甚至那一行根本不存在
 * （被 T-113 的清理任务删了），一律 204。
 * 「登出一个已经登出的会话」是客户端重试的正常形态，报错只会让它无法收敛。
 *
 * ============================================================================
 * ⚠️ access token 不会因此失效
 * ============================================================================
 * §7.1：服务端不做 access token 黑名单。登出之后那枚 access token 仍然验得过，
 * 最长 15 分钟。真正失效的是 refresh —— 会话一撤销，下一次刷新就是 401。
 *
 * 客户端的责任（T-150）是收到 204 之后**立刻**清掉本地的两个令牌。
 * 这条在 ADR-0015 的 Consequences 里被记为负面后果之一。
 */
final readonly class LogoutService
{
    public function __construct(
        private SessionRepositoryInterface $sessions,
        private MetricsInterface $metrics,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param Uuid $sessionId access token 的 `sid`，由 `AuthContext` 提供
     */
    public function logout(Uuid $sessionId): void
    {
        $session = $this->sessions->findById($sessionId);

        if (null === $session) {
            // 那一行不存在。只可能是清理任务删过，或（理论上）密钥泄露导致的伪造 ——
            // 后者伪造得了签名就早已不需要走这个端点了。204，不声张。
            return;
        }

        if ($session->isRevoked()) {
            // 幂等：**不**重置 revoked_at 与 revoked_reason。那是「什么时候、
            // 因为什么被撤销的」这个事实，而一次重复的 logout 不该把此前的
            // reuse_detected 覆盖成 logout —— Session::revoke() 本身也是这么写的
            // （首个 reason 胜出），这里提前返回只是为了不白算一次指标。
            return;
        }

        $session->revoke(SessionRevokedReason::Logout, $this->clock->now());

        $this->sessions->save($session);

        $this->metrics->counter('session_revoked_total', ['reason' => SessionRevokedReason::Logout->value]);
    }
}
