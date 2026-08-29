<?php

declare(strict_types=1);

namespace App\Shared\Domain\Limit;

/**
 * §7.5 系统限额表里**可强制**的那几项。
 *
 * ============================================================================
 * 为什么是枚举而不是裸字符串
 * ============================================================================
 * `enforce('cards_per_user', ...)` 里的一个拼写错误在运行期才会现形，而且现形的
 * 方式是「限额没生效」—— 没有异常、没有日志、测试照样绿。枚举把它挪到了
 * 静态分析能看见的地方（phpstan level 8）。
 *
 * `value` 同时是发进 `422 limit_exceeded` 的 `detail` 里的限额名，所以它是
 * **面向客户端的稳定标识**：与 §6.1 的 code 一样，改名不是向后兼容的变更。
 *
 * ============================================================================
 * ⚠️ username 的三个常量**不在**本枚举里
 * ============================================================================
 * §7.5 的表里有「username 长度 3–20 字符，`[a-z0-9_]`」，但它不合规时的错误码是
 * `422 username_invalid`（§6.1 的专门 code），不是 `limit_exceeded`。
 * 塞进来会让客户端无法区分「名字格式不对，换一个」与「你的额度满了」。
 * 那三个值由 {@see LimitEnforcer} 以只读访问器暴露给 T-107。
 *
 * ============================================================================
 * ⚠️ 「每日」两项是计数，不是速率限制
 * ============================================================================
 * `FriendRequestsPerDay` / `ShareInvitesPerDay` 走 `422 limit_exceeded`，
 * 计数来自数据库（按 UTC 自然日），**不经 Redis**。§7.5 把它们放在限额表而不是
 * 速率表里，是有意的：它们是产品语义（「你今天已经发了 50 个好友请求」），
 * 客户端应该展示成额度用尽而不是「请稍后重试」。
 * 真正的速率限制在 `config/packages/rate_limiter.yaml`。
 */
enum SystemLimit: string
{
    case CardsPerUser = 'cards_per_user';
    case MembersPerCard = 'members_per_card';
    case FriendsPerUser = 'friends_per_user';
    case FriendRequestsPerDay = 'friend_requests_per_day';
    case ShareInvitesPerDay = 'share_invites_per_day';
    case BarcodePayloadBytes = 'barcode_payload_bytes';
    case NoteChars = 'note_chars';
    case TitleChars = 'title_chars';

    /**
     * 这一项量的是什么。
     *
     * ⚠️ `match($this)` **没有 `default` 分支**是刻意的，与 `ErrorCode::httpStatus()`
     * 同一套自我强制机制：新增 case 忘了补映射 → 第一次被碰到就 `\UnhandledMatchError`，
     * 而 phpunit.xml.dist 开了 failOnWarning/failOnRisky，测试直接红。
     */
    public function unit(): LimitUnit
    {
        return match ($this) {
            self::CardsPerUser, self::MembersPerCard, self::FriendsPerUser,
            self::FriendRequestsPerDay, self::ShareInvitesPerDay => LimitUnit::Count,
            self::BarcodePayloadBytes => LimitUnit::Bytes,
            self::NoteChars, self::TitleChars => LimitUnit::Characters,
        };
    }
}
