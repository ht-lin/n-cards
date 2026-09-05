<?php

declare(strict_types=1);

namespace App\Module\Identity\Domain\Repository;

use App\Module\Identity\Domain\Entity\Session;
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
     * 所以 T-105 的处理器在这里拿到 null 之后**必须**再查一次 previous，
     * 而不是直接返回 401。查 previous 的方法由 T-105 加 —— 它的返回语义
     *（要不要连带返回家族里的其他行）属于那张卡。
     */
    public function findByRefreshTokenHash(HashDigest $refreshTokenHash): ?Session;
}
