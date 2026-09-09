<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Wallet\Application\Card;

use App\Module\Wallet\Application\Card\DeleteCardService;
use App\Shared\Domain\Error\DomainException;
use App\Shared\Domain\Error\ErrorCode;
use App\Tests\Double\Wallet\WalletEntities;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DeleteCardService::class)]
final class DeleteCardServiceTest extends TestCase
{
    public function testItSoftDeletesTheCardAndKeepsTheRow(): void
    {
        $card = WalletEntities::card(id: WalletEntities::id(1), ownerId: WalletEntities::id(0xA11A), note: 'bleibt');
        $harness = new WalletServiceHarness($card);

        $harness->deleter()->delete(WalletServiceHarness::auth(), WalletEntities::id(1));

        // 行还在（T-201 的墓碑同步需要它，而且 id 仍然算被占用）……
        $stored = $harness->cards->findIncludingDeleted(WalletEntities::id(1));
        self::assertNotNull($stored);
        self::assertTrue($stored->isDeleted());
        self::assertNotNull($stored->noteEncrypted(), '90 天的硬删窗口内还要能恢复。');

        // ……但正常查询看不到它了。
        self::assertNull($harness->cards->find(WalletEntities::id(1)));
    }

    public function testOnlyTheOwnerMayDelete(): void
    {
        $card = WalletEntities::card(id: WalletEntities::id(1), ownerId: WalletEntities::id(0xB0B));
        $harness = new WalletServiceHarness($card);

        try {
            $harness->deleter()->delete(WalletServiceHarness::auth(WalletEntities::id(0xA11A)), WalletEntities::id(1));
            self::fail('期待 insufficient_role。');
        } catch (DomainException $e) {
            self::assertSame(ErrorCode::InsufficientRole, $e->errorCode());
        }

        self::assertFalse($harness->cards->findIncludingDeleted(WalletEntities::id(1))?->isDeleted());
    }

    public function testDeletingTwiceIsNotFoundTheSecondTime(): void
    {
        $card = WalletEntities::card(id: WalletEntities::id(1), ownerId: WalletEntities::id(0xA11A));
        $harness = new WalletServiceHarness($card);

        $harness->deleter()->delete(WalletServiceHarness::auth(), WalletEntities::id(1));

        try {
            $harness->deleter()->delete(WalletServiceHarness::auth(), WalletEntities::id(1));
            self::fail('期待 not_found。');
        } catch (DomainException $e) {
            // 契约没有规定这一条，而 404 更诚实：客户端手里那个 id
            // 已经不指向任何可操作的东西了。
            self::assertSame(ErrorCode::NotFound, $e->errorCode());
        }
    }

    public function testDeletingAMissingCardIsNotFound(): void
    {
        $harness = new WalletServiceHarness();

        try {
            $harness->deleter()->delete(WalletServiceHarness::auth(), WalletEntities::id(999));
            self::fail('期待 not_found。');
        } catch (DomainException $e) {
            self::assertSame(ErrorCode::NotFound, $e->errorCode());
        }
    }
}
