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
 * T-106 补上了「按 magic_token_hash 查」；T-113 还需要「删过期的」——
 * 那个方法的语义（按哪个时间点判过期、批量还是逐条）只有写那个任务时才知道，
 * 现在猜是猜不对的。
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
}
