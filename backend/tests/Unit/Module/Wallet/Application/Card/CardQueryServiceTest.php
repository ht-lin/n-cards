<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Wallet\Application\Card;

use App\Module\Wallet\Application\Card\CardQueryService;
use App\Module\Wallet\Application\Card\CardViewAssembler;
use App\Shared\Domain\Error\DomainException;
use App\Shared\Domain\Error\ErrorCode;
use App\Tests\Double\Wallet\WalletEntities;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CardQueryService::class)]
#[CoversClass(CardViewAssembler::class)]
final class CardQueryServiceTest extends TestCase
{
    /**
     * ⚠️⚠️ §5.3 的硬要求：一次请求解一批，**禁止在循环里逐条调 Vault**。
     *
     * 这条用例是那条禁令在单元层唯一的强制点 —— 谁把 `CardViewAssembler`
     * 改回 foreach + `decrypt()`，它立刻红。
     * 口径同 `VaultBatchDecryptorTest` 里那条「N 条只发一次请求」。
     */
    public function testListingTwoHundredCardsIssuesExactlyOneDecryptCall(): void
    {
        $owner = WalletEntities::id(0xA11A);
        $cards = [];

        for ($i = 1; $i <= 200; ++$i) {
            $cards[] = WalletEntities::card(
                id: WalletEntities::id(1000 + $i),
                ownerId: $owner,
                note: 0 === $i % 2 ? 'Notiz '.$i : null,
            );
        }

        $harness = new WalletServiceHarness(...$cards);
        $service = new CardQueryService($harness->cards, $harness->assembler());

        $views = $service->page(WalletServiceHarness::auth($owner), null, 201);

        self::assertCount(200, $views);
        self::assertSame(1, $harness->decryptor->calls(), '200 张卡必须只解一次。');
        // 200 个码值 + 100 条备注（null 的那些不进批次，否则整批会失败）。
        self::assertSame([300], $harness->decryptor->batchSizes());
    }

    public function testAnEmptyPageNeverTouchesVault(): void
    {
        $harness = new WalletServiceHarness();
        $service = new CardQueryService($harness->cards, $harness->assembler());

        self::assertSame([], $service->page(WalletServiceHarness::auth(), null, 51));
        self::assertSame(0, $harness->decryptor->calls());
    }

    /**
     * ⚠️ 键关联必须逐张对上 —— 按下标对齐是这类批量 API 最经典的错位 bug
     * （一条失败、数组被压缩，于是 A 的卡号显示成了 B 的）。
     *
     * 用「一半有备注、一半没有」把两个前缀交错开：错位的话某张卡会拿到
     * 邻居的码值，而这里逐张比对明文。
     */
    public function testEachCardGetsItsOwnPlaintextBack(): void
    {
        $owner = WalletEntities::id(0xA11A);
        $cards = [];

        for ($i = 1; $i <= 6; ++$i) {
            $cards[] = WalletEntities::card(
                id: WalletEntities::id(1000 + $i),
                ownerId: $owner,
                barcodeValue: 'code-'.$i,
                note: 0 === $i % 2 ? 'note-'.$i : null,
            );
        }

        $harness = new WalletServiceHarness(...$cards);
        $service = new CardQueryService($harness->cards, $harness->assembler());

        $views = $service->page(WalletServiceHarness::auth($owner), null, 7);

        foreach ($views as $index => $view) {
            $n = $index + 1;
            self::assertSame('code-'.$n, $view->barcodeValue);
            self::assertSame(0 === $n % 2 ? 'note-'.$n : null, $view->note);
        }
    }

    public function testTheListOnlyContainsMyOwnLiveCards(): void
    {
        $me = WalletEntities::id(0xA11A);
        $mine = WalletEntities::card(id: WalletEntities::id(1), ownerId: $me);
        $deleted = WalletEntities::card(id: WalletEntities::id(2), ownerId: $me);
        $deleted->softDelete(WalletEntities::now());
        $theirs = WalletEntities::card(id: WalletEntities::id(3), ownerId: WalletEntities::id(0xB0B));

        $harness = new WalletServiceHarness($mine, $deleted, $theirs);
        $service = new CardQueryService($harness->cards, $harness->assembler());

        $views = $service->page(WalletServiceHarness::auth($me), null, 51);

        self::assertCount(1, $views);
        self::assertSame(WalletEntities::id(1)->toString(), $views[0]->id->toString());
    }

    public function testTheCursorWindowSkipsEverythingUpToAndIncludingIt(): void
    {
        $owner = WalletEntities::id(0xA11A);
        $cards = [];

        for ($i = 1; $i <= 5; ++$i) {
            $cards[] = WalletEntities::card(id: WalletEntities::id($i), ownerId: $owner);
        }

        $harness = new WalletServiceHarness(...$cards);
        $service = new CardQueryService($harness->cards, $harness->assembler());

        // 严格大于：游标指向上一页保留下来的最后一行，它已经发过了。
        $views = $service->page(WalletServiceHarness::auth($owner), WalletEntities::id(2), 51);

        self::assertSame(
            [WalletEntities::id(3)->toString(), WalletEntities::id(4)->toString(), WalletEntities::id(5)->toString()],
            array_map(static fn ($view): string => $view->id->toString(), $views),
        );
    }

    public function testGettingSomeoneElsesCardTellsTheClientToDropItsLocalCopy(): void
    {
        $theirs = WalletEntities::card(id: WalletEntities::id(1), ownerId: WalletEntities::id(0xB0B));
        $harness = new WalletServiceHarness($theirs);
        $service = new CardQueryService($harness->cards, $harness->assembler());

        try {
            $service->get(WalletServiceHarness::auth(WalletEntities::id(0xA11A)), WalletEntities::id(1));
            self::fail('期待 not_a_member。');
        } catch (DomainException $e) {
            // ⚠️ 契约让客户端据此**删掉本地副本**（共享已被撤销）——
            // 与 PATCH/DELETE 的 insufficient_role 是两种处置，所以是两个码。
            self::assertSame(ErrorCode::NotAMember, $e->errorCode());
        }
    }

    public function testASoftDeletedCardIsGone(): void
    {
        $card = WalletEntities::card(id: WalletEntities::id(1), ownerId: WalletEntities::id(0xA11A));
        $card->softDelete(WalletEntities::now());
        $harness = new WalletServiceHarness($card);
        $service = new CardQueryService($harness->cards, $harness->assembler());

        try {
            $service->get(WalletServiceHarness::auth(), WalletEntities::id(1));
            self::fail('期待 not_found。');
        } catch (DomainException $e) {
            self::assertSame(ErrorCode::NotFound, $e->errorCode());
        }
    }

    /**
     * 单卡读也走批量入口 —— `CardViewAssembler` 没有单条方法，
     * 免得留下一个会被抄进 foreach 的示范。
     */
    public function testASingleCardReadAlsoGoesThroughTheBatchPath(): void
    {
        $card = WalletEntities::card(id: WalletEntities::id(1), ownerId: WalletEntities::id(0xA11A), note: 'da');
        $harness = new WalletServiceHarness($card);
        $service = new CardQueryService($harness->cards, $harness->assembler());

        $view = $service->get(WalletServiceHarness::auth(), WalletEntities::id(1));

        self::assertSame('4012345678901', $view->barcodeValue);
        self::assertSame('da', $view->note);
        self::assertSame(1, $harness->decryptor->calls());
        self::assertSame([2], $harness->decryptor->batchSizes());
    }
}
