<?php

declare(strict_types=1);

namespace App\Module\Identity\Domain\ValueObject;

use App\Shared\Domain\Limit\LimitEnforcer;

/**
 * {@see Username} 校验时要用的那几条配置，聚成一个可注入的对象（T-107）。
 *
 * ============================================================================
 * 为什么值对象自己不去读配置
 * ============================================================================
 * deptrac 里 `Identity.Domain` 的允许列表只有 `Shared.Domain` 一项。
 * `#[Autowire]` 属于 `Framework.Core`，在那一层是 violation ——
 * 也就是说 `Username` **不可能**自己拿到参数容器里的值。
 *
 * 三种出路：把常量硬编码进值对象（那样任务卡「保留词由配置文件维护」就落空了）、
 * 让 Application 层把四个散装参数一个个传进 `Username::fromInput()`
 * （签名会长成 `(string $raw, int $min, int $max, string $pattern, array $reserved)`，
 * 每个调用点都要记住顺序），或者本类。选了本类：它是**一个**参数，
 * 而且「username 的规则集」本身就是个有名字的概念。
 *
 * ============================================================================
 * 为什么长度与正则是从 LimitEnforcer 转手，而不是再读一遍参数
 * ============================================================================
 * `LimitEnforcer::usernameMinChars()` / `usernameMaxChars()` / `usernamePattern()`
 * 三个访问器从 T-006 落地那天起就带着一句注释：「值对象与校验由 T-107 的
 * `Identity\Domain\ValueObject\Username` 负责」。它们等的就是这里。
 *
 * 再接一遍 `%ncards.limits.username_*%` 会开出第二个真相来源 ——
 * 改 §7.5 的数字时改一处、忘一处，而症状是「Android 侧按 20 预校验、
 * 服务端按 30 放行」这种只在边界上现形的分叉。
 *
 * 保留词是第四条、也是唯一不来自 LimitEnforcer 的一条：词表不是限额
 *（没有上限值、超了返回的也不是 `limit_exceeded`），
 * 所以它有自己的配置文件 `config/packages/ncards_username.yaml`。
 */
final readonly class UsernameRules
{
    /** @var list<string> 已归一化（小写）的保留词，精确匹配 */
    private array $reservedWords;

    /**
     * @param array<array-key, string> $reservedWords `%ncards.username.reserved_words%`。
     *                                                接线在 config/services.yaml —— 数组参数
     *                                                autowiring 解析不了，必须显式列出。
     *                                                声明成带键的数组而不是 list 是有意的：
     *                                                YAML 序列给的确实是 list，但下面那句
     *                                                `array_values()` 是给「哪天有人把它写成
     *                                                映射」准备的 —— 带键的数组会被
     *                                                `in_array()` 正常处理，却会在
     *                                                {@see reservedWords()} 的返回值上
     *                                                破坏 `list` 契约
     */
    public function __construct(
        private LimitEnforcer $limits,
        array $reservedWords,
    ) {
        $this->reservedWords = array_values($reservedWords);
    }

    public function minChars(): int
    {
        return $this->limits->usernameMinChars();
    }

    public function maxChars(): int
    {
        return $this->limits->usernameMaxChars();
    }

    /**
     * **不带定界符**的字符集正则（`^[a-z0-9_]{3,20}$`）。
     *
     * 调用方自己加 `/.../` —— 同一个字符串还要喂给 Android 侧做本地预校验（T-010），
     * 那边是 Kotlin 的 `Regex`，没有 PHP 的定界符概念。
     */
    public function pattern(): string
    {
        return $this->limits->usernamePattern();
    }

    /**
     * §3.8 的保留词判定。
     *
     * @param string $normalized **已归一化**的值（`trim` + 小写）。传未归一化的进来
     *                           会静默漏判 —— `Admin` 不等于 `admin`。
     *                           唯一的调用点是 {@see Username::fromInput()}，
     *                           它在归一化与字符集校验**之后**才调这里
     *
     * ⚠️ 精确匹配，不做前缀 / 子串 / 变体。完整论证在
     * `config/packages/ncards_username.yaml` 的抬头注释里。
     */
    public function isReserved(string $normalized): bool
    {
        // in_array 的第三个参数必须是 true：宽松比较下 `0 == 'admin'` 在
        // PHP 8 之前是 true，而这里两边都是字符串、严格比较也没有额外成本。
        return \in_array($normalized, $this->reservedWords, true);
    }

    /**
     * 词表本身。只给测试用 —— `UsernameRulesTest` 拿它逐条断言
     * 「每个保留词自己必须是一个合法的 username」，否则那条词永远匹配不到任何输入
     *（值对象会先在字符集那一步把输入拒掉），等于白写。
     *
     * @return list<string>
     */
    public function reservedWords(): array
    {
        return $this->reservedWords;
    }
}
