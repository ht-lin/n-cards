<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Sharing\Domain\Entity;

use App\Module\Sharing\Domain\Entity\CardMember;
use App\Module\Sharing\Domain\ValueObject\CardRole;
use App\Shared\Domain\Identity\Uuid;
use App\Tests\Double\Wallet\WalletEntities;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CardMember::class)]
final class CardMemberTest extends TestCase
{
    private const CARD = 0xCA5D;
    private const OWNER = 0xA11A;
    private const VIEWER = 0xB0B;

    // ========================================================================
    // 两个命名构造器
    // ========================================================================

    public function testAnOwnerRowStartsUnpinnedAtSortOrderZeroWithNoInviter(): void
    {
        $joinedAt = WalletEntities::now();

        $member = CardMember::owner(self::id(self::CARD), self::id(self::OWNER), $joinedAt);

        self::assertTrue($member->cardId()->equals(self::id(self::CARD)));
        self::assertTrue($member->userId()->equals(self::id(self::OWNER)));
        self::assertSame(CardRole::Owner, $member->role());
        self::assertTrue($member->isOwner());
        self::assertSame(0, $member->sortOrder());
        self::assertFalse($member->isPinned());
        self::assertSame($joinedAt, $member->joinedAt());

        // owner 不是被谁加进来的 —— §17.1 那一列可空正是为了这一格。
        self::assertNull($member->addedBy());
    }

    public function testAViewerRowCarriesTheInviter(): void
    {
        $member = CardMember::viewer(
            self::id(self::CARD),
            self::id(self::VIEWER),
            self::id(self::OWNER),
            WalletEntities::now(),
        );

        self::assertSame(CardRole::Viewer, $member->role());
        self::assertFalse($member->isOwner());
        self::assertNotNull($member->addedBy());
        self::assertTrue($member->addedBy()->equals(self::id(self::OWNER)));
    }

    // ========================================================================
    // placement
    // ========================================================================

    public function testPlacementIsWritable(): void
    {
        $member = self::owner();

        $member->place(7, true);

        self::assertSame(7, $member->sortOrder());
        self::assertTrue($member->isPinned());
    }

    /**
     * ⚠️ placement 是**每成员私有**的（§5.2）—— 这条用例是那句话的可执行版本。
     * 同一张卡的两行互不影响，这正是 T-110 验收标准的第二条在领域层的对应物。
     */
    public function testTwoMembersOfTheSameCardPlaceItIndependently(): void
    {
        $owner = self::owner();
        $viewer = CardMember::viewer(
            self::id(self::CARD),
            self::id(self::VIEWER),
            self::id(self::OWNER),
            WalletEntities::now(),
        );

        $owner->place(1, true);
        $viewer->place(99, false);

        self::assertSame(1, $owner->sortOrder());
        self::assertTrue($owner->isPinned());
        self::assertSame(99, $viewer->sortOrder());
        self::assertFalse($viewer->isPinned());
    }

    /** 负数是合法的 —— 服务端对 `sort_order` 没有任何语义（客户端自己排）。 */
    public function testANegativeSortOrderIsAccepted(): void
    {
        $member = self::owner();

        $member->place(-1, false);

        self::assertSame(-1, $member->sortOrder());
    }

    // ========================================================================
    // 墓碑
    // ========================================================================

    public function testANewMemberIsActive(): void
    {
        self::assertTrue(self::owner()->isActive());
        self::assertNull(self::owner()->leftAt());
    }

    public function testLeavingWritesTheTombstone(): void
    {
        $member = self::owner();
        $at = WalletEntities::now();

        $member->leave($at);

        self::assertFalse($member->isActive());
        self::assertSame($at, $member->leftAt());
    }

    /**
     * ⚠️ 幂等：重置会把「什么时候失去访问」这个事实改掉，而那是 T-201 的
     * audience 计算要用的。口径同 `Card::softDelete()`。
     */
    public function testLeavingTwiceDoesNotMoveTheTombstone(): void
    {
        $member = self::owner();
        $first = WalletEntities::now();

        $member->leave($first);
        $member->leave($first->modify('+1 day'));

        self::assertSame($first, $member->leftAt());
    }

    private static function owner(): CardMember
    {
        return CardMember::owner(self::id(self::CARD), self::id(self::OWNER), WalletEntities::now());
    }

    private static function id(int $n): Uuid
    {
        return WalletEntities::id($n);
    }
}
