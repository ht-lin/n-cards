<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Wallet\Application\Card;

use App\Module\Wallet\Application\Card\CardUpdatePayload;
use App\Module\Wallet\Application\Card\UpdateCardService;
use App\Shared\Domain\Error\DomainException;
use App\Shared\Domain\Error\ErrorCode;
use App\Tests\Double\Wallet\WalletEntities;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(UpdateCardService::class)]
final class UpdateCardServiceTest extends TestCase
{
    public function testItAppliesOnlyTheFieldsThatWerePresent(): void
    {
        $card = WalletEntities::card(
            id: WalletEntities::id(1),
            ownerId: WalletEntities::id(0xA11A),
            title: 'REWE Payback',
            merchantLabel: 'REWE',
            note: 'Rückseite abgenutzt',
        );
        $harness = new WalletServiceHarness($card);

        $view = $harness->updater()->update(
            WalletServiceHarness::auth(),
            WalletEntities::id(1),
            CardUpdatePayload::fromArray(['title' => 'REWE Payback (Zweitkarte)']),
            1,
        );

        self::assertSame('REWE Payback (Zweitkarte)', $view->title);
        // 没提到的字段一个都不能动 —— 这正是 *Present 标志存在的理由。
        self::assertSame('REWE', $view->merchantLabel);
        self::assertSame('Rückseite abgenutzt', $view->note);
    }

    /**
     * 七个字段一次全改 —— 覆盖 `apply()` 里每一条分支。
     *
     * 分开测的话，某个分支写成 `if (null !== $payload->title)` 却调了
     * `changeColor()` 这种复制粘贴错误，只有在同一次请求里同时改两个字段时
     * 才会暴露出来。
     */
    public function testItAppliesEveryFieldInOneRequest(): void
    {
        $card = WalletEntities::card(id: WalletEntities::id(1), ownerId: WalletEntities::id(0xA11A));
        $harness = new WalletServiceHarness($card);

        $view = $harness->updater()->update(
            WalletServiceHarness::auth(),
            WalletEntities::id(1),
            CardUpdatePayload::fromArray([
                'title' => 'DM',
                'merchant_label' => 'dm-drogerie markt',
                'color' => 'green_500',
                'barcode_format' => 'QR_CODE',
                'barcode_value' => 'https://example.de/x',
                'note' => 'neue Notiz',
                'expires_on' => '2028-12-31',
            ]),
            1,
        );

        self::assertSame('DM', $view->title);
        self::assertSame('dm-drogerie markt', $view->merchantLabel);
        self::assertSame('green_500', $view->color);
        self::assertSame('QR_CODE', $view->barcodeFormat);
        self::assertSame('https://example.de/x', $view->barcodeValue);
        self::assertSame('neue Notiz', $view->note);
        self::assertSame('2028-12-31', $view->expiresOn?->format('Y-m-d'));
    }

    public function testAnExplicitNullClearsTheField(): void
    {
        $card = WalletEntities::card(id: WalletEntities::id(1), ownerId: WalletEntities::id(0xA11A), note: 'weg damit');
        $harness = new WalletServiceHarness($card);

        $view = $harness->updater()->update(
            WalletServiceHarness::auth(),
            WalletEntities::id(1),
            CardUpdatePayload::fromArray(['note' => null]),
            1,
        );

        self::assertNull($view->note);
    }

    public function testChangingTheBarcodeAlsoRewritesTheFingerprint(): void
    {
        $card = WalletEntities::card(id: WalletEntities::id(1), ownerId: WalletEntities::id(0xA11A), barcodeValue: '4012345678901');
        $before = $card->barcodeValueFingerprint();
        $harness = new WalletServiceHarness($card);

        $view = $harness->updater()->update(
            WalletServiceHarness::auth(),
            WalletEntities::id(1),
            CardUpdatePayload::fromArray(['barcode_value' => '9998887776665']),
            1,
        );

        self::assertSame('9998887776665', $view->barcodeValue);
        // 指纹不可逆 —— 忘了重算的话事后没有任何办法从库里发现它对不上。
        self::assertFalse($before?->equals($card->barcodeValueFingerprint() ?? $before));
    }

    /**
     * ⚠️ 任务卡逐字：非 owner **一律** 403，**即使请求体合法、revision 正确**。
     *
     * 这条用例故意把 revision 传对、请求体写对 —— 只有把归属判断排在
     * 乐观锁**之前**才会绿。排反了的话，一个非成员能拿不同的 If-Match
     * 反复试，从 409 的 `current` 里读出这张卡的 revision 与 updated_at。
     */
    public function testANonOwnerIsRejectedEvenWithAValidBodyAndTheCorrectRevision(): void
    {
        $card = WalletEntities::card(id: WalletEntities::id(1), ownerId: WalletEntities::id(0xB0B));
        $harness = new WalletServiceHarness($card);

        try {
            $harness->updater()->update(
                WalletServiceHarness::auth(WalletEntities::id(0xA11A)),
                WalletEntities::id(1),
                CardUpdatePayload::fromArray(['title' => 'meins jetzt']),
                1,
            );
            self::fail('期待 insufficient_role。');
        } catch (DomainException $e) {
            self::assertSame(ErrorCode::InsufficientRole, $e->errorCode());
            // 泄露检查：403 的 body 里不能带上服务端状态。
            self::assertSame([], $e->current());
        }
    }

    /**
     * 非 owner 的 403 也必须早于**长度**检查 —— 否则会把「你的标题太长了」
     * 告诉一个根本无权写这张卡的人。
     */
    public function testANonOwnerIsRejectedBeforeTheLengthLimits(): void
    {
        $card = WalletEntities::card(id: WalletEntities::id(1), ownerId: WalletEntities::id(0xB0B));
        $harness = new WalletServiceHarness($card);

        try {
            $harness->updater()->update(
                WalletServiceHarness::auth(WalletEntities::id(0xA11A)),
                WalletEntities::id(1),
                CardUpdatePayload::fromArray(['title' => str_repeat('a', 101)]),
                1,
            );
            self::fail('期待 insufficient_role。');
        } catch (DomainException $e) {
            self::assertSame(ErrorCode::InsufficientRole, $e->errorCode());
        }
    }

    public function testAStaleRevisionIsAConflictCarryingTheCurrentState(): void
    {
        $card = WalletEntities::card(id: WalletEntities::id(1), ownerId: WalletEntities::id(0xA11A));
        $harness = new WalletServiceHarness($card);

        try {
            $harness->updater()->update(
                WalletServiceHarness::auth(),
                WalletEntities::id(1),
                CardUpdatePayload::fromArray(['title' => 'DM']),
                // 客户端手里是 7，服务端是 1。
                7,
            );
            self::fail('期待 revision_conflict。');
        } catch (DomainException $e) {
            self::assertSame(ErrorCode::RevisionConflict, $e->errorCode());
            // §5.4.3 的三方合并要靠 `current` 里的这两项。
            self::assertSame(1, $e->current()['revision']);
            self::assertArrayHasKey('updated_at', $e->current());
        }
    }

    public function testAMissingCardIsNotFound(): void
    {
        $harness = new WalletServiceHarness();

        $this->expectException(DomainException::class);

        $harness->updater()->update(
            WalletServiceHarness::auth(),
            WalletEntities::id(999),
            CardUpdatePayload::fromArray(['title' => 'DM']),
            1,
        );
    }

    /**
     * 软删的卡对 `PATCH` 是 404 —— 仓储的 `find()` 只返回未删除的行。
     */
    public function testASoftDeletedCardCannotBePatched(): void
    {
        $card = WalletEntities::card(id: WalletEntities::id(1), ownerId: WalletEntities::id(0xA11A));
        $card->softDelete(WalletEntities::now());
        $harness = new WalletServiceHarness($card);

        try {
            $harness->updater()->update(
                WalletServiceHarness::auth(),
                WalletEntities::id(1),
                CardUpdatePayload::fromArray(['title' => 'DM']),
                1,
            );
            self::fail('期待 not_found。');
        } catch (DomainException $e) {
            self::assertSame(ErrorCode::NotFound, $e->errorCode());
        }
    }

    /**
     * §7.5 的三条长度限额在 `PATCH` 上与 `POST` 上是**同一组数字**。
     *
     * ⚠️ 分开列而不是只测 note：`UpdateCardService::enforceLimits()` 的三个分支
     * 各带一个 `null !== $payload->…` 的 guard，抄错一个 `SystemLimit` case
     * （比如给 title 传了 `NoteChars`）不会有任何症状 —— 100 字符的标题照样通过，
     * 只是上限悄悄变成了 2000。逐条钉住上限值本身。
     *
     * @param string $field `CardUpdatePayload::fromArray()` 的键
     */
    #[DataProvider('lengthLimits')]
    public function testTheLengthLimitsApplyOnUpdateAtTheirRealBoundaries(
        string $field,
        string $atTheLimit,
        string $overTheLimit,
    ): void {
        // 恰好在上限上 → 放行。
        $harness = new WalletServiceHarness(
            WalletEntities::card(id: WalletEntities::id(1), ownerId: WalletEntities::id(0xA11A)),
        );

        $harness->updater()->update(
            WalletServiceHarness::auth(),
            WalletEntities::id(1),
            CardUpdatePayload::fromArray([$field => $atTheLimit]),
            1,
        );

        // 多一个单位 → 422。
        $harness = new WalletServiceHarness(
            WalletEntities::card(id: WalletEntities::id(1), ownerId: WalletEntities::id(0xA11A)),
        );

        try {
            $harness->updater()->update(
                WalletServiceHarness::auth(),
                WalletEntities::id(1),
                CardUpdatePayload::fromArray([$field => $overTheLimit]),
                1,
            );
            self::fail(\sprintf('%s 超限时期待 limit_exceeded。', $field));
        } catch (DomainException $e) {
            self::assertSame(ErrorCode::LimitExceeded, $e->errorCode());
        }
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function lengthLimits(): iterable
    {
        yield 'title 100 字符' => ['title', str_repeat('a', 100), str_repeat('a', 101)];
        yield 'note 2000 字符' => ['note', str_repeat('b', 2000), str_repeat('b', 2001)];

        // ⚠️ payload 的单位是**字节**：512 个 `ä` 是 1024 字节（放行），
        // 513 个是 1026 字节（拒绝）。按字符判的话两个都会通过。
        yield 'barcode_value 1024 字节' => ['barcode_value', str_repeat('ä', 512), str_repeat('ä', 513)];
    }
}
