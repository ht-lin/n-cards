<?php

declare(strict_types=1);

namespace App\Shared\Domain\Config;

/**
 * 计划维护窗口（§9.2：每周二 03:00–04:00 CET，提前 24 小时下发），T-112。
 *
 * ============================================================================
 * 为什么是「配置一个窗口」而不是「配置三个字段」
 * ============================================================================
 * 任务卡要的是 `{active, message_key, retry_after}` 三个字段。直接把这三个做成
 * env 是可以的，代价是运维每次要**手算**并维护它们之间的一致性：窗口开始时要把
 * `active` 翻成 true、同时开始维护一个递减的 `retry_after`，结束后三个一起清掉。
 * 那是三次人工编辑，而漏掉最后一次的后果是横幅永远挂着。
 *
 * 这里只配窗口的两头，三个字段全部由 {@see statusAt()} 派生。运维只做两件事：
 * 窗口定下来时填进去，过期后清掉（不清也只是多一条过期配置，不会有任何公告）。
 *
 * ============================================================================
 * 为什么来源是 env 而不是 Redis 或数据库
 * ============================================================================
 * Redis：本栈的 Redis 是单容器、无 AOF，§8.2 给它的定位是「限流计数保留 24 小时」。
 * 一次重启就会把公告吃掉，而且 `/v1/config` 必须在 Redis 不可达时照样回答 ——
 * 那就要再写一条降级路径，而降级到「没有公告」与「公告丢了」在外部看来一模一样。
 *
 * 数据库：要一张表、一次迁移、一个仓储，而 deptrac 里 `Shared.Http` 碰不到
 * Doctrine，得再加一个反转端口。对一个一期每周用一次的运维动作，这个形状太大了。
 *
 * env 的代价是**公告一次 = 改 group_vars + 跑一次 Ansible（app 容器重建）**。
 * 这个代价可以接受：§9.2 明说一期单主机部署、不承诺 HA，而公告本来就比窗口早
 * 24 小时，有充裕时间走一次正常部署。
 *
 * ============================================================================
 * ⚠️ 半开区间 [start, end)
 * ============================================================================
 * `end` 那一刻**不在**窗口内。否则 `retry_after` 会算出 0，而 0 的意思是
 * 「立刻重试」—— 与「还在维护中」自相矛盾。
 */
final readonly class MaintenanceWindow
{
    /**
     * §9.2：提前 24 小时下发。
     *
     * 是常量不是 env：窗口本身固定为每周二 03:00–04:00 CET，提前量是产品承诺
     * 而不是部署旋钮。做成 env 只会多一个能被配错、且配错了没人看得出来的值。
     */
    public const ANNOUNCEMENT_LEAD_SECONDS = 86400;

    /**
     * RFC 3339，**offset 必填**。
     *
     * ⚠️ 不接受 `2026-09-15T03:00:00`（无 offset）这种形态。PHP 会按容器的
     * `date.timezone` 去解释它，于是**同一份配置在两个环境指向不同时刻** ——
     * 而本地与 CI 通常都是 UTC，于是这个故障只在生产显形，且症状是「横幅早了/晚了
     * 两小时」，没人会把它和时区联系起来。德国是 CET/CEST 两个 offset，夏令时
     * 切换当天还会再错一小时。
     *
     * 先过正则再构造，而不是靠 `createFromFormat` 的 `P` / `p` 对 `Z` 是否宽容 ——
     * 那个细节随 PHP 版本变，而这里不需要赌它。
     */
    private const ISO_WITH_OFFSET = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(Z|[+-]\d{2}:\d{2})$/';

    private function __construct(
        private ?\DateTimeImmutable $start,
        private ?\DateTimeImmutable $end,
    ) {
    }

    public static function none(): self
    {
        return new self(null, null);
    }

    /**
     * @param string|null $start `MAINTENANCE_WINDOW_START`，两头都留空即无窗口
     * @param string|null $end   `MAINTENANCE_WINDOW_END`
     *
     * @throws \LogicException 配置错误 —— 见类注释与 {@see parse()}
     */
    public static function fromIso(?string $start, ?string $end): self
    {
        $start = self::normalise($start);
        $end = self::normalise($end);

        if (null === $start && null === $end) {
            return self::none();
        }

        // ⚠️ 只配一头必须炸，不能「宽容地」当成无窗口。
        // 漏填 end 的那次部署，运维以为公告发出去了，而客户端什么也没收到 ——
        // 而这件事要等到维护窗口当天有用户投诉才会被发现。
        if (null === $start || null === $end) {
            throw new \LogicException('MAINTENANCE_WINDOW_START and MAINTENANCE_WINDOW_END must be set together (or both left empty for "no window").');
        }

        $from = self::parse('MAINTENANCE_WINDOW_START', $start);
        $until = self::parse('MAINTENANCE_WINDOW_END', $end);

        if ($from >= $until) {
            throw new \LogicException(\sprintf('MAINTENANCE_WINDOW_START (%s) must be strictly before MAINTENANCE_WINDOW_END (%s).', $start, $end));
        }

        return new self($from, $until);
    }

    /**
     * 该时刻对外下发的三个字段。
     *
     * 四段，边界都是闭/开明确的：
     *   - 无窗口，或 `$now >= end`（窗口已过）        → 无公告
     *   - `start <= $now < end`                      → 进行中，带 `retry_after`
     *   - `start - 24h <= $now < start`              → 已公告，无 `retry_after`
     *   - `$now < start - 24h`（窗口还太远）          → 无公告
     */
    public function statusAt(\DateTimeImmutable $now): MaintenanceStatus
    {
        if (null === $this->start || null === $this->end) {
            return MaintenanceStatus::none();
        }

        if ($now >= $this->end) {
            return MaintenanceStatus::none();
        }

        if ($now >= $this->start) {
            // ⚠️ 这个差值恒 ≥ 1，两个前提缺一不可：
            //   - 区间半开，所以走到这里必有 `$now < $this->end`（含微秒比较）；
            //   - `$this->end` 的微秒恒为 0 —— ISO_WITH_OFFSET 不接受小数秒。
            // 于是 floor($now) 是一个严格小于 end 的整数秒，差至少是 1。
            // 换句话说 getTimestamp() 的截断在这里起的正是 ceil 的作用：
            // 还剩 0.4 秒时给出 1，而不是 0（0 的意思是「立刻重试」）。
            // MaintenanceStatus 的构造器再断言一次，那是第二道。
            return new MaintenanceStatus(
                true,
                MaintenanceMessageKey::InProgress,
                $this->end->getTimestamp() - $now->getTimestamp(),
            );
        }

        if ($now >= $this->start->modify(\sprintf('-%d seconds', self::ANNOUNCEMENT_LEAD_SECONDS))) {
            return new MaintenanceStatus(false, MaintenanceMessageKey::Scheduled);
        }

        return MaintenanceStatus::none();
    }

    private static function normalise(?string $raw): ?string
    {
        if (null === $raw) {
            return null;
        }

        $trimmed = trim($raw);

        // `%env(FOO)%` 对一个未设置或留空的变量给的是 `''`，不是 null。
        return '' === $trimmed ? null : $trimmed;
    }

    /**
     * @throws \LogicException 格式不对
     */
    private static function parse(string $variable, string $raw): \DateTimeImmutable
    {
        if (1 !== preg_match(self::ISO_WITH_OFFSET, $raw)) {
            throw new \LogicException(\sprintf('%s is not an RFC 3339 instant with an explicit offset: "%s". Expected something like "2026-09-15T03:00:00+02:00" or "2026-09-15T01:00:00Z".', $variable, $raw));
        }

        // 正则已经保证它可解析；统一换算到 UTC，与 ClockInterface::now() 同一基准
        // （那个接口的约定是「恒为 UTC」），于是比较不依赖任何隐式时区。
        return (new \DateTimeImmutable($raw))->setTimezone(new \DateTimeZone('UTC'));
    }
}
