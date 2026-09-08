<?php

declare(strict_types=1);

namespace App\Shared\Application\Onboarding;

use App\Shared\Domain\Identity\Uuid;
use App\Shared\Domain\Onboarding\OnboardingState;

/**
 * 「这个用户在 §5.2 的状态机里走到哪了」—— {@see
 * \App\Shared\Infrastructure\Http\OnboardingListener} 唯一的数据来源（T-108）。
 *
 * ============================================================================
 * ⚠️ 这是本仓库第一个**由模块实现**的 Shared 端口
 * ============================================================================
 * 到 T-107 为止，每一个 `Shared\Application\*` 接口都由 `Shared\Infrastructure`
 * 实现（`AccessTokenVerifierInterface`、`CryptoServiceInterface`、
 * `IdempotencyStoreInterface`…），而跨模块的既有机制是
 * `<Module>/Application/Port/*` —— 但那个方向反了：deptrac 里
 * `Shared.Infrastructure` 的允许列表是
 * `[Shared.Domain, Shared.Application, Framework.*]`，**没有任何模块图层**，
 * 所以监听器看不见 `Identity.Port`，更看不见 `UserRepositoryInterface`。
 *
 * 于是依赖只能反过来：接口放在这里，实现放在
 * {@see \App\Module\Identity\Infrastructure\Onboarding\UserOnboardingStatus}
 * （`Identity.Infrastructure` 的允许列表里本来就有 `Shared.Application`，
 * 零 deptrac 配置改动）。理由与取舍记在 ADR-0018。
 *
 * ⚠️ 别把这个模式当成「Shared 想要什么就开一个端口」的先例。它成立的前提很窄：
 * 被强制的规则本身是**全 `/v1` 面**的（所以强制点必须在 Shared），
 * 而判定所需的数据属于某一个模块。不满足前一条的东西属于模块自己。
 *
 * ============================================================================
 * 为什么返回状态而不是用户资料
 * ============================================================================
 * 监听器只需要「放行 / 403 / 401」这一个三选一。返回一个用户 DTO 会逼
 * `Shared.Domain` 认识一个 Identity 的类型，而那正是上面那段说不通的方向；
 * 也会让一个每请求都跑的横切组件持有它用不到的个人数据（§8.2 的数据最小化）。
 *
 * 三态而不是 `bool` 的理由在 {@see OnboardingState} 的类注释里。
 */
interface OnboardingStatusInterface
{
    /**
     * ⚠️ **不做**任何鉴权判断 —— 调用方必须已经验过签。本方法只把一个
     * 用户 id 翻译成状态；它不知道这个 id 是从哪来的。
     *
     * @param Uuid $userId access token 的 `sub`（{@see
     *                     \App\Shared\Domain\Http\AuthContext::$userId}）
     */
    public function stateOf(Uuid $userId): OnboardingState;
}
