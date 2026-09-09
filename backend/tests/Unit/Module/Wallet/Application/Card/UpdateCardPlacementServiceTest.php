<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Wallet\Application\Card;

use App\Module\Wallet\Application\Card\CardPlacementPayload;
use App\Module\Wallet\Application\Card\UpdateCardPlacementService;
use App\Shared\Domain\Error\DomainException;
use App\Shared\Domain\Error\ErrorCode;
use App\Shared\Domain\Identity\Uuid;
use App\Tests\Double\Wallet\WalletEntities;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(UpdateCardPlacementService::class)]
final class UpdateCardPlacementServiceTest extends TestCase
{
    private const OWNER = 0xA11A;
    private const STRANGER = 0xC0C0;

    public function testPlacementComesBackOnTheCardView(): void
    {
        $card = WalletEntities::card(ownerId: self::id(self::OWNER));
        $harness = new WalletServiceHarness($card);

        $view = $harness->placement()->update(
            WalletServiceHarness::auth(self::id(self::OWNER)),
            $card->id(),
            new CardPlacementPayload(5, true),
        );

        self::assertSame(5, $view->sortOrder);
        self::assertTrue($view->isPinned);
        self::assertSame('owner', $view->myRole);
        self::assertTrue($view->canEdit);
    }

    /**
     * ⚠️ 契约逐字要求 placement **不递增卡的 revision** —— 它改的不是这张卡。
     *
     * 这在实现里是结构性成立的（本服务一个 `Card` 的 setter 都不调），
     * 但它是那种「有人顺手加一行 `$card->touch()` 就没了」的保证，
     * 所以钉一条。
     */
    public function testTheCardRevisionDoesNotMove(): void
    {
        $card = WalletEntities::card(ownerId: self::id(self::OWNER));
        $harness = new WalletServiceHarness($card);
        $before = $card->revision();

        $view = $harness->placement()->update(
            WalletServiceHarness::auth(self::id(self::OWNER)),
            $card->id(),
            new CardPlacementPayload(5, true),
        );

        self::assertSame($before, $view->revision);
        self::assertSame($before, $card->revision());
    }

    public function testTheUpdatedAtDoesNotMoveEither(): void
    {
        $card = WalletEntities::card(ownerId: self::id(self::OWNER));
        $harness = new WalletServiceHarness($card);
        $before = $card->updatedAt();

        $harness->placement()->update(
            WalletServiceHarness::auth(self::id(self::OWNER)),
            $card->id(),
            new CardPlacementPayload(5, true),
        );

        self::assertSame($before, $card->updatedAt());
    }

    // ========================================================================
    // 检查顺序：404 早于 403
    // ========================================================================

    public function testAnUnknownCardIsNotFound(): void
    {
        $harness = new WalletServiceHarness();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('No such card.');

        $harness->placement()->update(
            WalletServiceHarness::auth(self::id(self::OWNER)),
            WalletEntities::id(0xDEAD),
            new CardPlacementPayload(0, false),
        );
    }

    /**
     * 软删的卡是 404，不是 200。
     *
     * ⚠️ 这一条不是理论上的：T-110 的迁移**刻意**给软删的卡也回填了 owner
     * 成员行（§5.2 的墓碑 audience 需要它）。所以成员行是在的 ——
     * 只有「先判卡」这个顺序才能挡住对一张已删除的卡返回 200。
     */
    public function testASoftDeletedCardIsNotFound(): void
    {
        $card = WalletEntities::card(ownerId: self::id(self::OWNER));
        $card->softDelete(WalletEntities::now());

        $harness = new WalletServiceHarness($card);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('No such card.');

        $harness->placement()->update(
            WalletServiceHarness::auth(self::id(self::OWNER)),
            $card->id(),
            new CardPlacementPayload(0, false),
        );
    }

    /**
     * ⚠️ 一个**不存在**的卡 id 必须是 404 而不是 403：403 会告诉调用者
     * 「这张卡存在，只是你不在上面」，而他连它存不存在都不该知道。
     */
    public function testAnUnknownCardIsNotFoundEvenForANonMember(): void
    {
        $harness = new WalletServiceHarness();

        try {
            $harness->placement()->update(
                WalletServiceHarness::auth(self::id(self::STRANGER)),
                WalletEntities::id(0xDEAD),
                new CardPlacementPayload(0, false),
            );
            self::fail('Expected DomainException.');
        } catch (DomainException $e) {
            self::assertSame(ErrorCode::NotFound, $e->errorCode());
        }
    }

    /**
     * 非成员是 `not_a_member`（403），**不是** `insufficient_role`。
     *
     * placement 对 owner 与 viewer 都开放，所以失败只可能是「你根本不在这张
     * 卡上」——而契约给 `not_a_member` 的语义正是「客户端应据此从本地删除该卡」。
     */
    public function testANonMemberGetsNotAMember(): void
    {
        $card = WalletEntities::card(ownerId: self::id(self::OWNER));
        $harness = new WalletServiceHarness($card);

        try {
            $harness->placement()->update(
                WalletServiceHarness::auth(self::id(self::STRANGER)),
                $card->id(),
                new CardPlacementPayload(0, false),
            );
            self::fail('Expected DomainException.');
        } catch (DomainException $e) {
            self::assertSame(ErrorCode::NotAMember, $e->errorCode());
            self::assertSame(403, $e->errorCode()->httpStatus());
        }
    }

    private static function id(int $n): Uuid
    {
        return WalletEntities::id($n);
    }
}
