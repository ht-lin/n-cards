<?php

declare(strict_types=1);

namespace App\Module\Identity\Domain\ValueObject;

/**
 * `users.status`（§5.2 / §17.1 的 `CHECK (status IN ('active','pending_deletion'))`）。
 *
 * ============================================================================
 * 为什么删除账号是一个状态而不是一条 DELETE
 * ============================================================================
 * §8.4 给了删除请求一个**宽限期**（`deletion_requested_at` 起算 30 天，ROPA §8.2 的
 * 「账号存续期 + 30 天宽限」）。宽限期存在的理由是 §3.7：账号删除与共享卡的所有权
 * 冲突 —— 立即物删会让别人钱包里的卡凭空消失。
 *
 * 所以 `pending_deletion` 是一个**真实的业务状态**，不是软删标记：
 * 处于该状态的账号仍然能登录（好取消删除），但 T-4xx 的删除流程会在到期后接管。
 *
 * ⚠️ 唯一的例外是「僵尸注册行」（`username IS NULL` 且超过 7 天）——
 * 那些行**物删**，不走这个状态机。理由见 §5.2：它们只是一条邮箱哈希，
 * 无任何关联数据。清理任务属 T-113。
 */
enum UserStatus: string
{
    /** 正常账号。 */
    case Active = 'active';

    /** 用户已请求删除，处在 §8.4 的宽限期内。 */
    case PendingDeletion = 'pending_deletion';

    /**
     * 注册时的默认值，与 §17.1 的 `DEFAULT 'active'` 必须一致。
     */
    public static function default(): self
    {
        return self::Active;
    }
}
