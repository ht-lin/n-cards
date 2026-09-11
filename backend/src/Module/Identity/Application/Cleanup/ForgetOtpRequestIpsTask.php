<?php

declare(strict_types=1);

namespace App\Module\Identity\Application\Cleanup;

use App\Module\Identity\Domain\Repository\OtpChallengeRepositoryInterface;
use App\Shared\Application\Cleanup\CleanupTaskInterface;

/**
 * 把 30 天前的 `otp_challenges.request_ip_hash` 置空（§8.2：「ip_hash 30 天」，T-113）。
 *
 * ============================================================================
 * ⚠️⚠️ 读之前先读这一段：它在当前配置下**恒处理 0 行**，这是对的
 * ============================================================================
 * 挑战 10 分钟过期，{@see PurgeDeadOtpChallengesTask} 在它死后 24 小时就把整行删了。
 * 没有任何一行活得到 30 天，所以这个任务每天返回 0。
 *
 * **它不是死代码，删掉它是一个合规回退。** 理由有三条，缺一不可：
 *
 * 1. §8.2 的 ROPA 对 `ip_hash` 写的是「30 天」，而那是一个**上限**，与「挑战整行
 *    什么时候删」是两条独立的承诺。今天前者被后者顺带满足了，但那是巧合 ——
 *    巧合不是执行点。把上限写成代码，它才在 Art. 30 的意义上真的存在。
 *
 * 2. 那个巧合建立在 `ncards.cleanup.otp_challenge_grace_hours = 24` 上。
 *    有人为了排障把它调到 45 天（一个完全合理的诉求），30 天的上限当场失效，
 *    而**没有任何测试会红** —— 除非这道闸还在原地。它会在那一天自动开始干活。
 *
 * 3. {@see \App\Module\Identity\Domain\Entity\OtpChallenge::forgetRequestIp()}
 *    早在 T-101 就写好并有单测钉住，注释写着「由 T-113 的每日任务调用」。
 *    本任务就是那个调用方。
 *
 * ============================================================================
 * ⚠️ 为什么逐条走实体，而不是一条批量 UPDATE
 * ============================================================================
 * 正因为 N 恒为 0 —— N+1 在这里没有代价。换来的是「忘记 IP」这件事在代码里
 * 只有**一个**表达（实体上那个方法），而不是散落在一条 DQL 的 SET 子句里。
 *
 * 这与 `DoctrineOtpChallengeRepository::invalidateActiveFor()` 的结论相反，
 * 而那里的论证依然成立：它跑在**每一次** `POST /v1/auth/otp/request` 上，
 * N+1 是真实成本。两条结论不冲突，因为前提不同。别顺手统一。
 *
 * `$batchSize` 兜的是理由 2 那种情况刚发生时的第一趟：一次处理上限那么多行，
 * 剩下的明天接着 —— 宁可多跑几天，也不要一次把几十万行拉进内存。
 */
final readonly class ForgetOtpRequestIpsTask implements CleanupTaskInterface
{
    /**
     * @param int<1, max> $retentionDays `ncards.cleanup.request_ip_hash_days`
     * @param int<1, max> $batchSize     `ncards.cleanup.request_ip_batch_size`
     */
    public function __construct(
        private OtpChallengeRepositoryInterface $challenges,
        private int $retentionDays,
        private int $batchSize,
    ) {
    }

    public function name(): string
    {
        return 'identity.otp_request_ips';
    }

    public function run(\DateTimeImmutable $now): int
    {
        $cutoff = $now->modify(\sprintf('-%d days', $this->retentionDays));

        $stale = $this->challenges->findWithRequestIpOlderThan($cutoff, $this->batchSize);

        $forgotten = 0;

        foreach ($stale as $challenge) {
            $challenge->forgetRequestIp();

            // ⚠️ 逐条 save()（每次 flush 一行）而不是攒一批。行数恒为 0～个位数，
            // 而分批攒的话「某一行写失败」会把同一批里已经改好的其余行一起回滚 ——
            // 对一个合规兜底来说，尽量多忘掉几行比原子性更重要。
            $this->challenges->save($challenge);
            ++$forgotten;
        }

        return $forgotten;
    }
}
