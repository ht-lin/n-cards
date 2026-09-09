<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Wallet\Application\Card;

use App\Module\Wallet\Application\Card\CardCreatePayload;
use App\Module\Wallet\Application\Card\CardSecrets;
use App\Module\Wallet\Application\Card\CardViewAssembler;
use App\Module\Wallet\Application\Card\CreateCardService;
use App\Shared\Domain\Error\DomainException;
use App\Shared\Domain\Error\ErrorCode;
use App\Tests\Double\Wallet\WalletEntities;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * §5.4.3 的「天然幂等」三条出路，以及 §7.5 的写入路径限额。
 */
#[CoversClass(CreateCardService::class)]
#[CoversClass(CardSecrets::class)]
#[CoversClass(CardViewAssembler::class)]
final class CreateCardServiceTest extends TestCase
{
    public function testItCreatesACardAndReportsThatItDidSo(): void
    {
        $harness = new WalletServiceHarness();

        $result = $harness->creator()->create(WalletServiceHarness::auth(), self::payload());

        self::assertTrue($result->wasCreated, '真新建必须让控制器发 201 + Location。');
        self::assertSame('REWE Payback', $result->card->title);
        // 明文进、明文出：加密与解密都真的发生了。
        self::assertSame('4012345678901', $result->card->barcodeValue);
        self::assertSame(1, $result->card->revision);
        self::assertSame('owner', $result->card->myRole);
        self::assertTrue($result->card->canEdit);
    }

    public function testTheBarcodeAndNoteAreStoredEncrypted(): void
    {
        $harness = new WalletServiceHarness();

        $harness->creator()->create(WalletServiceHarness::auth(), self::payload(note: 'Rückseite abgenutzt'));

        $stored = $harness->cards->findIncludingDeleted(WalletEntities::id(1));

        self::assertNotNull($stored);
        // 明文绝不能出现在密文列里 —— Ciphertext 的类型闸门挡住的正是这个。
        self::assertStringNotContainsString('4012345678901', $stored->barcodeValueEncrypted()->toString());
        self::assertStringNotContainsString('abgenutzt', (string) $stored->noteEncrypted());
        // 指纹是 HMAC(明文)，不可逆，且必须与码值同时写入。
        self::assertNotNull($stored->barcodeValueFingerprint());
        self::assertSame(2, $harness->crypto->encryptCalls(), '码值一次、备注一次。');
    }

    public function testANullNoteIsNotEncryptedAtAll(): void
    {
        $harness = new WalletServiceHarness();

        $harness->creator()->create(WalletServiceHarness::auth(), self::payload());

        self::assertSame(1, $harness->crypto->encryptCalls(), '没有备注就不该白花一次 Vault 往返。');
        self::assertNull($harness->cards->findIncludingDeleted(WalletEntities::id(1))?->noteEncrypted());
    }

    /**
     * ⚠️ 幂等重放**不改任何字段**。
     *
     * 一个迟到的离线 outbox 条目（§5.4.3）带着几小时前的 body 打过来，
     * 应用它就会把用户后来改过的标题静默覆盖回去 —— 而建卡请求不带 revision，
     * 没有任何冲突检测会发现。
     */
    public function testReplayingTheSameIdReturnsTheExistingCardUntouched(): void
    {
        $existing = WalletEntities::card(
            id: WalletEntities::id(1),
            ownerId: WalletEntities::id(0xA11A),
            title: '用户后来改成的标题',
        );
        $harness = new WalletServiceHarness($existing);

        $result = $harness->creator()->create(
            WalletServiceHarness::auth(),
            self::payload(title: '离线队列里那个旧标题'),
        );

        self::assertFalse($result->wasCreated, '什么都没建 → 控制器发 200，不是 201。');
        self::assertSame('用户后来改成的标题', $result->card->title);
        self::assertSame('用户后来改成的标题', $harness->cards->findIncludingDeleted(WalletEntities::id(1))?->title());
        self::assertSame(0, $harness->crypto->encryptCalls(), '重放不该重新加密任何东西。');
    }

    /**
     * 软删的 id 仍然算被占用 —— 用 `find()` 的话这里会走进 INSERT 然后撞主键冲突（500）。
     */
    public function testReplayingTheIdOfASoftDeletedCardIsStillIdempotent(): void
    {
        $deleted = WalletEntities::card(id: WalletEntities::id(1), ownerId: WalletEntities::id(0xA11A));
        $deleted->softDelete(WalletEntities::now());
        $harness = new WalletServiceHarness($deleted);

        $result = $harness->creator()->create(WalletServiceHarness::auth(), self::payload());

        self::assertFalse($result->wasCreated);
        // **不复活它**：撤销删除需要一个明确的产品决定，不该由一次网络重试触发。
        self::assertTrue($harness->cards->findIncludingDeleted(WalletEntities::id(1))?->isDeleted());
    }

    public function testAnIdBelongingToSomeoneElseIsAConflict(): void
    {
        $someoneElses = WalletEntities::card(id: WalletEntities::id(1), ownerId: WalletEntities::id(0xB0B));
        $harness = new WalletServiceHarness($someoneElses);

        try {
            $harness->creator()->create(WalletServiceHarness::auth(), self::payload());
            self::fail('期待 id_conflict。');
        } catch (DomainException $e) {
            // 客户端据此**重新生成一个 id** 再重试 —— 与 already_exists
            // （「别重试，东西已经在了」）是两种处置，所以是两个码。
            self::assertSame(ErrorCode::IdConflict, $e->errorCode());
        }
    }

    /**
     * 别人的卡不会被本次请求碰到，也不会泄露任何内容。
     */
    public function testAConflictLeavesTheOtherUsersCardAlone(): void
    {
        $someoneElses = WalletEntities::card(id: WalletEntities::id(1), ownerId: WalletEntities::id(0xB0B), title: '别人的卡');
        $harness = new WalletServiceHarness($someoneElses);

        try {
            $harness->creator()->create(WalletServiceHarness::auth(), self::payload(title: '我的卡'));
        } catch (DomainException $e) {
            self::assertStringNotContainsString('别人的卡', $e->detail());
        }

        self::assertSame('别人的卡', $harness->cards->findIncludingDeleted(WalletEntities::id(1))?->title());
    }

    /**
     * §7.5 的三条长度限额 —— 边界值取真值（100 / 2000 / 1024）。
     */
    public function testItEnforcesTheLengthLimitsAtTheirRealBoundaries(): void
    {
        $harness = new WalletServiceHarness();

        // 恰好在上限上 → 放行。
        $ok = $harness->creator()->create(WalletServiceHarness::auth(), self::payload(
            title: str_repeat('a', 100),
            note: str_repeat('b', 2000),
            barcodeValue: str_repeat('7', 1024),
        ));

        self::assertTrue($ok->wasCreated);
    }

    public function testATitleOverTheLimitIsRejectedBeforeItReachesTheDatabaseCheck(): void
    {
        // 库层有 `CHECK (char_length(title) <= 100)` —— 不在应用层挡住
        // 就是一个 500，而不是契约写的 422 limit_exceeded。
        self::assertLimitExceeded(self::payload(title: str_repeat('a', 101)));
    }

    public function testANoteOverTheLimitIsRejected(): void
    {
        self::assertLimitExceeded(self::payload(note: str_repeat('b', 2001)));
    }

    /**
     * ⚠️ payload 的限额是 1024 **字节**，不是字符。
     *
     * 一个德语变音符号在 UTF-8 里是两个字节 —— 用 mb_strlen 判的话，
     * 513 个 `ä` 会被放行，然后在别的地方以一个费解的形式炸掉。
     * `SystemLimit::BarcodePayloadBytes` 的 unit 是 Bytes，`enforceLength()`
     * 据此选 `strlen`；这条用例钉住那个选择。
     */
    public function testTheBarcodePayloadLimitCountsBytesNotCharacters(): void
    {
        self::assertLimitExceeded(self::payload(barcodeValue: str_repeat('ä', 513)));
    }

    /**
     * §7.5：每用户 500 张卡。499 放行（这是第 500 张），500 拒绝。
     */
    public function testTheFiveHundredthCardIsAllowedAndTheFiveHundredAndFirstIsNot(): void
    {
        $owner = WalletEntities::id(0xA11A);
        $existing = [];

        for ($i = 1; $i <= 499; ++$i) {
            $existing[] = WalletEntities::card(id: WalletEntities::id(1000 + $i), ownerId: $owner);
        }

        $harness = new WalletServiceHarness(...$existing);

        $five_hundredth = $harness->creator()->create(WalletServiceHarness::auth($owner), self::payload());
        self::assertTrue($five_hundredth->wasCreated);

        try {
            $harness->creator()->create(WalletServiceHarness::auth($owner), self::payload(id: 2));
            self::fail('第 501 张应该是 limit_exceeded。');
        } catch (DomainException $e) {
            self::assertSame(ErrorCode::LimitExceeded, $e->errorCode());
            self::assertStringContainsString('cards_per_user', $e->detail());
        }
    }

    /**
     * ⚠️ 软删的卡不占配额，否则一个反复建删卡的用户会被自己的墓碑挤满。
     */
    public function testSoftDeletedCardsDoNotCountTowardsTheQuota(): void
    {
        $owner = WalletEntities::id(0xA11A);
        $existing = [];

        for ($i = 1; $i <= 500; ++$i) {
            $card = WalletEntities::card(id: WalletEntities::id(1000 + $i), ownerId: $owner);
            $card->softDelete(WalletEntities::now());
            $existing[] = $card;
        }

        $harness = new WalletServiceHarness(...$existing);

        self::assertTrue($harness->creator()->create(WalletServiceHarness::auth($owner), self::payload())->wasCreated);
    }

    /**
     * ⚠️ 共享给我的卡**不计入**我的额度（§17.5 Q11）——
     * 否则 owner 可以通过共享消耗别人的配额，是一种滥用面。
     * 计数只统计 `owner_id = :user`。
     */
    public function testCardsOwnedByOthersDoNotCountTowardsMyQuota(): void
    {
        $me = WalletEntities::id(0xA11A);
        $existing = [];

        for ($i = 1; $i <= 500; ++$i) {
            $existing[] = WalletEntities::card(id: WalletEntities::id(1000 + $i), ownerId: WalletEntities::id(0xB0B));
        }

        $harness = new WalletServiceHarness(...$existing);

        self::assertTrue($harness->creator()->create(WalletServiceHarness::auth($me), self::payload())->wasCreated);
    }

    /**
     * ⚠️ 配额刚好满的时候，重放一张**已经存在的自有卡**仍然是 200，不是 422。
     *
     * {@see CreateCardService::create()} 的幂等短路排在 `enforceLimits()` **之前**，
     * 这不是巧合：满配额的用户手里那个离线 outbox（§5.4.3）里躺着的条目，
     * 指向的正是已经建成的卡。先查限额的话它们会永久 422 —— 而客户端对 422 的
     * 处置是「额度已满，别重试」（见 openapi.yaml 的 UnprocessableEntity 描述），
     * 于是那些条目再也不会被清掉，队列永远排不空。
     *
     * 把 `enforceLimits()` 挪到函数开头不会让别的用例红，所以这条用例在这里钉住它。
     */
    public function testReplayingAnExistingCardAtAFullQuotaIsStillIdempotent(): void
    {
        $owner = WalletEntities::id(0xA11A);
        $existing = [];

        // 第 1 张就是待重放的那一张，另外 499 张把配额顶到 500。
        for ($i = 1; $i <= 500; ++$i) {
            $existing[] = WalletEntities::card(
                id: WalletEntities::id(1 === $i ? 1 : 1000 + $i),
                ownerId: $owner,
                title: 1 === $i ? '用户后来改成的标题' : 'Karte',
            );
        }

        $harness = new WalletServiceHarness(...$existing);

        // 满配额下建**新**卡确实是 422 —— 前提没写错。
        try {
            $harness->creator()->create(WalletServiceHarness::auth($owner), self::payload(id: 2));
            self::fail('前提不成立：配额没有满。');
        } catch (DomainException $e) {
            self::assertSame(ErrorCode::LimitExceeded, $e->errorCode());
        }

        // 而重放已有的那一张照样通过。
        $replay = $harness->creator()->create(WalletServiceHarness::auth($owner), self::payload());

        self::assertFalse($replay->wasCreated, '什么都没建 → 200，不是 201，更不是 422。');
        self::assertSame('用户后来改成的标题', $replay->card->title);
    }

    private static function assertLimitExceeded(CardCreatePayload $payload): void
    {
        try {
            (new WalletServiceHarness())->creator()->create(WalletServiceHarness::auth(), $payload);
            self::fail('期待 limit_exceeded。');
        } catch (DomainException $e) {
            self::assertSame(ErrorCode::LimitExceeded, $e->errorCode());
        }
    }

    private static function payload(
        int $id = 1,
        string $title = 'REWE Payback',
        ?string $note = null,
        string $barcodeValue = '4012345678901',
    ): CardCreatePayload {
        return CardCreatePayload::fromArray([
            'id' => WalletEntities::id($id)->toString(),
            'title' => $title,
            'merchant_label' => 'REWE',
            'color' => 'blue_600',
            'barcode_format' => 'EAN_13',
            'barcode_value' => $barcodeValue,
            'note' => $note,
        ]);
    }
}
