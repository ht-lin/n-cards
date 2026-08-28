<?php

declare(strict_types=1);

namespace App\Shared\Domain\Time;

/**
 * 时钟（§12.2 的 `Shared/Domain/Clock.php`）。
 *
 * ⚠️ **刻意不是** `Psr\Clock\ClockInterface`。deptrac 的 `Shared.Domain` 允许列表是空的，
 * 而 `Framework.Core` 收集 `^(Symfony|Psr|Twig)\\` —— 继承 PSR 的接口在这一层是 violation。
 * 实现类 `Shared\Infrastructure\Time\SystemClock` 在 Infrastructure 层，那里可以顺带
 * 实现 PSR 接口（如果将来有第三方库要）。
 *
 * 两个方法而不是一个：`nowMillis()` 不是 `now()->format('Uv')` 的糖。
 * {@see \App\Shared\Domain\Identity\Uuid7Generator} 每次生成 id 都要毫秒时间戳，
 * 走一趟 DateTimeImmutable 的构造与格式化纯属浪费；而实现类保证两者从**同一次**
 * 时间读取派生，所以它们永远不会自相矛盾。
 *
 * 存在的意义是**可测试性**：注入 `tests/Double/Time/FrozenClock` 后，
 * 「同一毫秒内生成 4096 个 id」「NTP 回拨」这类分支才写得出确定性的测试。
 */
interface ClockInterface
{
    /**
     * 当前时刻。**约定恒为 UTC**（§6.1：时间一律 RFC 3339 UTC）。
     */
    public function now(): \DateTimeImmutable;

    /**
     * 当前 Unix 时间戳，毫秒。
     */
    public function nowMillis(): int;
}
