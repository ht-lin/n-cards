<?php

declare(strict_types=1);

namespace App\Module\Identity\Domain\Repository;

use App\Module\Identity\Domain\Entity\OtpChallenge;
use App\Shared\Domain\Crypto\HashDigest;
use App\Shared\Domain\Identity\Uuid;

/**
 * `otp_challenges` 的持久化出口。
 *
 * 放 `Domain/Repository/` 而不是 `Application/Port/` 的理由见
 * {@see UserRepositoryInterface} 的类注释。
 *
 * ⚠️ 方法集刻意窄。T-103 补上了「作废该邮箱的旧挑战」，
 * T-106 补上了「按 magic_token_hash 查」，T-113 补上了清理要用的两个。
 *
 * T-113 当初留的问题（「按哪个时间点判过期、批量还是逐条」）现在有答案了，
 * 而且两个方法给出的答案**不一样** —— {@see deleteDeadBefore()} 是批量 DQL，
 * {@see findWithRequestIpOlderThan()} 是逐条走实体。理由分别写在它们的注释里，
 * 别顺手统一。
 */
interface OtpChallengeRepositoryInterface
{
    /**
     * ⚠️ 实现会 `flush()`，跨仓储的写要自己包
     * `Shared\Application\Transaction\TransactionRunnerInterface::run()`。
     */
    public function save(OtpChallenge $challenge): void;

    /**
     * `challenge_id` 就是这张表的主键 —— `POST /auth/otp/request` 返回给客户端的
     * 那个 id（§5.2），`POST /auth/otp/verify` 拿它回来找挑战。
     */
    public function findById(Uuid $id): ?OtpChallenge;

    /**
     * Magic Link 的入口（T-106）：`POST /auth/magic/consume` 拿信里那个令牌的
     * 摘要回来找挑战。
     *
     * ⚠️ **实现必须加行锁**（`PESSIMISTIC_WRITE`）。消费是读-改-写：
     * 查到挑战 → 判 `consumed_at IS NULL` → 写 `consumed_at`。两个并发 POST
     * 不加锁会双双通过那个判空，于是**一个令牌换到两个会话**，
     * 而库里只留下一条看起来完全正常的记录。
     * 这与 T-105 的 `findByRefreshTokenHash()` 是同一个坑，代价也一样：
     * 一条挑战上的并发是个位数量级，付得起。
     *
     * ⚠️ 传进来的摘要是**本地 SHA-256**，不是 Vault HMAC ——
     * 与 `code_hash` 不同口径，理由见 {@see \App\Module\Identity\Application\Magic\ConsumeMagicLinkService::consume()}。
     * 本接口不关心是哪一种，但写迁移与写测试的人需要知道。
     */
    public function findByMagicTokenHash(HashDigest $magicTokenHash): ?OtpChallenge;

    /**
     * 作废该邮箱**全部仍然活跃**的挑战（未消费且未过期）。
     *
     * §7.1：「单次登录只允许一个活跃 challenge —— 新建时作废该 email 的旧 challenge」。
     * 不作废的话，用户连点三次「重发」会同时留下三个可用的码，
     * 而 §7.1 给的爆破预算（`attempts` 上限 5）是按**一个**码算的。
     *
     * ⚠️ 调用方必须在 {@see save()} 新挑战**之前**调它，否则会把刚建的那条一起作废。
     *
     * @return int 受影响行数。调用方通常不关心，但它是集成测试唯一能断言
     *             「WHERE 条件确实按预期收窄」的返回值
     */
    public function invalidateActiveFor(HashDigest $emailHash, \DateTimeImmutable $now): int;

    /**
     * 删掉**已死且死透了**的挑战（T-113）。
     *
     * 「死」有两种：`consumed_at` 非空（验证成功 / Magic Link 被消费 / 被新挑战作废），
     * 或 `expires_at` 已过。两者在读取侧没有行为差别（T-104 对二者都返回 401），
     * 所以清理也不区分。
     *
     * 「死透了」= 那个时刻早于 `$cutoff`。宽限期是
     * `ncards.cleanup.otp_challenge_grace_hours`（24 小时），留着是为了排障时还能
     * 回答「昨晚那批登录失败是码错了还是过期了」。
     *
     * ⚠️ 这张表自 ADR-0014 起每行都带 `email_encrypted`（Vault Transit 加密的收件
     * 邮箱），所以这个方法就是 §8.2 ROPA 里「认证」那一行的保留期本身。调大宽限期
     * 等于延长 PII 留存，要连着 ROPA 一起改。
     *
     * 批量 DQL，理由与 {@see invalidateActiveFor()} 第 1 条相同（逐条是 N+1），
     * 但第 2 条不适用 —— 这里根本不经过实体的不变量。
     *
     * @param \DateTimeImmutable $cutoff 严格小于它才删
     *
     * @return int<0, max> 受影响行数
     */
    public function deleteDeadBefore(\DateTimeImmutable $cutoff): int;

    /**
     * 取出 `created_at < $cutoff` 且 `request_ip_hash` 仍非空的挑战（T-113）。
     *
     * 调用方（{@see \App\Module\Identity\Application\Cleanup\ForgetOtpRequestIpsTask}）
     * 逐条调 {@see OtpChallenge::forgetRequestIp()}
     * 再 {@see save()}。
     *
     * ============================================================================
     * ⚠️ 为什么这一条**不是**批量 DQL，与上面两个方法相反
     * ============================================================================
     * 因为在当前配置下它**恒返回空数组**：挑战 10 分钟过期、24 小时后整行就被
     * {@see deleteDeadBefore()} 删了，活不到 30 天。它是 §8.2「ip_hash 30 天」这条
     * **上限的强制点**，不是主力清理 —— 完整论证见那个任务的类注释。
     *
     * 既然 N 恒为 0，N+1 就没有代价；换来的是「忘记 IP」这件事在代码里只有一个
     * 表达（实体上那个方法，已有单测钉住），而不是散落在一条 DQL 的 SET 子句里。
     * `invalidateActiveFor()` 的注释论证过热路径为什么必须批量，这条恰好是它的反面。
     *
     * @param int<1, max> $limit 单次上限，兜住「有人把宽限期调过 30 天」那种病态情况
     *
     * @return list<OtpChallenge> 最多 `$limit` 条；
     *                            取不满即没有更多
     */
    public function findWithRequestIpOlderThan(\DateTimeImmutable $cutoff, int $limit): array;
}
