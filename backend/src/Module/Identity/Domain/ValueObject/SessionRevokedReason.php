<?php

declare(strict_types=1);

namespace App\Module\Identity\Domain\ValueObject;

/**
 * `sessions.revoked_reason`（§5.2 / §7.1，取值域由 T-105 的任务卡逐字列出）。
 *
 * ============================================================================
 * 这四个值不是同一类事件，区别是有后果的
 * ============================================================================
 * 撤销一个会话的四种成因里，只有一种是安全事件：
 *
 *   - {@see Logout}         用户自己点了退出。什么都不用做。
 *   - {@see UserRevoked}    用户在设备管理页远程登出了另一台设备（T-105）。
 *   - {@see AccountDeleted} §8.4 的账号删除流程收尾时撤销全部会话。
 *   - {@see ReuseDetected}  **令牌被窃**（§7.1）：收到一个已经用过的 refresh token。
 *                           后果是撤销整个会话家族 + `audit_log(reuse_detected)`
 *                           + 告警 + 给用户发安全提醒邮件。
 *
 * 把它做成 enum 而不是自由文本，是为了让「哪些 reason 要触发告警」成为一个
 * `match()` 而不是一次字符串比较 —— 后者拼错一个字母就会静默地不告警，
 * 而这恰恰是最不能静默的一条路径。
 *
 * 这一列**不加库层 CHECK**（§13.6：新增枚举值是向后兼容变更）。
 * T-105 落地撤销逻辑时会用到 {@see isSecurityIncident()}。
 */
enum SessionRevokedReason: string
{
    /** 用户主动退出（`POST /auth/logout`）。 */
    case Logout = 'logout';

    /** §7.1 的轮换重放检测命中 —— 判定为令牌被窃。 */
    case ReuseDetected = 'reuse_detected';

    /** 用户在设备管理页远程登出了这台设备。 */
    case UserRevoked = 'user_revoked';

    /** §8.4 的账号删除流程收尾。 */
    case AccountDeleted = 'account_deleted';

    /**
     * 这次撤销是不是安全事件（要告警 + 发提醒邮件）。
     *
     * ⚠️ 无 `default` 分支 —— 新增 case 而忘了在这里表态，第一次被碰到就
     * `\UnhandledMatchError`，测试直接红。这是本仓库对闭合词表的一贯写法
     * （见 `Shared\Domain\Error\ErrorCode` 与 `Shared\Domain\Limit\LimitEnforcer`）。
     */
    public function isSecurityIncident(): bool
    {
        return match ($this) {
            self::ReuseDetected => true,
            self::Logout, self::UserRevoked, self::AccountDeleted => false,
        };
    }
}
