<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Wallet\Domain\Entity;

use App\Module\Wallet\Domain\Entity\Card;
use App\Module\Wallet\Domain\ValueObject\BarcodeFormat;
use App\Tests\Double\Wallet\WalletEntities;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Card::class)]
final class CardTest extends TestCase
{
    public function testANewCardStartsAtRevisionOneAndIsNotDeleted(): void
    {
        $card = WalletEntities::card();

        // revision 的初值必须与迁移里的 `DEFAULT 1` 一致 —— Doctrine 的
        // INSERT 不含这一列，所以内存里的值和库里的值必须本来就相等。
        self::assertSame(1, $card->revision());
        self::assertFalse($card->isDeleted());
        self::assertNull($card->deletedAt());
        // ⚠️ 这里**没有**对 encryption_scheme 的断言。一期那个枚举只有一个 case，
        // 所以任何形式的断言（比实例、比 ->value）对静态分析都是恒真的 ——
        // phpstan 的 `staticMethod.alreadyNarrowedType` 会直接把它报成错误，
        // 而它确实什么都证明不了。
        // 真正要钉住的两件事各有归属：
        //   - 库里的 `DEFAULT 'server_v1'` → WalletSchemaTest::columns()
        //   - 枚举 ↔ TEXT 的往返        → DoctrineCardRepositoryTest（对着真列比）
        self::assertEquals($card->createdAt(), $card->updatedAt());
    }

    /**
     * 每个访问器都读回构造时给的值。
     *
     * 看着像凑数，其实不是：这些 getter 是 `CardViewAssembler` 组响应体时
     * 唯一的取值路径，而 §5.2 的列表里有两对字段名很容易写串
     * （`color` / `barcode_format`、`created_at` / `updated_at`）。
     * 一个复制粘贴出来的 `return $this->color;` 放进 `barcodeFormat()`
     * 不会有任何别的测试发现。
     */
    public function testEveryAccessorReadsBackWhatWasStored(): void
    {
        $expiry = new \DateTimeImmutable('2028-12-31');
        $created = WalletEntities::now();
        $card = WalletEntities::card(
            id: WalletEntities::id(7),
            ownerId: WalletEntities::id(0xA11A),
            title: 'REWE Payback',
            merchantLabel: 'REWE',
            color: 'blue_600',
            barcodeFormat: BarcodeFormat::QrCode,
            barcodeValue: '4012345678901',
            note: 'Rückseite abgenutzt',
            expiresOn: $expiry,
            now: $created,
        );

        self::assertTrue($card->id()->equals(WalletEntities::id(7)));
        self::assertTrue($card->ownerId()->equals(WalletEntities::id(0xA11A)));
        self::assertSame('REWE Payback', $card->title());
        self::assertSame('REWE', $card->merchantLabel());
        self::assertSame('blue_600', $card->color());
        self::assertSame(BarcodeFormat::QrCode, $card->barcodeFormat());
        self::assertEquals($expiry, $card->expiresOn());
        self::assertEquals($created, $card->createdAt());
        self::assertNotNull($card->noteEncrypted());
        self::assertNotNull($card->barcodeValueFingerprint());
    }

    /**
     * 改颜色 / 条码格式 / 到期日的**生效**路径。
     *
     * ⚠️ 与 {@see testWritingTheSameValueChangesNothing()} 是一对：那条走
     * 早退分支，这条走赋值分支。只测一半的话，把 `if ($x === $this->x) return;`
     * 写反（变成「只有相同时才写」）不会被发现。
     */
    public function testChangingColourFormatAndExpiryTakesEffect(): void
    {
        $card = WalletEntities::card(color: 'blue_600', barcodeFormat: BarcodeFormat::Ean13, expiresOn: null);
        $later = WalletEntities::now('2026-09-09T08:00:00+00:00');

        $card->changeColor('green_500', $later);
        $card->changeBarcodeFormat(BarcodeFormat::QrCode, $later);
        $card->changeExpiry(new \DateTimeImmutable('2028-12-31'), $later);

        self::assertSame('green_500', $card->color());
        self::assertSame(BarcodeFormat::QrCode, $card->barcodeFormat());
        self::assertSame('2028-12-31', $card->expiresOn()?->format('Y-m-d'));
        self::assertEquals($later, $card->updatedAt());
    }

    /**
     * 到期日也可以被**清空**（契约里 `expires_on` 可空）。
     */
    public function testClearingTheExpiryIsAChange(): void
    {
        $card = WalletEntities::card(expiresOn: new \DateTimeImmutable('2028-12-31'));
        $later = WalletEntities::now('2026-09-09T08:00:00+00:00');

        $card->changeExpiry(null, $later);

        self::assertNull($card->expiresOn());
        self::assertEquals($later, $card->updatedAt());
    }

    public function testOwnershipIsDecidedByValueNotIdentity(): void
    {
        $owner = WalletEntities::id(0xA11A);
        $card = WalletEntities::card(ownerId: $owner);

        // 同一个 uuid 的**另一个对象** —— `===` 会说 false，而那正是
        // isOwnedBy() 存在的理由（比错的症状是静默放行）。
        self::assertTrue($card->isOwnedBy(WalletEntities::id(0xA11A)));
        self::assertFalse($card->isOwnedBy(WalletEntities::id(0xB0B)));
    }

    public function testMutatorsTouchUpdatedAt(): void
    {
        $card = WalletEntities::card(title: 'REWE', now: WalletEntities::now());
        $later = WalletEntities::now('2026-09-09T08:00:00+00:00');

        $card->rename('DM', $later);

        self::assertSame('DM', $card->title());
        self::assertEquals($later, $card->updatedAt());
    }

    /**
     * 同值写入是 no-op，连 `updated_at` 都不动。
     *
     * 这不是微优化：`updated_at` 会进 `/sync` 的增量判定（T-202），
     * 每个重复的 PATCH 都推高它的话，客户端会反复收到一张没变过的卡。
     */
    public function testWritingTheSameValueChangesNothing(): void
    {
        $created = WalletEntities::now();
        $card = WalletEntities::card(title: 'REWE', merchantLabel: 'REWE', color: 'blue_600', now: $created);
        $later = WalletEntities::now('2026-09-09T08:00:00+00:00');

        $card->rename('REWE', $later);
        $card->changeMerchantLabel('REWE', $later);
        $card->changeColor('blue_600', $later);
        $card->changeBarcodeFormat(BarcodeFormat::Ean13, $later);
        $card->changeNote(null, $later);
        $card->changeExpiry(null, $later);

        self::assertEquals($created, $card->updatedAt());
    }

    /**
     * ⚠️ 到期日的相等判断必须用 `==`（值相等），不是 `===`（同一个对象）。
     *
     * 写成 `===` 的症状很隐蔽：每个带 expires_on 的 PATCH 都会把 revision 加 1，
     * 于是客户端的三方合并（§5.4.3）会看到一串虚假的版本变化。
     */
    public function testResendingTheSameExpiryDoesNotTouchTheCard(): void
    {
        $created = WalletEntities::now();
        $card = WalletEntities::card(expiresOn: new \DateTimeImmutable('2028-12-31'), now: $created);
        $later = WalletEntities::now('2026-09-09T08:00:00+00:00');

        $card->changeExpiry(new \DateTimeImmutable('2028-12-31'), $later);

        self::assertEquals($created, $card->updatedAt());
    }

    public function testClearingANullableFieldIsDistinctFromLeavingIt(): void
    {
        $card = WalletEntities::card(merchantLabel: 'REWE', note: 'Rückseite abgenutzt');
        $later = WalletEntities::now('2026-09-09T08:00:00+00:00');

        $card->changeMerchantLabel(null, $later);
        $card->changeNote(null, $later);

        self::assertNull($card->merchantLabel());
        self::assertNull($card->noteEncrypted());
    }

    /**
     * 码值与指纹只能一起换 —— 签名本身就保证了这一点，这条用例钉住它。
     */
    public function testChangingTheBarcodeReplacesBothCiphertextAndFingerprint(): void
    {
        $card = WalletEntities::card(barcodeValue: '4012345678901');
        $later = WalletEntities::now('2026-09-09T08:00:00+00:00');

        $card->changeBarcodeValue(
            WalletEntities::ciphertext('9998887776665'),
            WalletEntities::digest('9998887776665'),
            $later,
        );

        self::assertSame(
            WalletEntities::ciphertext('9998887776665')->toString(),
            $card->barcodeValueEncrypted()->toString(),
        );
        self::assertTrue($card->barcodeValueFingerprint()?->equals(WalletEntities::digest('9998887776665')));
    }

    /**
     * ⚠️ 重新加密**同一个明文**必须被当成一次真的改动。
     *
     * Vault Transit 每次加密都带新的随机 nonce，所以密文必然不同 ——
     * 实体没有办法（也不该花一次解密去）判断明文变没变。
     */
    public function testReEncryptingTheSameBarcodeStillCountsAsAChange(): void
    {
        $created = WalletEntities::now();
        $card = WalletEntities::card(barcodeValue: '4012345678901', now: $created);
        $later = WalletEntities::now('2026-09-09T08:00:00+00:00');

        $card->changeBarcodeValue(
            WalletEntities::ciphertext('4012345678901'),
            WalletEntities::digest('4012345678901'),
            $later,
        );

        self::assertEquals($later, $card->updatedAt());
    }

    public function testSoftDeleteMarksTheCardWithoutClearingItsSecrets(): void
    {
        $card = WalletEntities::card(note: 'Rückseite abgenutzt');
        $at = WalletEntities::now('2026-09-09T08:00:00+00:00');

        $card->softDelete($at);

        self::assertTrue($card->isDeleted());
        self::assertEquals($at, $card->deletedAt());
        // 90 天的硬删窗口内这张卡还要能被恢复，且 T-201 的墓碑同步需要这一行。
        self::assertNotNull($card->noteEncrypted());
        self::assertNotNull($card->barcodeValueFingerprint());
    }

    public function testSoftDeleteIsIdempotentAndDoesNotResetTheClock(): void
    {
        $card = WalletEntities::card();
        $first = WalletEntities::now('2026-09-09T08:00:00+00:00');

        $card->softDelete($first);
        $card->softDelete(WalletEntities::now('2026-09-10T08:00:00+00:00'));

        // 重置的话，90 天硬删的倒计时会被每一次重复删除推后。
        self::assertEquals($first, $card->deletedAt());
    }
}
