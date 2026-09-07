<?php

declare(strict_types=1);

namespace App\Shared\Domain\Limit;

use App\Shared\Domain\Error\DomainException;

/**
 * §7.5 系统限额的强制点（§3.1 的落地）。超限抛 `422 limit_exceeded`。
 *
 * ============================================================================
 * ⚠️ 为什么在 Shared\Domain 而不是任务书写的 Shared\Infrastructure\RateLimit
 * ============================================================================
 * T-006 的交付物清单写的是 `Shared/Infrastructure/RateLimit/LimitEnforcer`。
 * 照字面放过去，这个类**没有任何模块能调用**：
 *
 *   deptrac.yaml 里每个模块 `*.Application` 的允许列表是
 *   `_Ports, <M>.Domain, Shared.Domain, Shared.Application, Framework.Core`
 *   —— **不含 `Shared.Infrastructure`**；`*.Domain` 更是只有 `Shared.Domain` 一项。
 *
 * 而「这个用户已经有 500 张卡了」正是 Wallet 的 Application / Domain 该做出的判定
 * （T-111 的强制点就在那一层）。这与 {@see \App\Shared\Domain\Error\ErrorCode}
 * 当初的处境完全相同，那个类的注释已经记录了同一条论证 —— 请连着读。
 *
 * 代价：`Shared.Domain` 的允许列表是**空的**，所以这里不能用 `#[Autowire]`
 * （`Symfony\Component\DependencyInjection` 属于 `Framework.Core`）。
 * 值由 `config/services.yaml` 显式 `arguments` 从 `%ncards.limits.*%` 注入。
 *
 * ============================================================================
 * 三个方法而不是一个
 * ============================================================================
 * `enforceCanAdd` 与 `enforceLength` 的**边界语义不同**，合并成一个会立刻出错：
 *
 *   - 计数类问的是「还能再加一个吗」：持有 499 张卡 → 可以（这是第 500 张）；
 *     持有 500 张 → 不行。判据是 `current >= max`。
 *   - 长度类问的是「这个值本身合法吗」：100 字符的 title 合法，101 不合法。
 *     判据是 `length > max`。
 *
 * 把两者写成同一个 `enforce($limit, $value)` 的话，调用方必须自己记得
 * 计数要不要先 +1 —— 记错的症状是限额差一，且没有任何报错。
 */
final class LimitEnforcer
{
    /**
     * @param int    $cardsPerUser         §7.5：500
     * @param int    $membersPerCard       §7.5：20（1 owner + 19 viewer）
     * @param int    $friendsPerUser       §7.5：500
     * @param int    $friendRequestsPerDay §7.5：50
     * @param int    $shareInvitesPerDay   §7.5：100
     * @param int    $barcodePayloadBytes  §7.5：1024 **字节**
     * @param int    $noteChars            §7.5：2000 **字符**
     * @param int    $titleChars           §7.5：100 **字符**
     * @param int    $usernameMinChars     §3.8 / §7.5：3
     * @param int    $usernameMaxChars     §3.8 / §7.5：20
     * @param string $usernamePattern      §3.8 / §7.5：`^[a-z0-9_]{3,20}$`，**不带定界符**
     */
    public function __construct(
        private readonly int $cardsPerUser,
        private readonly int $membersPerCard,
        private readonly int $friendsPerUser,
        private readonly int $friendRequestsPerDay,
        private readonly int $shareInvitesPerDay,
        private readonly int $barcodePayloadBytes,
        private readonly int $noteChars,
        private readonly int $titleChars,
        private readonly int $usernameMinChars,
        private readonly int $usernameMaxChars,
        private readonly string $usernamePattern,
        private readonly int $usernameAttemptsPerUser,
    ) {
    }

    /**
     * 该限额的上限值。
     *
     * ⚠️ 无 `default` 分支 —— 新增 {@see SystemLimit} case 而忘了在构造里加对应参数，
     * 第一次被碰到就 `\UnhandledMatchError`，测试直接红。
     */
    public function max(SystemLimit $limit): int
    {
        return match ($limit) {
            SystemLimit::CardsPerUser => $this->cardsPerUser,
            SystemLimit::MembersPerCard => $this->membersPerCard,
            SystemLimit::FriendsPerUser => $this->friendsPerUser,
            SystemLimit::FriendRequestsPerDay => $this->friendRequestsPerDay,
            SystemLimit::ShareInvitesPerDay => $this->shareInvitesPerDay,
            SystemLimit::BarcodePayloadBytes => $this->barcodePayloadBytes,
            SystemLimit::NoteChars => $this->noteChars,
            SystemLimit::TitleChars => $this->titleChars,
            SystemLimit::UsernameAttemptsPerUser => $this->usernameAttemptsPerUser,
        };
    }

    /**
     * 「在已有 `$currentCount` 个的基础上，还能再加 `$adding` 个吗？」.
     *
     * 调用方传的是**新增之前**的存量。500 上限下：499 放行（这是第 500 个），
     * 500 拒绝。
     *
     * @param int $currentCount 新增前的存量。T-111 的卡数只统计 `owner_id = :user`
     *                          —— 共享卡不计入 viewer 的额度（§17.5 Q11），
     *                          否则 owner 可以通过共享消耗他人配额
     *
     * @throws DomainException `422 limit_exceeded`
     */
    public function enforceCanAdd(SystemLimit $limit, int $currentCount, int $adding = 1): void
    {
        if (LimitUnit::Count !== $limit->unit()) {
            throw new \LogicException(\sprintf('%s 不是计数类限额，用 enforceLength()。', $limit->value));
        }

        $max = $this->max($limit);

        if ($currentCount + $adding > $max) {
            throw DomainException::limitExceeded($limit->value, $max);
        }
    }

    /**
     * 「这个值的长度合法吗？」.
     *
     * 按 {@see SystemLimit::unit()} 自动选 `strlen`（字节）还是 `mb_strlen`（字符）——
     * 这个选择不交给调用点，用错的症状是静默的：限额看起来生效了，只是数字
     * 比 §7.5 写的松一点或紧一点。
     *
     * @throws DomainException `422 limit_exceeded`
     */
    public function enforceLength(SystemLimit $limit, string $value): void
    {
        $length = match ($limit->unit()) {
            LimitUnit::Bytes => \strlen($value),
            LimitUnit::Characters => mb_strlen($value),
            LimitUnit::Count => throw new \LogicException(\sprintf('%s 是计数类限额，用 enforceCanAdd()。', $limit->value)),
        };

        $max = $this->max($limit);

        if ($length > $max) {
            throw DomainException::limitExceeded($limit->value, $max);
        }
    }

    // ========================================================================
    // username 的**格式**约束（§3.8 / §7.5）
    //
    // 只暴露常量，**不**在这里抛异常：不合规的 username 是 `422 username_invalid`
    // 而不是 `limit_exceeded`（理由见 SystemLimit 的类注释）。
    // 值对象与校验由 T-107 的 Identity\Domain\ValueObject\Username 负责，
    // 它经 UsernameRules 读下面这三个访问器。
    //
    // ⚠️ username 的第四个常量 —— 10 次总计 —— **不**在这里，它是
    // SystemLimit::UsernameAttemptsPerUser，走上面的 enforceCanAdd()。
    // ========================================================================

    public function usernameMinChars(): int
    {
        return $this->usernameMinChars;
    }

    public function usernameMaxChars(): int
    {
        return $this->usernameMaxChars;
    }

    /**
     * **不带定界符**的正则。调用方自己加 —— 同一个字符串还要喂给 Android 侧
     * 做本地预校验（T-010），那边是 Kotlin 的 `Regex`，没有 PHP 的 `/.../` 概念。
     */
    public function usernamePattern(): string
    {
        return $this->usernamePattern;
    }
}
