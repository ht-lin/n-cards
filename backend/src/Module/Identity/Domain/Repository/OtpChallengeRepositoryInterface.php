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
 * ⚠️ 方法集刻意窄。T-103 补上了「作废该邮箱的旧挑战」；
 * T-106 需要「按 magic_token_hash 查」、T-113 需要「删过期的」——
 * 那两个方法各自的语义（要不要带事务、按哪个时间点判过期、批量还是逐条）
 * 只有写那些任务时才知道，现在猜是猜不对的。
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
