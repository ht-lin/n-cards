<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Sharing\Application\Dto;

use App\Module\Sharing\Application\Dto\CardMembership;
use App\Module\Sharing\Application\Dto\CardMembershipMap;
use App\Shared\Domain\Identity\Uuid;
use App\Tests\Double\Wallet\WalletEntities;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * {@see CardMembershipMap} 自己的用例（T-110）。
 *
 * 它在 `CardMembershipServiceTest` 里已经被间接走过，但那里断言的是**服务**的
 * 行为。这个类有自己的逻辑值得直接钉：`missingFrom()` 要去重、要保序，
 * 而那两条在服务那一层看不出来（服务传进去的 cardId 天然不重复）。
 */
#[CoversClass(CardMembershipMap::class)]
final class CardMembershipMapTest extends TestCase
{
    private const A = 0xAAA;
    private const B = 0xBBB;
    private const C = 0xCCC;

    public function testAKnownCardComesBack(): void
    {
        $map = CardMembershipMap::of([self::id(self::A)->toString() => self::membership()]);

        $found = $map->for(self::id(self::A));

        self::assertNotNull($found);
        self::assertSame('owner', $found->role);
    }

    /** 缺席是 `null`，不是异常 —— 「缺席意味着什么」由调用方决定。 */
    public function testAnUnknownCardIsNull(): void
    {
        $map = CardMembershipMap::of([self::id(self::A)->toString() => self::membership()]);

        self::assertNull($map->for(self::id(self::B)));
    }

    public function testAnEmptyMapFindsNothing(): void
    {
        self::assertNull(CardMembershipMap::empty()->for(self::id(self::A)));
    }

    public function testNothingIsMissingWhenEverythingIsPresent(): void
    {
        $map = CardMembershipMap::of([
            self::id(self::A)->toString() => self::membership(),
            self::id(self::B)->toString() => self::membership(),
        ]);

        self::assertSame([], $map->missingFrom([self::id(self::A), self::id(self::B)]));
    }

    public function testMissingCardsAreReportedInInputOrder(): void
    {
        $map = CardMembershipMap::of([self::id(self::B)->toString() => self::membership()]);

        self::assertSame(
            [self::id(self::A)->toString(), self::id(self::C)->toString()],
            $map->missingFrom([self::id(self::A), self::id(self::B), self::id(self::C)]),
        );
    }

    /**
     * ⚠️ 去重：`CardMembershipReaderInterface` 的入参**允许重复**
     * （「可含重复；结果按 card_id 天然去重」），所以缺失清单也不该把同一个
     * id 报两遍 —— `CardViewAssembler::assertComplete()` 的错误信息会直接
     * 把这个列表打给运维看。
     */
    public function testMissingCardsAreDeduplicated(): void
    {
        $map = CardMembershipMap::empty();

        self::assertSame(
            [self::id(self::A)->toString()],
            $map->missingFrom([self::id(self::A), self::id(self::A)]),
        );
    }

    public function testAnEmptyProbeFindsNothingMissing(): void
    {
        self::assertSame([], CardMembershipMap::empty()->missingFrom([]));
    }

    private static function membership(): CardMembership
    {
        return new CardMembership('owner', true, 0, false);
    }

    private static function id(int $n): Uuid
    {
        return WalletEntities::id($n);
    }
}
