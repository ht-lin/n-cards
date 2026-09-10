<?php

declare(strict_types=1);

namespace App\Shared\Domain\Config;

/**
 * 某一时刻的维护公告状态 —— `GET /v1/config` 里 `maintenance` 那三个字段（§9.2，T-112）。
 *
 * 由 {@see MaintenanceWindow::statusAt()} 算出来，自己不知道「现在」是什么时候。
 * 这是本卡唯一有分支的逻辑能被纯单测钉住的原因。
 *
 * ============================================================================
 * ⚠️ `retryAfter` 与 `active` 的绑定是不变量，不是巧合
 * ============================================================================
 * `retryAfter` **仅在 `active` 为 true 时有值**。它是 §6.1 里 `503` 那个
 * `Retry-After` 的同义物（「多久以后可以重试」），而那句话对一个还没开始的窗口
 * 没有意义 —— 提前 24 小时的公告阶段只下发 `messageKey`。
 *
 * 横幅需要显示的时间不靠这个字段：§9.2 把窗口固定成**每周二 03:00–04:00 CET**，
 * 客户端的德语文案里本来就能写死它。这也是规格只给了三个字段、没给时间戳的原因。
 *
 * 构造器断言这条绑定。它便宜，而违反它的后果（客户端在公告期按一个不存在的秒数
 * 倒计时）在服务端这侧完全没有症状。
 */
final readonly class MaintenanceStatus
{
    /**
     * @param int|null $retryAfter 距窗口结束的秒数，恒 ≥ 1
     *
     * @throws \LogicException `$retryAfter` 与 `$active` 不一致
     */
    public function __construct(
        public bool $active,
        public ?MaintenanceMessageKey $messageKey = null,
        public ?int $retryAfter = null,
    ) {
        if ($active !== (null !== $retryAfter)) {
            throw new \LogicException('MaintenanceStatus::$retryAfter is set exactly when $active is true.');
        }

        if (null !== $retryAfter && $retryAfter < 1) {
            // 0 会让客户端「立刻重试」，而窗口还没结束。
            // MaintenanceWindow 用 ceil() 保证这一点，这里是第二道。
            throw new \LogicException('MaintenanceStatus::$retryAfter must be at least 1 second.');
        }
    }

    /** 无公告：没有窗口、窗口还在 24 小时以外、或者窗口已经结束。 */
    public static function none(): self
    {
        return new self(false);
    }
}
