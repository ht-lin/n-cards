<?php

declare(strict_types=1);

namespace App\Module\Identity\Domain\Repository;

use App\Module\Identity\Domain\Entity\Session;
use App\Module\Identity\Domain\ValueObject\SessionRevokedReason;
use App\Shared\Domain\Crypto\HashDigest;
use App\Shared\Domain\Identity\Uuid;

/**
 * `sessions` 的持久化出口。
 *
 * 放 `Domain/Repository/` 而不是 `Application/Port/` 的理由见
 * {@see UserRepositoryInterface} 的类注释。
 */
interface SessionRepositoryInterface
{
    /**
     * ⚠️ 实现会 `flush()`，跨仓储的写要自己包
     * `Shared\Application\Transaction\TransactionRunnerInterface::run()`。
     */
    public function save(Session $session): void;

    /**
     * @param Uuid $id JWT 的 `sid` claim（§7.1）
     */
    public function findById(Uuid $id): ?Session;

    /**
     * 按**当前**的 refresh token 摘要查找 —— 刷新流程的入口（T-105）。
     *
     * ⚠️ 查不到**不等于**令牌无效，这是 §7.1 重放检测最容易写错的一处：
     * 一个刚被轮换掉的令牌在这里查不到，但它躺在某一行的 `previous_token_hash` 里，
     * 而那意味着**令牌被窃**（撤销整个会话家族 + 告警 + 安全提醒邮件）。
     *
     * 所以刷新处理器在这里拿到 null 之后**必须**再查一次
     * {@see findByPreviousTokenHash()}，而不是直接返回 401。
     *
     * ⚠️ **加行级悲观锁**（`SELECT ... FOR UPDATE`）。轮换是一次读-改-写：
     * 两个并发请求拿同一个 refresh token 进来，各自读到同一行、各自 `rotate()`，
     * 结果是一次丢失更新 —— 库里留下 B 的新摘要，而 A 的响应里发给客户端的是
     * 一枚谁也不认识的孤儿令牌。锁在这里而不是在调用方，是因为
     * 「查出来就是为了改」是这个方法**唯一**的用途。
     */
    public function findByRefreshTokenHash(HashDigest $refreshTokenHash): ?Session;

    /**
     * 按**上一代**（刚被轮换掉的）refresh token 摘要查找 —— §7.1 的重放检测（T-105）。
     *
     * 查到即判定**令牌被窃**：调用方要撤销这条会话、写指标与告警日志、
     * 发安全提醒邮件，然后返回一个与「未知令牌」逐字相同的 401。
     *
     * ============================================================================
     * 「会话家族」就是返回的这一行，不需要连带返回别的
     * ============================================================================
     * `sessions.id` 在整条轮换链上**不变**（它就是 JWT 的 `sid`），
     * {@see Session::rotate()} 是就地把旧摘要挪进 `previous_token_hash`。
     * 所以一条「家族」在库里始终只有一行，而撤销这一行同时废掉 current 与
     * previous 两个摘要 —— §7.1 说的「撤销该会话家族全部令牌」已经全在里面了。
     *
     * ⚠️ 只有一代 previous，不是一条链（理由见 {@see Session::rotate()} 的注释）。
     * 所以一个**隔了两代**的旧令牌在这里也查不到，会得到普通的 401 而不触发告警。
     * 这是可接受的：客户端在任意时刻手里只有一个 refresh token，
     * 能拿出上一代的攻击者必然是刚刚偷到的。
     */
    public function findByPreviousTokenHash(HashDigest $previousTokenHash): ?Session;

    /**
     * 撤销某台设备上该用户**全部**未撤销的会话 —— 远程登出（T-105）。
     *
     * ⚠️ `userId` 是必需的第二个条件，不是冗余。`devices.id` 由客户端生成、
     * 不是凭据，调用方虽然已经校验过设备归属，但把归属条件一路带到 UPDATE 的
     * WHERE 里意味着「即使上层漏了那次校验，这条语句也伤不到别人的会话」。
     *
     * 实现**必须**逐行走 {@see Session::revoke()}，不要图省事写成批量 DQL `UPDATE` ——
     * 那会绕过「首个 reason 胜出」的幂等语义（一条已因 `reuse_detected` 撤销的会话
     * 被远程登出扫到时，留下的必须仍是安全事件那个原因），也会让身份映射里
     * 已水合的 Session 变成陈旧状态。完整论证在实现类的方法注释里。
     *
     * @return int 实际被撤销的行数（幂等：已撤销的不计入）
     */
    public function revokeAllForDevice(
        Uuid $deviceId,
        Uuid $userId,
        SessionRevokedReason $reason,
        \DateTimeImmutable $now,
    ): int;
}
