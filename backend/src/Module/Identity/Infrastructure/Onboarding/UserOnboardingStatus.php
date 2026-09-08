<?php

declare(strict_types=1);

namespace App\Module\Identity\Infrastructure\Onboarding;

use App\Module\Identity\Domain\Repository\UserRepositoryInterface;
use App\Shared\Application\Onboarding\OnboardingStatusInterface;
use App\Shared\Domain\Identity\Uuid;
use App\Shared\Domain\Onboarding\OnboardingState;

/**
 * {@see OnboardingStatusInterface} 的唯一实现：读 `users.username`（T-108）。
 *
 * ============================================================================
 * 为什么在 Infrastructure 而不是 Application
 * ============================================================================
 * 它实现的是一个 `Shared.Application` 的接口，而 deptrac 里
 * `Identity.Application` 的允许列表**有** `Shared.Application` —— 所以两处都编译得过。
 * 放 Infrastructure 是因为这个类不是编排，是一次持久层查询的适配器：
 * 它没有业务分支，只把「有没有那一行、那一行有没有 username」翻译成状态。
 * 与 `DoctrineUserRepository` 在同一层，读起来也在同一层。
 *
 * ============================================================================
 * ⚠️ 走仓储的 findById()，不写标量 SQL
 * ============================================================================
 * `SELECT username FROM users WHERE id = ?` 看起来更省 —— 少反序列化一个实体。
 * 但它会**绕开 Doctrine 的 identity map**，而这个方法在每个受管请求上都跑一次：
 * `GET /v1/me` 与 `PATCH /v1/me` 紧接着还要拿同一行，走标量查询的话它们会
 * 再查一次库。一次主键查找在 §9.1 的预算里（最紧的是 `PATCH /v1/cards/{id}`
 * P95 ≤ 200 ms）可以忽略，而「同一请求里查两次同一行」是会被复制的形状。
 *
 * ⚠️ 也**不要**在这里加缓存。username 是一次性写入、此后不可变，所以
 * 「完成」这个状态确实可以永久缓存 —— 但反过来那一格不行：删号与 T-113 的
 * 僵尸行清理都会让 `Complete` 变回 `UserUnknown`，而一个永不失效的缓存会让
 * 一个已删除账号的 token 在剩余寿命里继续畅通。收益是一次主键查找，
 * 代价是一条要靠人记得去失效的路径。
 */
final readonly class UserOnboardingStatus implements OnboardingStatusInterface
{
    public function __construct(private UserRepositoryInterface $users)
    {
    }

    public function stateOf(Uuid $userId): OnboardingState
    {
        $user = $this->users->findById($userId);

        if (null === $user) {
            // 签名有效但主体不在了 —— 删号流程或 T-113 的清理跑过了，
            // 而 token 还有最长 15 分钟寿命。见 OnboardingState::UserUnknown。
            return OnboardingState::UserUnknown;
        }

        return $user->hasUsername() ? OnboardingState::Complete : OnboardingState::Incomplete;
    }
}
