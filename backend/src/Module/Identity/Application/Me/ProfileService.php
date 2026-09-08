<?php

declare(strict_types=1);

namespace App\Module\Identity\Application\Me;

use App\Module\Identity\Domain\Entity\User;
use App\Module\Identity\Domain\Repository\UserRepositoryInterface;
use App\Shared\Domain\Error\DomainException;
use App\Shared\Domain\Error\ErrorCode;
use App\Shared\Domain\Http\AuthContext;
use App\Shared\Domain\Time\ClockInterface;

/**
 * `GET /v1/me` 与 `PATCH /v1/me`（§6.2，T-108）。
 *
 * 两个操作放在一个服务里的理由同 {@see \App\Module\Identity\Application\Session\DeviceService}：
 * 它们读写同一行、共用同一段取用户的开头，拆开只会得到两个各三行的类。
 *
 * ============================================================================
 * ⚠️ `GET /v1/me` 是 onboarding 未完成时仍然可达的三个端点之一
 * ============================================================================
 * 所以它**必然**会被 `username IS NULL` 的用户调到，而且那正是它最重要的用途：
 * 客户端靠响应里的 `onboarding_complete: false` 决定去 username 设定页
 * （§5.2：该页不可跳过、不可返回）。
 *
 * 反过来 `PATCH /v1/me` **不**在那张豁免表里 —— 未完成的用户调它得到
 * `403 username_required`，请求根本到不了本类。注册期的语言由
 * `POST /auth/otp/request` 的 `locale` 决定，不需要这个端点。
 *
 * ============================================================================
 * 不需要 TransactionRunner
 * ============================================================================
 * 只碰一个仓储，而 {@see UserRepositoryInterface::save()} 自己 flush。
 * 需要包事务的是「建 user + 建 device + 建 session」那种跨仓储的写
 * （T-104 的 `SessionIssuer`）。
 */
final readonly class ProfileService
{
    public function __construct(
        private UserRepositoryInterface $users,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws DomainException `internal_error`（500）—— 见 {@see require()}
     */
    public function profile(AuthContext $auth): UserProfile
    {
        return UserProfile::of($this->require($auth));
    }

    /**
     * ⚠️ **请求体里的 `username` 字段在这一层之前就被拒了**
     * （{@see ProfileUpdatePayload::fromArray()} → `409 username_immutable`）。
     * 本方法拿到的 payload 里不可能有它，所以这里没有、也不该有任何
     * 与 username 相关的分支 —— 加一个就是把那条不变量搬到了第二个地方。
     *
     * @throws DomainException `internal_error`（500）
     */
    public function update(AuthContext $auth, ProfileUpdatePayload $payload): UserProfile
    {
        $user = $this->require($auth);

        // 同值时 changeLocale() 自身是 no-op（连 updatedAt 都不动），
        // 于是重复发同一个 PATCH 不会在 `users` 上留下一串无意义的更新。
        $user->changeLocale($payload->locale, $this->clock->now());

        $this->users->save($user);

        return UserProfile::of($user);
    }

    /**
     * 本次请求的用户行。
     *
     * ⚠️ 落空是 500 而不是 404，口径与 {@see AssignUsernameService::assign()} 逐字相同：
     * 令牌验过签、且 {@see \App\Shared\Infrastructure\Http\OnboardingListener}
     * 刚刚为了判定状态查过同一行，所以走到这里它**必然**存在 ——
     * 除非它在这两步之间被删号流程或 T-113 的清理任务删掉了。
     * 那不是客户端能修的东西，也不该是 404：对调用方来说，
     * 它拿着一个有效令牌却被告知自己不存在。
     *
     * @throws DomainException `internal_error`（500）
     */
    private function require(AuthContext $auth): User
    {
        $user = $this->users->findById($auth->userId);

        if (!$user instanceof User) {
            throw new DomainException(ErrorCode::InternalError, 'An unexpected error occurred.');
        }

        return $user;
    }
}
