<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Domain\Limit;

use App\Shared\Domain\Error\DomainException;
use App\Shared\Domain\Error\ErrorCode;
use App\Shared\Domain\Limit\LimitEnforcer;
use App\Shared\Domain\Limit\LimitUnit;
use App\Shared\Domain\Limit\SystemLimit;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * T-006 验收标准：「单测覆盖**每个限额常量的边界值**」。
 *
 * ============================================================================
 * 两件事，不要合并
 * ============================================================================
 * 1. **配置与 §7.5 一致**（{@see testConfiguredValuesMatchTheSpec}）：
 *    黄金对照表照 §7.5 的表逐行抄写，与 `config/packages/ncards_limits.yaml`
 *    比对。改数字而不改规格（或反过来）→ 红。
 * 2. **边界行为正确**（499/500/501 那一组）：用的是**真实配置值**而不是
 *    随手编的小数字。两者用不同的数字的话，第一条测的是配置、第二条测的是
 *    一个虚构世界，谁都不保证生产里的 500 真的在第 501 张卡上拒绝。
 */
#[CoversClass(LimitEnforcer::class)]
#[CoversClass(SystemLimit::class)]
#[CoversClass(LimitUnit::class)]
final class LimitEnforcerTest extends TestCase
{
    /**
     * §7.5 限额表的逐行抄写。**改这里等于改规格**，PR 里要说明理由。
     *
     * @var array<string, int>
     */
    private const SPEC = [
        'cards_per_user' => 500,
        'members_per_card' => 20,
        'friends_per_user' => 500,
        'friend_requests_per_day' => 50,
        'share_invites_per_day' => 100,
        'barcode_payload_bytes' => 1024,
        'note_chars' => 2000,
        'title_chars' => 100,
        'username_min_chars' => 3,
        'username_max_chars' => 20,
    ];

    private const USERNAME_PATTERN = '^[a-z0-9_]{3,20}$';

    // ========================================================================
    // 配置 ↔ 规格
    // ========================================================================

    /**
     * `config/packages/ncards_limits.yaml` 与 §7.5 的表逐行一致。
     */
    public function testConfiguredValuesMatchTheSpec(): void
    {
        $parameters = self::configuredParameters();

        foreach (self::SPEC as $name => $expected) {
            self::assertSame(
                $expected,
                $parameters['ncards.limits.'.$name] ?? null,
                \sprintf('ncards_limits.yaml 的 %s 与 §7.5 不符', $name),
            );
        }

        self::assertSame(self::USERNAME_PATTERN, $parameters['ncards.limits.username_pattern'] ?? null);
    }

    /**
     * 每个 {@see SystemLimit} case 都在配置里有对应的 parameter。
     *
     * 新增一个 case 而忘了配 yaml 的话，容器会因为 LimitEnforcer 少一个构造参数
     * 而拒绝编译 —— 但那是 Integration 层才发现。这里在单测层就红。
     */
    public function testEverySystemLimitHasAConfiguredValue(): void
    {
        $parameters = self::configuredParameters();

        foreach (SystemLimit::cases() as $limit) {
            self::assertArrayHasKey(
                'ncards.limits.'.$limit->value,
                $parameters,
                \sprintf('SystemLimit::%s 在 ncards_limits.yaml 里没有对应的 parameter', $limit->name),
            );
        }
    }

    // ========================================================================
    // 边界值 —— 验收标准的正题
    // ========================================================================

    /**
     * 计数类：`max - 1` 个存量还能再加一个（那是第 `max` 个），`max` 个不能。
     *
     * 卡数就是任务书点名的 499 / 500 / 501 那一组。
     */
    #[DataProvider('countLimits')]
    public function testCountLimitAcceptsExactlyUpToItsMaximum(SystemLimit $limit): void
    {
        $enforcer = self::enforcer();
        $max = $enforcer->max($limit);

        // max - 1 个存量 → 放行（新增后正好是 max 个）
        self::assertTrue(self::allows(static fn () => $enforcer->enforceCanAdd($limit, $max - 1)));

        // max 个存量 → 拒绝
        self::assertLimitExceeded($limit, static fn () => $enforcer->enforceCanAdd($limit, $max));

        // max + 1 个存量（不该发生，但真发生了也必须拒）
        self::assertLimitExceeded($limit, static fn () => $enforcer->enforceCanAdd($limit, $max + 1));
    }

    /**
     * 一次加多个也要在总量上判定，不是只看「加 1」。
     */
    public function testCountLimitConsidersHowManyAreBeingAdded(): void
    {
        $enforcer = self::enforcer();
        $max = $enforcer->max(SystemLimit::CardsPerUser);

        self::assertTrue(self::allows(
            static fn () => $enforcer->enforceCanAdd(SystemLimit::CardsPerUser, $max - 10, 10),
        ));

        self::assertLimitExceeded(
            SystemLimit::CardsPerUser,
            static fn () => $enforcer->enforceCanAdd(SystemLimit::CardsPerUser, $max - 10, 11),
        );
    }

    /**
     * 长度类：正好 `max` 合法，`max + 1` 不合法。
     */
    #[DataProvider('lengthLimits')]
    public function testLengthLimitAcceptsExactlyItsMaximum(SystemLimit $limit): void
    {
        $enforcer = self::enforcer();
        $max = $enforcer->max($limit);

        self::assertTrue(self::allows(static fn () => $enforcer->enforceLength($limit, str_repeat('a', $max - 1))));
        self::assertTrue(self::allows(static fn () => $enforcer->enforceLength($limit, str_repeat('a', $max))));

        self::assertLimitExceeded($limit, static fn () => $enforcer->enforceLength($limit, str_repeat('a', $max + 1)));
    }

    /**
     * ⚠️ payload 按**字节**、note/title 按**字符**。
     *
     * 这条测试是那个区分的唯一强制点。一个 512 个「ä」的 note 是 1024 字节但
     * 只有 512 字符 —— 按字节算的话，德语用户的备注会在半程被拒。
     */
    public function testBytesAndCharactersAreNotTheSameThing(): void
    {
        $enforcer = self::enforcer();

        // 「ä」在 UTF-8 里是 2 字节。
        $noteMax = $enforcer->max(SystemLimit::NoteChars);
        self::assertTrue(
            self::allows(static fn () => $enforcer->enforceLength(SystemLimit::NoteChars, str_repeat('ä', $noteMax))),
            'note 按字符计：2000 个变音符号合法（哪怕是 4000 字节）',
        );

        self::assertLimitExceeded(
            SystemLimit::NoteChars,
            static fn () => $enforcer->enforceLength(SystemLimit::NoteChars, str_repeat('ä', $noteMax + 1)),
        );

        // payload 按字节计：1024 个「ä」是 2048 字节，超限。
        $payloadMax = $enforcer->max(SystemLimit::BarcodePayloadBytes);
        self::assertTrue(self::allows(
            static fn () => $enforcer->enforceLength(SystemLimit::BarcodePayloadBytes, str_repeat('ä', intdiv($payloadMax, 2))),
        ));

        self::assertLimitExceeded(
            SystemLimit::BarcodePayloadBytes,
            static fn () => $enforcer->enforceLength(SystemLimit::BarcodePayloadBytes, str_repeat('ä', $payloadMax)),
        );
    }

    /**
     * ⚠️ payload 是二进制安全的：barcode 载荷可能根本不是合法 UTF-8。
     *
     * `mb_strlen` 对非法 UTF-8 的行为是「按替换字符计数」，会给出一个既不是
     * 字节数也不是字符数的第三个答案。
     */
    public function testBarcodePayloadIsCountedInRawBytes(): void
    {
        $enforcer = self::enforcer();
        $max = $enforcer->max(SystemLimit::BarcodePayloadBytes);

        $binary = str_repeat("\xFF\xFE", intdiv($max, 2));
        self::assertSame($max, \strlen($binary));

        self::assertTrue(
            self::allows(static fn () => $enforcer->enforceLength(SystemLimit::BarcodePayloadBytes, $binary)),
            '1024 个原始字节合法，哪怕它不是合法 UTF-8',
        );

        self::assertLimitExceeded(
            SystemLimit::BarcodePayloadBytes,
            static fn () => $enforcer->enforceLength(SystemLimit::BarcodePayloadBytes, $binary."\xFF"),
        );
    }

    // ========================================================================
    // 用错方法要立刻炸，而不是静默给一个别的答案
    // ========================================================================

    public function testCountLimitRejectsTheLengthApi(): void
    {
        $this->expectException(\LogicException::class);

        self::enforcer()->enforceLength(SystemLimit::CardsPerUser, 'whatever');
    }

    public function testLengthLimitRejectsTheCountApi(): void
    {
        $this->expectException(\LogicException::class);

        self::enforcer()->enforceCanAdd(SystemLimit::TitleChars, 1);
    }

    // ========================================================================
    // username：只暴露常量，不抛 limit_exceeded
    // ========================================================================

    /**
     * §7.5 的 username 行不走 `limit_exceeded` —— 它有自己的 `422 username_invalid`。
     * 这里只断言常量透传正确，供 T-107 构造值对象。
     */
    public function testUsernameConstantsAreExposedButNotEnforcedAsLimits(): void
    {
        $enforcer = self::enforcer();

        self::assertSame(self::SPEC['username_min_chars'], $enforcer->usernameMinChars());
        self::assertSame(self::SPEC['username_max_chars'], $enforcer->usernameMaxChars());
        self::assertSame(self::USERNAME_PATTERN, $enforcer->usernamePattern());

        // 正则不带定界符（Android 侧 T-010 要用同一个字符串），但加上定界符后可用。
        self::assertMatchesRegularExpression('/'.$enforcer->usernamePattern().'/', 'anna_b');
        self::assertDoesNotMatchRegularExpression('/'.$enforcer->usernamePattern().'/', 'Anna_B');
        self::assertDoesNotMatchRegularExpression('/'.$enforcer->usernamePattern().'/', 'ab');
        self::assertDoesNotMatchRegularExpression('/'.$enforcer->usernamePattern().'/', str_repeat('a', 21));

        // username 不在可强制的限额枚举里 —— 这是刻意的，见 SystemLimit 的类注释。
        $enforceable = array_map(static fn (SystemLimit $l): string => $l->value, SystemLimit::cases());

        self::assertNotContains('username_min_chars', $enforceable);
        self::assertNotContains('username_max_chars', $enforceable);
        self::assertNotContains('username_pattern', $enforceable);
    }

    // ========================================================================
    // 错误形状
    // ========================================================================

    /**
     * 超限抛的是 `422 limit_exceeded`，且 detail 里带限额名与上限值 ——
     * 客户端据此展示「你最多能有 500 张卡」而不是一句「出错了」。
     */
    public function testTheExceptionCarriesTheLimitNameAndMaximum(): void
    {
        try {
            self::enforcer()->enforceCanAdd(SystemLimit::CardsPerUser, self::SPEC['cards_per_user']);
            self::fail('应该抛 limit_exceeded');
        } catch (DomainException $e) {
            self::assertSame(ErrorCode::LimitExceeded, $e->errorCode());
            self::assertSame(422, $e->errorCode()->httpStatus());
            self::assertStringContainsString('cards_per_user', $e->detail());
            self::assertStringContainsString('500', $e->detail());
            // 限额不是字段级校验失败，errors[] 应为空。
            self::assertSame([], $e->fieldErrors());
        }
    }

    // ========================================================================
    // 夹具
    // ========================================================================

    /**
     * @return iterable<string, array{SystemLimit}>
     */
    public static function countLimits(): iterable
    {
        foreach (SystemLimit::cases() as $limit) {
            if (LimitUnit::Count === $limit->unit()) {
                yield $limit->value => [$limit];
            }
        }
    }

    /**
     * @return iterable<string, array{SystemLimit}>
     */
    public static function lengthLimits(): iterable
    {
        foreach (SystemLimit::cases() as $limit) {
            if (LimitUnit::Count !== $limit->unit()) {
                yield $limit->value => [$limit];
            }
        }
    }

    /**
     * 用**真实配置值**构造，而不是随手编的小数字 —— 见类注释。
     */
    private static function enforcer(): LimitEnforcer
    {
        return new LimitEnforcer(
            self::SPEC['cards_per_user'],
            self::SPEC['members_per_card'],
            self::SPEC['friends_per_user'],
            self::SPEC['friend_requests_per_day'],
            self::SPEC['share_invites_per_day'],
            self::SPEC['barcode_payload_bytes'],
            self::SPEC['note_chars'],
            self::SPEC['title_chars'],
            self::SPEC['username_min_chars'],
            self::SPEC['username_max_chars'],
            self::USERNAME_PATTERN,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function configuredParameters(): array
    {
        $path = __DIR__.'/../../../../../config/packages/ncards_limits.yaml';
        self::assertFileExists($path);

        /** @var array{parameters?: array<string, mixed>} $parsed */
        $parsed = Yaml::parseFile($path);

        return $parsed['parameters'] ?? [];
    }

    /**
     * 「这次调用没有超限」—— 用返回值而不是 `assertTrue(true)`，
     * 后者在 phpstan-phpunit 下是一条 always-true 的死断言。
     */
    private static function allows(callable $act): bool
    {
        try {
            $act();
        } catch (DomainException) {
            return false;
        }

        return true;
    }

    private static function assertLimitExceeded(SystemLimit $limit, callable $act): void
    {
        try {
            $act();
        } catch (DomainException $e) {
            self::assertSame(ErrorCode::LimitExceeded, $e->errorCode());
            self::assertStringContainsString($limit->value, $e->detail());

            return;
        }

        self::fail(\sprintf('%s 超限时应该抛 limit_exceeded', $limit->value));
    }
}
