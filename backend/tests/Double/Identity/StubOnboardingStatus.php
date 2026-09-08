<?php

declare(strict_types=1);

namespace App\Tests\Double\Identity;

use App\Shared\Application\Onboarding\OnboardingStatusInterface;
use App\Shared\Domain\Identity\Uuid;
use App\Shared\Domain\Onboarding\OnboardingState;

/**
 * 固定回答的 {@see OnboardingStatusInterface}，并**记下被问过几次**。
 *
 * 那个计数是重点：`OnboardingListener` 的四条 early return（非主请求、
 * 非 `/v1`、豁免路由、无 `AuthContext`）如果哪条失效了，功能上可能仍然是
 * 「放行」——因为这个替身照样会说 `Complete`。只有「一次都没问」这个断言
 * 能证明它是**结构性地**跳过了，而不是碰巧问出了一个放行的答案。
 * 在真库上那个差别就是每个免鉴权请求多一次 DB 往返。
 */
final class StubOnboardingStatus implements OnboardingStatusInterface
{
    private int $calls = 0;

    public function __construct(private readonly OnboardingState $answer = OnboardingState::Complete)
    {
    }

    public function stateOf(Uuid $userId): OnboardingState
    {
        ++$this->calls;

        return $this->answer;
    }

    public function calls(): int
    {
        return $this->calls;
    }
}
