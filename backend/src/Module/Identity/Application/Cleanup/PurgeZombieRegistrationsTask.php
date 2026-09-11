<?php

declare(strict_types=1);

namespace App\Module\Identity\Application\Cleanup;

use App\Module\Identity\Domain\Repository\UserRepositoryInterface;
use App\Shared\Application\Cleanup\CleanupTaskInterface;

/**
 * 删掉「僵尸注册行」：`username IS NULL` 且超过 7 天的 `users`（§5.2 的 MUST，T-113）。
 *
 * ============================================================================
 * 它们是什么
 * ============================================================================
 * §5.2 让 `users.username` 可空，因为 `POST /auth/otp/verify` 成功即建行（首次验证
 * 即注册），而那一刻用户还没设 username。正常路径上 ADR-0018 的 onboarding 拦截器
 * 会把他挡在除 `GET /v1/me`、`POST /v1/me/username`、`POST /v1/auth/logout` 之外的
 * 所有端点外，直到他设好名字。
 *
 * 中途关掉 App 就再也没回来的人，留下的就是这么一行：一个邮箱哈希、一个密文、
 * 一个 locale，外加可能的 devices / sessions。没有卡、没有好友、没有共享 ——
 * 所以 §5.2 说「可安全物删」，不走 `UserStatus::PendingDeletion` 那套 30 天宽限
 * （那是给**真有账号**、且可能有共享卡影响到别人的用户准备的，见 §3.7）。
 *
 * ============================================================================
 * ⚠️ 删掉的那一刻，对方手里的 access token 还有最长 15 分钟的寿命
 * ============================================================================
 * 这不是 bug，是 ADR-0018 决定四写明的前提：`AuthContext` 持有一个 user id **不**
 * 意味着那个 user 还存在，所以 `OnboardingState` 有 `UserUnknown` 那一格，
 * 而 onboarding 状态**不得缓存**（缓存会让已删除用户的 token 在剩余寿命里畅通）。
 * 那一格与这个任务是同一个决定的两半。
 *
 * 表现给用户的是 401 而不是 403：他的 token 指向一个不存在的人。重新走一次 OTP
 * 就又是一条全新的注册行 —— 邮箱哈希相同，但那一行已经不在了，所以不冲突。
 */
final readonly class PurgeZombieRegistrationsTask implements CleanupTaskInterface
{
    /**
     * @param int<1, max> $retentionDays `ncards.cleanup.zombie_registration_days`
     */
    public function __construct(
        private UserRepositoryInterface $users,
        private int $retentionDays,
    ) {
    }

    public function name(): string
    {
        return 'identity.zombie_registrations';
    }

    public function run(\DateTimeImmutable $now): int
    {
        // `modify` 而不是 `sub(new DateInterval(...))`：同一个结果，但读起来
        // 与 §5.2 那句「created_at < now() - 7 days」是一一对应的。
        $cutoff = $now->modify(\sprintf('-%d days', $this->retentionDays));

        // 严格小于 —— 「第 7 天」留下，「第 7 天零 1 秒」删掉。
        // 边界由 tests/Integration/Module/Identity/Cleanup 钉住（任务卡的验收标准）。
        return $this->users->deleteZombieRegistrationsBefore($cutoff);
    }
}
