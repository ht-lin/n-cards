<?php

declare(strict_types=1);

namespace App\Module\Identity\Domain\Repository;

use App\Module\Identity\Domain\Entity\OtpChallenge;
use App\Shared\Domain\Identity\Uuid;

/**
 * `otp_challenges` 的持久化出口。
 *
 * 放 `Domain/Repository/` 而不是 `Application/Port/` 的理由见
 * {@see UserRepositoryInterface} 的类注释。
 *
 * ⚠️ 方法集只有两个，这是刻意的。T-103 还需要「作废该邮箱的旧挑战」、
 * T-106 需要「按 magic_token_hash 查」、T-113 需要「删过期的」——
 * 那三个方法各自的语义（要不要带事务、按哪个时间点判过期、批量还是逐条）
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
}
