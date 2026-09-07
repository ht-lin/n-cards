<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Identity\Domain\ValueObject;

use App\Module\Identity\Domain\ValueObject\Username;
use App\Module\Identity\Domain\ValueObject\UsernameRules;
use App\Shared\Domain\Error\DomainException;
use App\Shared\Domain\Error\ErrorCode;
use App\Shared\Domain\Limit\LimitEnforcer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * §13.4-6「username 不变性测试」里归 Domain 的那一半：归一化等价、字符集、
 * 长度边界、保留词。端到端那一半在 `tests/Api/UsernameEndpointTest`。
 *
 * ⚠️ 规则用**真实配置值**构造（3 / 20 / `^[a-z0-9_]{3,20}$` / 12 个保留词），
 * 不是随手编的小数字 —— 与 `LimitEnforcerTest` 同一个做法。
 * 编一套小的会让「边界值到底是几」这件事在测试里失去意义。
 */
#[CoversClass(Username::class)]
final class UsernameTest extends TestCase
{
    /**
     * `config/packages/ncards_username.yaml` 的那份清单，逐字。
     * `UsernameRulesTest` 负责断言它与配置文件一致 —— 这里只用它。
     */
    private const RESERVED = [
        'admin', 'support', 'ncards', 'help', 'root', 'system', 'info',
        'kontakt', 'datenschutz', 'impressum', 'hilfe', 'konto',
    ];

    /**
     * `Username::reject()` 能发出的**全部**文案，逐字。
     *
     * 它们必须是固定串：`detail` 会进日志与 Sentry，而 §3.8-C4 的整套修补
     * （`ApiProblemExceptionListener` 把 `instance` 从 `getRequestUri()` 换成
     * `getPathInfo()`）就是为了不让 username 出现在那里。
     * 加一条新的拒绝理由时，这里也要加 —— 那正是重新想一遍「这句话里有没有
     * 用户输入」的时刻。
     */
    private const FIXED_DETAILS = [
        'The username must be between 3 and 20 characters long.',
        'The username may only contain lowercase letters, digits and underscores.',
        'The username is reserved and cannot be used.',
    ];

    // ========================================================================
    // 归一化 —— 验收标准的正题
    // ========================================================================

    /**
     * §13.4-6 逐字写的那一条：`Anna_B ` 与 `anna_b` 是**同一个**名字。
     *
     * 这是整个社交检索路径成立的前提：用户不会记得自己当初用的大小写，
     * 而 `GET /v1/users/lookup` 是纯等值查询。
     */
    #[DataProvider('equivalentInputs')]
    public function testNormalisesToTheSameValue(string $raw): void
    {
        self::assertSame('anna_b', Username::fromInput($raw, self::rules())->toString());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function equivalentInputs(): iterable
    {
        yield 'already normalised' => ['anna_b'];
        yield 'trailing space（验收标准逐字写的那个）' => ['Anna_B '];
        yield 'leading space' => [' anna_b'];
        yield 'both sides' => ["  Anna_B\t"];
        yield 'all caps' => ['ANNA_B'];
        yield 'mixed case' => ['aNnA_b'];
        yield 'newline（粘贴时常带上）' => ["anna_b\n"];
    }

    public function testEqualsComparesTheNormalisedValue(): void
    {
        $rules = self::rules();

        self::assertTrue(
            Username::fromInput('Anna_B ', $rules)->equals(Username::fromInput('anna_b', $rules)),
        );
        self::assertFalse(
            Username::fromInput('anna_b', $rules)->equals(Username::fromInput('anna_c', $rules)),
        );
    }

    /**
     * ⚠️ §3.8 点名的土耳其语坑：`I → ı`。
     *
     * PHP 8.2 起 `strtolower()` 不再受 locale 影响，所以这条在任何机器上都成立。
     * 用 `mb_strtolower()` 实现的话 `İ`（U+0130）会折叠成 `i̇`（两个码位），
     * 那个输入就会**通过**长度检查却带着一个组合重音符 —— 本条会红。
     */
    public function testLowercasingIsAsciiOnlyAndLocaleIndependent(): void
    {
        $previous = setlocale(LC_CTYPE, '0');
        // 土耳其语 locale 在 CI 上多半装不了；装得上时这条才真的在验那件事，
        // 装不上也无妨 —— PHP 8.2 之后 strtolower() 根本不看 locale。
        setlocale(LC_CTYPE, 'tr_TR.UTF-8', 'tr_TR', 'tr');

        try {
            self::assertSame('anna_bi', Username::fromInput('ANNA_BI', self::rules())->toString());
        } finally {
            setlocale(LC_CTYPE, false === $previous ? 'C' : $previous);
        }
    }

    // ========================================================================
    // 字符集与长度 —— 全部 422 username_invalid
    // ========================================================================

    #[DataProvider('invalidInputs')]
    public function testRejectsInvalidInput(string $raw): void
    {
        $this->expectExceptionOfCode(ErrorCode::UsernameInvalid, $raw);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidInputs(): iterable
    {
        // ---- 长度（3–20）
        yield 'empty' => [''];
        yield 'whitespace only' => ['   '];
        yield 'two chars（下边界减一）' => ['ab'];
        yield 'twenty-one chars（上边界加一）' => [str_repeat('a', 21)];
        yield 'trim 之后才变短' => ['  ab  '];

        // ---- 字符集
        yield 'hyphen' => ['anna-b'];
        yield 'dot' => ['anna.b'];
        yield 'at sign（防止有人直接填邮箱）' => ['anna@example.de'];
        yield 'space in the middle' => ['anna b'];
        yield 'slash' => ['anna/b'];
        yield 'emoji' => ['anna_🎉'];
        yield 'umlaut（德语用户的第一反应）' => ['anna_müller'];
        yield 'cyrillic а（同形异义，§3.8 的原话）' => ['аnna_b'];
        yield 'turkish dotted capital I' => ['İnna_b'];
        yield 'zero-width space（trim 吃不掉）' => ["anna\u{200B}_b"];
        yield 'non-breaking space（trim 吃不掉）' => ["anna\u{00A0}b"];
        yield 'null byte' => ["anna\0b"];
        yield 'newline in the middle' => ["anna\nb"];

        // ---- 保留词
        foreach (self::RESERVED as $word) {
            yield 'reserved: '.$word => [$word];
        }

        yield 'reserved, mixed case（归一化之后才撞上）' => ['Admin'];
        yield 'reserved, padded' => [' support '];
    }

    /**
     * 边界值本身必须**通过** —— 只测「拒绝」的话，一个把范围写成 4–19 的实现
     * 也会全绿。
     */
    #[DataProvider('validInputs')]
    public function testAcceptsValidInput(string $raw, string $expected): void
    {
        self::assertSame($expected, Username::fromInput($raw, self::rules())->toString());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function validInputs(): iterable
    {
        yield 'three chars（下边界）' => ['abc', 'abc'];
        yield 'twenty chars（上边界）' => [str_repeat('a', 20), str_repeat('a', 20)];
        yield 'digits only' => ['123', '123'];
        yield 'underscores only' => ['___', '___'];
        yield 'leading underscore' => ['_anna', '_anna'];
        yield 'trailing digit' => ['anna2', 'anna2'];
        // 保留词只做**精确**匹配，不做前缀/子串 —— 见 ncards_username.yaml 的抬头。
        yield 'reserved word as a prefix' => ['systematic_anna', 'systematic_anna'];
        yield 'reserved word as a suffix' => ['not_admin', 'not_admin'];
        yield 'reserved word as a substring' => ['helpful', 'helpful'];
    }

    // ========================================================================
    // 不泄露输入
    // ========================================================================

    /**
     * ⚠️ `detail` 会进日志与 Sentry，而 §3.8-C4 的整套修补就是为了不让 username
     * 出现在那里（`ApiProblemExceptionListener` 为此把 `instance` 从
     * `getRequestUri()` 换成了 `getPathInfo()`）。在异常文案里拼回去等于绕过它。
     */
    #[DataProvider('invalidInputs')]
    public function testNeverEchoesTheInputInTheDetail(string $raw): void
    {
        try {
            Username::fromInput($raw, self::rules());
            self::fail('Expected DomainException.');
        } catch (DomainException $e) {
            // 断言 detail 是三条**固定串**之一，而不是「碰巧没含这次的输入」——
            // 后者对空串之类的用例是句废话，而且一个把输入拼在句尾的实现
            // 也能骗过大部分数据。三选一是这条性质唯一说得死的表达方式。
            self::assertContains($e->detail(), self::FIXED_DETAILS);
        }
    }

    private function expectExceptionOfCode(ErrorCode $expected, string $raw): void
    {
        try {
            Username::fromInput($raw, self::rules());
            self::fail(\sprintf('Expected DomainException for %s.', var_export($raw, true)));
        } catch (DomainException $e) {
            self::assertSame($expected, $e->errorCode());
            self::assertSame(422, $e->errorCode()->httpStatus());
        }
    }

    /**
     * 用 §7.5 的真实值构造，见类注释。
     */
    private static function rules(): UsernameRules
    {
        return new UsernameRules(
            new LimitEnforcer(500, 20, 500, 50, 100, 1024, 2000, 100, 3, 20, '^[a-z0-9_]{3,20}$', 10),
            self::RESERVED,
        );
    }
}
