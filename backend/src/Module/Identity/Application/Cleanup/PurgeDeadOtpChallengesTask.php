<?php

declare(strict_types=1);

namespace App\Module\Identity\Application\Cleanup;

use App\Module\Identity\Domain\Repository\OtpChallengeRepositoryInterface;
use App\Shared\Application\Cleanup\CleanupTaskInterface;

/**
 * 删掉已死且过了宽限期的 `otp_challenges`（§8.2 ROPA 的「认证」那一行，T-113）。
 *
 * ============================================================================
 * 这是本卡三个任务里唯一有实际工作量的那个
 * ============================================================================
 * §7.1 给挑战的寿命是 10 分钟，而 §9.3 的量级下每天有千级的登录 —— 也就是说
 * 这张表每天产生千级的死行，在本任务上线之前它们**一行都没被删过**。
 *
 * 更要紧的是它们装着什么：ADR-0014 之后每条挑战都带 `email_encrypted`
 * （Vault Transit 加密的收件邮箱），这张表的数据分级因此从「只有哈希」升级成
 * 「含加密的个人数据」。所以这个任务不是清理磁盘，是 §8.2 那一行保留期的
 * **执行点** —— 在它跑起来之前，那份 Art. 30 记录写的是一件没有发生的事。
 *
 * ============================================================================
 * 为什么留 24 小时宽限而不是立刻删
 * ============================================================================
 * 排障。「昨晚那批登录失败是码错了、过期了、还是次数耗尽？」这个问题的答案在
 * `attempts` 与 `consumed_at` 两列上，删掉就没了。24 小时够覆盖「用户第二天早上
 * 来报障」这个最常见的时间差，同时把 PII 的留存窗口压在一天之内。
 *
 * 数字在 `config/packages/ncards_cleanup.yaml`，改它要连着 §8.2 的表一起改。
 */
final readonly class PurgeDeadOtpChallengesTask implements CleanupTaskInterface
{
    /**
     * @param int<1, max> $graceHours `ncards.cleanup.otp_challenge_grace_hours`
     */
    public function __construct(
        private OtpChallengeRepositoryInterface $challenges,
        private int $graceHours,
    ) {
    }

    public function name(): string
    {
        return 'identity.dead_otp_challenges';
    }

    public function run(\DateTimeImmutable $now): int
    {
        $cutoff = $now->modify(\sprintf('-%d hours', $this->graceHours));

        // 「死」的两种形态（已消费 / 已过期）都交给仓储的 WHERE 去判 ——
        // 在这里先查一遍再删是 N+1，而且没有任何不变量需要经过实体。
        return $this->challenges->deleteDeadBefore($cutoff);
    }
}
