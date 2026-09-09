<?php

declare(strict_types=1);

namespace App\Tests\Integration\Module\Wallet\Doctrine;

use App\Module\Sharing\Domain\Entity\CardMember;
use App\Module\Sharing\Domain\Repository\CardMemberRepositoryInterface;
use App\Module\Wallet\Domain\Repository\CardRepositoryInterface;
use App\Module\Wallet\Infrastructure\Doctrine\DoctrineCardRepository;
use App\Shared\Domain\Identity\Uuid;
use App\Tests\Integration\Support\RequiresSharingSchema;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * §17.5 Q11 在**真库**上：共享给我的卡不占我的「每用户 500 张」额度（T-111）。
 *
 * ============================================================================
 * 为什么这个文件与 `DoctrineCardRepositoryTest` 分开
 * ============================================================================
 * 那个文件 `use RequiresWalletSchema`，而 Q11 要一行真的 `card_members`。
 * 把它换成 {@see RequiresSharingSchema}（内部再 use 前者）会让整个 `cards`
 * 仓储套件在 `card_members` 未迁移时**一起 skip** —— 用一个与那十几条用例
 * 毫无关系的依赖换本文件这两条，不划算。
 *
 * ============================================================================
 * 为什么替身上的同名用例证明不了这件事
 * ============================================================================
 * `CreateCardServiceTest::testCardsOwnedByOthersDoNotCountTowardsMyQuota()`
 * 跑在 `InMemoryCardRepository` 上，而那个替身**根本不知道 `card_members` 存在** ——
 * 它的 `countOwnedBy()` 只能按 `ownerId` 过滤，于是那条用例是恒真的。
 *
 * 真正要证的是 {@see DoctrineCardRepository::countOwnedBy()} 的 DQL
 * **不 join `card_members`**：一个「顺手把共享卡也算上」的改动会让它变成
 * owner 可以通过共享消耗别人配额的滥用面（§17.5 Q11 的原话），
 * 而在替身层看不出任何区别。所以这里两张表都要有真行。
 *
 * ⚠️ 本文件是 Wallet 的集成用例却 import 了 `Sharing\Domain\Entity\CardMember` ——
 * 被测对象正是「两个模块的表凑在一起时计数还对不对」，测不了单边。
 * deptrac 不扫 `tests/`；`tests/Api/CardListPerformanceTest` 已有同样的 import。
 */
#[CoversClass(DoctrineCardRepository::class)]
final class CardQuotaScopeTest extends KernelTestCase
{
    use RequiresSharingSchema;

    private CardRepositoryInterface $cards;

    private CardMemberRepositoryInterface $members;

    private Uuid $owner;

    private Uuid $viewer;

    protected function setUp(): void
    {
        $this->bootSharingSchema();

        /** @var CardRepositoryInterface $cards */
        $cards = self::getContainer()->get(CardRepositoryInterface::class);
        $this->cards = $cards;

        /** @var CardMemberRepositoryInterface $members */
        $members = self::getContainer()->get(CardMemberRepositoryInterface::class);
        $this->members = $members;

        $this->owner = $this->seedOwner();
        $this->viewer = $this->seedOwner();
    }

    protected function tearDown(): void
    {
        $this->rollbackSharingSchema();

        parent::tearDown();
    }

    /**
     * Q11 的正题：viewer 手里有一行 `card_members`，额度却是 0。
     */
    public function testACardSharedToMeDoesNotCountTowardsMyQuota(): void
    {
        $cardId = $this->share($this->seedCard($this->owner));

        $this->entityManager->clear();

        self::assertSame(1, $this->cards->countOwnedBy($this->owner), 'owner 自己的卡当然要算。');
        self::assertSame(
            0,
            $this->cards->countOwnedBy($this->viewer),
            '共享给 viewer 的卡不计入他的额度（§17.5 Q11）—— '
            .'否则 owner 可以通过共享消耗他人配额。countOwnedBy() 只按 owner_id 数，'
            .'别让它 join card_members。',
        );

        // 那一行成员记录**确实在库里** —— 否则上面的 0 只是因为共享压根没发生。
        self::assertArrayHasKey(
            $cardId->toString(),
            $this->members->findActiveFor($this->viewer, [$cardId]),
        );
    }

    /**
     * 两条谓词同时成立：`owner_id = :user` **且** `deleted_at IS NULL`。
     *
     * 各自单独的证明在 `DoctrineCardRepositoryTest`
     *（`testTheListAndCountAreScopedToTheOwner` / `testSoftDeletedCardsAreInvisibleToEveryQueryButOne`）。
     * 这里测的是一张**既被共享又被软删**的卡对谁都不计数 —— 少写一条 `andWhere`
     * 的症状在只有一条谓词的用例里可能被另一条掩盖掉。
     */
    public function testASoftDeletedSharedCardCountsForNobody(): void
    {
        $this->share($this->seedCard($this->owner));

        $deleted = $this->share($this->seedCard($this->owner));
        $card = $this->cards->findIncludingDeleted($deleted);
        self::assertNotNull($card);
        $card->softDelete(new \DateTimeImmutable('2026-09-09T12:00:00+00:00'));
        $this->cards->save($card);

        $this->entityManager->clear();

        self::assertSame(1, $this->cards->countOwnedBy($this->owner), '墓碑不占 owner 自己的配额。');
        self::assertSame(0, $this->cards->countOwnedBy($this->viewer));
    }

    /**
     * 给 {@see $viewer} 一行 `card_members(role='viewer')`，并补上 owner 自己那一行。
     *
     * ⚠️ owner 行要在这里补：{@see RequiresSharingSchema::seedCard()} 刻意**不写**
     * 成员行（在它的本职用例里那是被测对象），而「每张卡都有一行 owner 成员记录」
     * 是 T-110 之后的库内不变量 —— 不补的话这里躺着的是一张真实世界里不存在的卡。
     */
    private function share(Uuid $cardId): Uuid
    {
        $joinedAt = new \DateTimeImmutable('2026-09-09T10:00:00+00:00');

        $this->members->save(CardMember::owner($cardId, $this->owner, $joinedAt));
        $this->members->save(CardMember::viewer($cardId, $this->viewer, $this->owner, $joinedAt));

        return $cardId;
    }
}
