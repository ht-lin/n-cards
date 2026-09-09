<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Sharing\Application\Membership;

use App\Module\Sharing\Application\Membership\CardMembershipService;
use App\Module\Sharing\Domain\Entity\CardMember;
use App\Shared\Domain\Error\DomainException;
use App\Shared\Domain\Error\ErrorCode;
use App\Shared\Domain\Identity\Uuid;
use App\Tests\Double\Sharing\InMemoryCardMemberRepository;
use App\Tests\Double\Transaction\RecordingTransactionRunner;
use App\Tests\Double\Wallet\WalletEntities;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CardMembershipService::class)]
final class CardMembershipServiceTest extends TestCase
{
    private const CARD = 0xCA5D;
    private const OTHER_CARD = 0xCA5E;
    private const OWNER = 0xA11A;
    private const VIEWER = 0xB0B;
    private const STRANGER = 0xC0C0;

    private InMemoryCardMemberRepository $members;

    private RecordingTransactionRunner $transactions;

    private CardMembershipService $service;

    protected function setUp(): void
    {
        $this->members = new InMemoryCardMemberRepository();
        $this->transactions = new RecordingTransactionRunner();
        $this->service = new CardMembershipService($this->members, $this->transactions);
    }

    // ========================================================================
    // registerOwner —— 事务护栏
    // ========================================================================

    public function testRegisteringAnOwnerInsideATransactionWritesTheRow(): void
    {
        $this->transactions->run(function (): void {
            $this->service->registerOwner(self::id(self::CARD), self::id(self::OWNER), WalletEntities::now());
        });

        $rows = $this->members->all();

        self::assertCount(1, $rows);
        self::assertTrue($rows[0]->isOwner());
        self::assertTrue($rows[0]->isActive());
    }

    /**
     * ⚠️ 这是本卡最重要的一条单测。
     *
     * 非事务地调用 `registerOwner()` **没有任何症状** —— 卡建好了、成员行也建好了，
     * 功能测试全绿。直到某天一次部分失败在生产里留下一张没有 owner 行的孤儿卡，
     * 而那时已经没有信息能查出它是怎么来的。
     *
     * 护栏本身也需要一条用例，否则谁把那个 `if` 删掉，同样什么都不会红。
     */
    public function testRegisteringAnOwnerOutsideATransactionThrows(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/must be called inside/');

        $this->service->registerOwner(self::id(self::CARD), self::id(self::OWNER), WalletEntities::now());
    }

    public function testTheJoinedAtIsTheOneHandedIn(): void
    {
        // 由调用方传时钟读数进来，Sharing 自己不读 —— cards.created_at 与
        // card_members.joined_at 必须逐字相同（M2 的 change_log 排序会在意）。
        $now = WalletEntities::now();

        $this->transactions->run(function () use ($now): void {
            $this->service->registerOwner(self::id(self::CARD), self::id(self::OWNER), $now);
        });

        self::assertSame($now, $this->members->all()[0]->joinedAt());
    }

    /** 「一卡一 owner」由库层的部分唯一索引强制，替身复刻了它。 */
    public function testASecondOwnerOnTheSameCardIsRejected(): void
    {
        $this->transactions->run(function (): void {
            $this->service->registerOwner(self::id(self::CARD), self::id(self::OWNER), WalletEntities::now());
        });

        $this->expectException(DomainException::class);

        $this->transactions->run(function (): void {
            $this->service->registerOwner(self::id(self::CARD), self::id(self::STRANGER), WalletEntities::now());
        });
    }

    // ========================================================================
    // membershipsFor
    // ========================================================================

    public function testTheReaderTranslatesRoleAndCanEdit(): void
    {
        $this->seedOwner();

        $map = $this->service->membershipsFor(self::id(self::OWNER), [self::id(self::CARD)]);
        $membership = $map->for(self::id(self::CARD));

        self::assertNotNull($membership);
        self::assertSame('owner', $membership->role);
        // ⚠️ canEdit 在 Sharing 侧算好 —— Wallet 拿到的是 bool，不做角色比较。
        self::assertTrue($membership->canEdit);
    }

    public function testAViewerCannotEdit(): void
    {
        $this->members->save(CardMember::viewer(
            self::id(self::CARD),
            self::id(self::VIEWER),
            self::id(self::OWNER),
            WalletEntities::now(),
        ));

        $membership = $this->service
            ->membershipsFor(self::id(self::VIEWER), [self::id(self::CARD)])
            ->for(self::id(self::CARD));

        self::assertNotNull($membership);
        self::assertSame('viewer', $membership->role);
        self::assertFalse($membership->canEdit);
    }

    public function testANonMemberGetsNothingRatherThanAnException(): void
    {
        $this->seedOwner();

        $map = $this->service->membershipsFor(self::id(self::STRANGER), [self::id(self::CARD)]);

        // 缺席不是错误 —— 「缺席意味着什么」由调用方决定，见接口注释。
        self::assertNull($map->for(self::id(self::CARD)));
        self::assertSame([self::id(self::CARD)->toString()], $map->missingFrom([self::id(self::CARD)]));
    }

    public function testAMemberWhoLeftIsInvisible(): void
    {
        $member = CardMember::owner(self::id(self::CARD), self::id(self::OWNER), WalletEntities::now());
        $member->leave(WalletEntities::now());
        $this->members->save($member);

        $map = $this->service->membershipsFor(self::id(self::OWNER), [self::id(self::CARD)]);

        self::assertNull($map->for(self::id(self::CARD)));
    }

    public function testAnEmptyBatchYieldsAnEmptyMap(): void
    {
        self::assertSame([], $this->service->membershipsFor(self::id(self::OWNER), [])->missingFrom([]));
    }

    // ========================================================================
    // updatePlacement
    // ========================================================================

    public function testPlacementIsWrittenToTheCallersOwnRow(): void
    {
        $this->seedOwner();

        $this->service->updatePlacement(self::id(self::CARD), self::id(self::OWNER), 5, true);

        $membership = $this->service
            ->membershipsFor(self::id(self::OWNER), [self::id(self::CARD)])
            ->for(self::id(self::CARD));

        self::assertNotNull($membership);
        self::assertSame(5, $membership->sortOrder);
        self::assertTrue($membership->isPinned);
    }

    /**
     * T-110 的验收标准第二条：**两个成员各自的 placement 互不影响。**.
     */
    public function testTwoMembersPlacementsAreIndependent(): void
    {
        $this->seedOwner();
        $this->members->save(CardMember::viewer(
            self::id(self::CARD),
            self::id(self::VIEWER),
            self::id(self::OWNER),
            WalletEntities::now(),
        ));

        $this->service->updatePlacement(self::id(self::CARD), self::id(self::OWNER), 1, true);
        $this->service->updatePlacement(self::id(self::CARD), self::id(self::VIEWER), 99, false);

        $owner = $this->service->membershipsFor(self::id(self::OWNER), [self::id(self::CARD)])->for(self::id(self::CARD));
        $viewer = $this->service->membershipsFor(self::id(self::VIEWER), [self::id(self::CARD)])->for(self::id(self::CARD));

        self::assertNotNull($owner);
        self::assertNotNull($viewer);
        self::assertSame(1, $owner->sortOrder);
        self::assertTrue($owner->isPinned);
        self::assertSame(99, $viewer->sortOrder);
        self::assertFalse($viewer->isPinned);
    }

    /**
     * viewer **能**调这个端点 —— 这是他唯一的上行写入权利（§5.2）。
     * 拿 `insufficient_role` 挡住他是本卡最容易犯的错。
     */
    public function testAViewerMayPlaceTheCard(): void
    {
        $this->members->save(CardMember::viewer(
            self::id(self::CARD),
            self::id(self::VIEWER),
            self::id(self::OWNER),
            WalletEntities::now(),
        ));

        $this->service->updatePlacement(self::id(self::CARD), self::id(self::VIEWER), 3, true);

        $membership = $this->service
            ->membershipsFor(self::id(self::VIEWER), [self::id(self::CARD)])
            ->for(self::id(self::CARD));

        self::assertNotNull($membership);
        self::assertSame(3, $membership->sortOrder);
    }

    public function testANonMemberGetsNotAMember(): void
    {
        $this->seedOwner();

        try {
            $this->service->updatePlacement(self::id(self::CARD), self::id(self::STRANGER), 1, true);
            self::fail('Expected DomainException.');
        } catch (DomainException $e) {
            // ⚠️ not_a_member，**不是** insufficient_role：placement 对两种角色
            // 都开放，所以失败只可能是「你根本不在这张卡上」。契约给这个码的
            // 语义是「客户端应据此从本地删除该卡」。
            self::assertSame(ErrorCode::NotAMember, $e->errorCode());
            self::assertSame(403, $e->errorCode()->httpStatus());
        }
    }

    public function testAMemberWhoLeftCannotPlaceTheCard(): void
    {
        $member = CardMember::owner(self::id(self::CARD), self::id(self::OWNER), WalletEntities::now());
        $member->leave(WalletEntities::now());
        $this->members->save($member);

        $this->expectException(DomainException::class);

        // 墓碑行不算成员 —— 漏掉这一条就是 §7.2 的 T20（权限残留）。
        $this->service->updatePlacement(self::id(self::CARD), self::id(self::OWNER), 1, true);
    }

    public function testPlacingOneCardDoesNotTouchAnother(): void
    {
        $this->seedOwner();
        $this->members->save(CardMember::owner(self::id(self::OTHER_CARD), self::id(self::OWNER), WalletEntities::now()));

        $this->service->updatePlacement(self::id(self::CARD), self::id(self::OWNER), 5, true);

        $other = $this->service
            ->membershipsFor(self::id(self::OWNER), [self::id(self::OTHER_CARD)])
            ->for(self::id(self::OTHER_CARD));

        self::assertNotNull($other);
        self::assertSame(0, $other->sortOrder);
        self::assertFalse($other->isPinned);
    }

    /** placement 是单次写，**不需要**事务 —— 与 registerOwner 相反。 */
    public function testPlacementDoesNotRequireATransaction(): void
    {
        $this->seedOwner();

        $this->service->updatePlacement(self::id(self::CARD), self::id(self::OWNER), 1, false);

        self::assertSame(0, $this->transactions->runs());
    }

    private function seedOwner(): void
    {
        $this->members->save(CardMember::owner(self::id(self::CARD), self::id(self::OWNER), WalletEntities::now()));
    }

    private static function id(int $n): Uuid
    {
        return WalletEntities::id($n);
    }
}
