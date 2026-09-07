<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Identity\Domain\ValueObject;

use App\Module\Identity\Domain\ValueObject\Username;
use App\Module\Identity\Domain\ValueObject\UsernameRules;
use App\Shared\Domain\Error\DomainException;
use App\Shared\Domain\Limit\LimitEnforcer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * 规则集与**配置文件**的对账。
 *
 * `UsernameTest` 用一份手写的清单验行为；这里验那份手写清单确实就是
 * `config/packages/ncards_username.yaml` 里的东西 —— 两条合起来才让
 * 「改配置」与「改行为」不可能分叉。
 */
#[CoversClass(UsernameRules::class)]
final class UsernameRulesTest extends TestCase
{
    /**
     * §3.8 与任务卡 T-107（Q9）的清单，逐字。九个通用 + 三个德语场景。
     */
    private const SPEC = [
        'admin', 'support', 'ncards', 'help', 'root', 'system', 'info',
        'kontakt', 'datenschutz', 'impressum', 'hilfe', 'konto',
    ];

    /**
     * ⚠️ Q9 在 §17.5 里仍是**未决项**（决策人：产品，截止 M1 结束）。
     * 产品确认后要改的是 yaml 与本常量两处 —— 本条用例会逼着两处一起改。
     */
    public function testConfiguredListMatchesTheSpec(): void
    {
        self::assertSame(self::SPEC, self::configuredWords());
    }

    /**
     * ⚠️ **每个保留词自己必须是一个合法的 username。**.
     *
     * 不合法的保留词是**死代码**：`Username::fromInput()` 先做长度与字符集校验，
     * 再问保留词，所以一个像 `n-cards` 或 `ab` 这样的词永远匹配不到任何输入 ——
     * 它看起来在保护品牌，实际上一次都不会生效，而且没有任何测试会红。
     *
     * 这条是 Q9 定稿时最容易踩的坑：产品很自然会写出 `n-cards`（人读形态）。
     */
    #[DataProvider('configuredWordsProvider')]
    public function testEveryReservedWordWouldOtherwiseBeAValidUsername(string $word): void
    {
        $rules = new UsernameRules(self::limits(), []);

        // 用一份**空**保留词表构造，这样这个词唯一可能的失败原因就是长度或字符集。
        self::assertSame($word, Username::fromInput($word, $rules)->toString());
    }

    #[DataProvider('configuredWordsProvider')]
    public function testEveryReservedWordIsRejectedWithTheRealList(string $word): void
    {
        $this->expectException(DomainException::class);

        Username::fromInput($word, self::rules());
    }

    /**
     * 词表本身必须已经是归一化形态 —— `isReserved()` 拿到的是归一化后的值，
     * 一个写成 `Admin` 的保留词永远匹配不上。
     */
    #[DataProvider('configuredWordsProvider')]
    public function testEveryReservedWordIsAlreadyNormalised(string $word): void
    {
        self::assertSame(strtolower(trim($word)), $word);
    }

    public function testHasNoDuplicates(): void
    {
        $words = self::configuredWords();

        self::assertSame(array_values(array_unique($words)), $words);
    }

    public function testIsReservedMatchesExactlyAndNotBySubstring(): void
    {
        $rules = self::rules();

        self::assertTrue($rules->isReserved('admin'));
        // 前缀 / 后缀 / 子串都**不**算 —— 论证在 ncards_username.yaml 的抬头。
        self::assertFalse($rules->isReserved('admin_anna'));
        self::assertFalse($rules->isReserved('not_admin'));
        self::assertFalse($rules->isReserved('helpful'));
        // 未归一化的输入匹配不上，这是 isReserved() 的前置条件而不是 bug。
        self::assertFalse($rules->isReserved('Admin'));
    }

    /**
     * 长度与正则是从 {@see LimitEnforcer} 转手的，不是第二份配置 ——
     * 改 §7.5 的数字时只该改一处。
     */
    public function testLengthAndPatternComeFromTheLimitEnforcer(): void
    {
        $limits = self::limits();
        $rules = new UsernameRules($limits, []);

        self::assertSame($limits->usernameMinChars(), $rules->minChars());
        self::assertSame($limits->usernameMaxChars(), $rules->maxChars());
        self::assertSame($limits->usernamePattern(), $rules->pattern());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function configuredWordsProvider(): iterable
    {
        foreach (self::configuredWords() as $word) {
            yield $word => [$word];
        }
    }

    /**
     * @return list<string>
     */
    private static function configuredWords(): array
    {
        $path = __DIR__.'/../../../../../../config/packages/ncards_username.yaml';
        self::assertFileExists($path);

        /** @var array{parameters?: array{'ncards.username.reserved_words'?: list<string>}} $parsed */
        $parsed = Yaml::parseFile($path);

        return $parsed['parameters']['ncards.username.reserved_words'] ?? [];
    }

    private static function rules(): UsernameRules
    {
        return new UsernameRules(self::limits(), self::configuredWords());
    }

    private static function limits(): LimitEnforcer
    {
        return new LimitEnforcer(500, 20, 500, 50, 100, 1024, 2000, 100, 3, 20, '^[a-z0-9_]{3,20}$', 10);
    }
}
